<?php
namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as CommercePlugin;
use craft\web\Controller;
use craft\web\Request;
use craft\web\twig\variables\Paginate;
use DateTime;
use Illuminate\Support\Collection;
use yii\base\InvalidConfigException;
use yii\web\Response;

/**
 * DashboardController handles the display of best-selling products/variants.
 */
class DashboardController extends Controller
{
	/**
	 * Number of items to display per page.
	 *
	 * @var int
	 */
	final public const ITEMS_PER_PAGE = 20;

	/**
	 * @var array|bool|int
	 */
	protected array|bool|int $allowAnonymous = false;

	/**
	 * Renders the dashboard index.
	 *
	 * Retrieves query parameters, prepares date filters, and fetches best selling products or variants.
	 *
	 * @return Response
	 * @throws InvalidConfigException
	 */
	public function actionIndex(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		// Set default date range: from one month ago to now.
		$defaultFromDT = new DateTime('-1 month');
		$defaultToDT   = new DateTime('now');

		/** @var string $preset */
		$preset = $request->getQueryParam('preset', '');
		/** @var string $fromInput */
		$fromInput = $request->getQueryParam('from', $defaultFromDT->format('Y-m-d'));
		/** @var string $toInput */
		$toInput = $request->getQueryParam('to', $defaultToDT->format('Y-m-d'));

		$from = is_array($fromInput) ? trim(reset($fromInput)) : trim($fromInput);
		$to   = is_array($toInput) ? trim(reset($toInput)) : trim($toInput);

		// Convert to valid datetime strings.
		$fromDTObj = new DateTime($from);
		$fromDTObj->setTime(0, 0, 0);
		$fromDT = $fromDTObj->format('Y-m-d H:i:s');
		$toDTObj = new DateTime($to);
		$toDTObj->setTime(23, 59, 59);
		$toDT = $toDTObj->format('Y-m-d H:i:s');

		/** @var string $productsOrVariants */
		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		$fetchVariants = $productsOrVariants === 'variants';

		/** @var string $productType */
		$productType = $request->getQueryParam('productType', 'all');

		if ($fetchVariants) {
			$query = Variant::find();
			if ($productType !== 'all') {
				$productTypeId = CommercePlugin::getInstance()
					?->productTypes
					->getProductTypeByHandle($productType)
					?->id;
				$query->typeId($productTypeId);
			}
		} else {
			$query = Product::find();
			if ($productType !== 'all') {
				$query->type($productType);
			}
		}

		// Apply best sellers criteria.
		$query->bestSellers($fromDT, $toDT);

		// New: Apply order status filter if provided.
		/** @var array $selectedStatuses */
		$selectedStatuses = (array)$request->getQueryParam('orderStatuses', []);
		if (!empty($selectedStatuses)) {
			$query->andWhere(['orderStatus' => $selectedStatuses]);
		}

		$query->andWhere([
			'not', [
				'totalQtySold' => null,
			],
		])->orderBy([
			'totalQtySold' => SORT_DESC,
		]);

		$pageNum = $request->getPageNum();
		$offset = (self::ITEMS_PER_PAGE * ($pageNum - 1));
		/** @var int $total */
		$total = $query->count();

		/**
		 * Maps an element (Product or Variant) to an array of relevant info.
		 *
		 * @param Product|Variant $element
		 * @return array{url: ?string, title: string, sku: string, totalQtySold: int, type: string}
		 */
		$map = static function (Product|Variant $element) use ($fetchVariants): array {
			if ($fetchVariants) {
				/** @var Variant $element */
				/** @var ?Product $product */
				$product = $element->getOwner();
				$totalQtySold = (int)($product->totalQtySold ?? 0);
				return [
					'url'         => $product?->getCpEditUrl(),
					'title'       => $product?->title . ': ' . $element->title,
					'sku'         => $element->sku,
					'totalQtySold'=> $totalQtySold,
					'type'        => $product?->getType()->name,
				];
			}

			/** @var Product $element */
			$totalQtySold = (int)($element->totalQtySold ?? 0);
			return [
				'url'         => $element->getCpEditUrl(),
				'title'       => $element->title,
				'sku'         => $element->defaultSku,
				'totalQtySold'=> $totalQtySold,
				'type'        => $element->getType()->name,
			];
		};

		/** @var Collection<int, array> $page */
		$page = collect(
			$query
				->limit(self::ITEMS_PER_PAGE)
				->offset($offset)
				->all()
		)->map($map);

		$pagination = Craft::createObject([
			'class'       => Paginate::class,
			'first'       => $offset + 1,
			'last'        => min($offset + self::ITEMS_PER_PAGE, $total),
			'total'       => $total,
			'currentPage' => $pageNum,
			'totalPages'  => ceil($total / self::ITEMS_PER_PAGE),
		]);

		// Prepare title text with translation in mind.
		$titleText = $productsOrVariants === 'variants' ? 'Variants' : 'Products';

		return $this->renderTemplate('best-sellers/_dashboard', [
			'items'               => $page,
			'title'               => $titleText,
			'from'                => $from,
			'to'                  => $to,
			'preset'              => $preset,
			'productType'         => $productType,
			'productsOrVariants'  => $productsOrVariants,
			'pagination'          => $pagination,
			'selectedStatuses'    => $selectedStatuses,
		]);
	}
}
