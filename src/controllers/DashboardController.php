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
use fostercommerce\bestsellers\behaviors\SaleQueryBehavior;
use fostercommerce\bestsellers\behaviors\SalesBehavior;
use fostercommerce\bestsellers\Plugin;
use yii\base\InvalidConfigException;
use yii\web\Response;

class DashboardController extends Controller
{
	final public const ITEMS_PER_PAGE = 20;

	protected array|bool|int $allowAnonymous = false;

	/**
	 * @param \yii\base\Action<static> $action
	 */
	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requirePermission(Plugin::PERMISSION_VIEW_REPORTS);

		return true;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function actionIndex(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		$defaultFromDT = new DateTime('-1 month');
		$defaultToDT = new DateTime('now');

		/** @var string $preset */
		$preset = $request->getQueryParam('preset', '');
		/** @var string $fromInput */
		$fromInput = $request->getQueryParam('from', $defaultFromDT->format('Y-m-d'));
		/** @var string $toInput */
		$toInput = $request->getQueryParam('to', $defaultToDT->format('Y-m-d'));

		$from = trim($fromInput);
		$to = trim($toInput);

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

		/** @var SaleQueryBehavior<array-key, Product|Variant> $bestSellersBehavior */
		$bestSellersBehavior = $query->getBehavior('bestSellers');
		$bestSellersBehavior->bestSellers($fromDT, $toDT);

		/** @var array<array-key, string> $selectedStatuses */
		$selectedStatuses = (array) $request->getQueryParam('orderStatuses', []);
		if (! empty($selectedStatuses)) {
			$query->andWhere([
				'orderStatus' => $selectedStatuses,
			]);
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

		$map = static function (Product|Variant $element) use ($fetchVariants): array {
			if ($fetchVariants) {
				/** @var (Variant & SalesBehavior) $element */
				/** @var ?Product $product */
				$product = $element->getOwner();
				$totalQtySold = (int) ($element->totalQtySold ?? 0);
				return [
					'url' => $product?->getCpEditUrl(),
					'title' => $product?->title . ': ' . $element->title,
					'sku' => $element->sku,
					'totalQtySold' => $totalQtySold,
					'type' => $product?->getType()->name,
				];
			}

			/** @var Product $element */
			$totalQtySold = (int) ($element->totalQtySold ?? 0);
			return [
				'url' => $element->getCpEditUrl(),
				'title' => $element->title,
				'sku' => $element->defaultSku,
				'totalQtySold' => $totalQtySold,
				'type' => $element->getType()->name,
			];
		};

		$query->andWhere([
			'not', [
				'totalQtySold' => null,
			],
		]);

		/** @var array<int, Product|Variant> $elements */
		$elements = $query
			->limit(self::ITEMS_PER_PAGE)
			->offset($offset)
			->all();

		$page = array_map($map, $elements);

		$pagination = Craft::createObject([
			'class' => Paginate::class,
			'first' => $offset + 1,
			'last' => min($offset + self::ITEMS_PER_PAGE, $total),
			'total' => $total,
			'currentPage' => $pageNum,
			'totalPages' => ceil($total / self::ITEMS_PER_PAGE),
		]);

		$titleText = $productsOrVariants === 'variants' ? 'Variants' : 'Products';

		return $this->renderTemplate('best-sellers/_dashboard', [
			'items' => $page,
			'title' => $titleText,
			'from' => $from,
			'to' => $to,
			'preset' => $preset,
			'productType' => $productType,
			'productsOrVariants' => $productsOrVariants,
			'pagination' => $pagination,
			'selectedStatuses' => $selectedStatuses,
		]);
	}
}
