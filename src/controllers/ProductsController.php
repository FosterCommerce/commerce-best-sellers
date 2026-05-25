<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\db\Query;
use craft\helpers\UrlHelper;
use craft\web\Request;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\VariantTitleHelper;
use fostercommerce\bestsellers\models\ProductRow;
use fostercommerce\bestsellers\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\Response;

class ProductsController extends BaseReportController
{
	private const PER_PAGE = 100;

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveScope();

		/** @var string $productsOrVariants */
		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		/** @var string $sortBy */
		$sortBy = $request->getQueryParam('sortBy', 'revenue');

		return $this->renderTemplate('best-sellers/_products', [
			'title' => Craft::t('commerce', 'Products'),
			'selectedSubnavItem' => 'products',
			'from' => $dateRange->from,
			'to' => $dateRange->to,
			'preset' => $dateRange->preset,
			'scope' => $dateRange,
			'productsOrVariants' => $productsOrVariants,
			'sortBy' => $sortBy,
			'selectedProductTypes' => $this->resolveProductTypeHandles(),
		]);
	}

	/**
	 * AJAX endpoint for paginated products/variants data.
	 */
	public function actionProductsData(): Response
	{
		$this->requireAcceptsJson();

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveScope();

		/** @var int|string $page */
		$page = $request->getQueryParam('page', 1);
		$page = max(1, (int) $page);

		/** @var string $productsOrVariants */
		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		/** @var string $sortBy */
		$sortBy = $request->getQueryParam('sortBy', 'revenue');
		$productTypeHandles = $this->resolveProductTypeHandles();
		/** @var string $rawSearch */
		$rawSearch = $request->getQueryParam('search', '');
		$search = trim($rawSearch);
		/** @var string $rawSort */
		$rawSort = $request->getQueryParam('sort', '');
		$sort = trim($rawSort);
		/** @var string $rawSortDir */
		$rawSortDir = $request->getQueryParam('sortDir', 'desc');
		$sortDir = trim($rawSortDir);

		$plugin = Plugin::getInstance();
		$productStats = $plugin->productStats;

		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange, $sortBy, 10000, $productTypeHandles);
		} else {
			$allItems = $productStats->getTopProducts($dateRange, $sortBy, 10000, $productTypeHandles);
		}

		/** @var list<ProductRow> $allItems */

		$sortKeyMap = [
			'sku' => $productsOrVariants === 'variants' ? 'variantSku' : 'productTitle',
		];
		$effectiveSort = $sortKeyMap[$sort] ?? $sort;

		if ($effectiveSort !== '' && $allItems !== [] && isset($allItems[0]->toArray()[$effectiveSort])) {
			usort($allItems, function (ProductRow $itemA, ProductRow $itemB) use ($effectiveSort, $sortDir): int {
				$arrA = $itemA->toArray();
				$arrB = $itemB->toArray();
				/** @var string|int|float|null $valueA */
				$valueA = $arrA[$effectiveSort] ?? 0;
				/** @var string|int|float|null $valueB */
				$valueB = $arrB[$effectiveSort] ?? 0;
				if (is_numeric($valueA) && is_numeric($valueB)) {
					$comparison = (float) $valueA <=> (float) $valueB;
				} else {
					$comparison = strcasecmp((string) $valueA, (string) $valueB);
				}

				return $sortDir === 'asc' ? $comparison : -$comparison;
			});
		}

		if ($search !== '') {
			$searchLower = strtolower($search);
			$allItems = array_values(array_filter($allItems, function (ProductRow $item) use ($searchLower): bool {
				$searchable = strtolower($item->productTitle . ' ' . ($item->variantTitle ?? '') . ' ' . ($item->variantSku ?? '') . ' ' . ($item->productType));
				return str_contains($searchable, $searchLower);
			}));
		}

		$totalItems = count($allItems);
		$totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
		$offset = ($page - 1) * self::PER_PAGE;
		$pageItems = array_slice($allItems, $offset, self::PER_PAGE);

		$productIds = array_unique(array_map(fn (ProductRow $item): int => $item->productId, $pageItems));
		$productElements = [];
		if ($productIds !== []) {
			$products = Product::find()->id($productIds)->status(null)->all();
			foreach ($products as $product) {
				$productElements[$product->id] = $product;
			}
		}

		$rows = [];
		foreach ($pageItems as $pageItem) {
			$product = $productElements[$pageItem->productId] ?? null;

			$displayTitle = $productsOrVariants === 'variants'
				? VariantTitleHelper::buildDisplayTitle($pageItem->productTitle, $pageItem->variantTitle)
				: $pageItem->productTitle;

			$ordersUrl = UrlHelper::cpUrl('best-sellers/orders', [
				($productsOrVariants === 'variants' ? 'variantId' : 'productId') => $productsOrVariants === 'variants' ? ($pageItem->variantId ?? 0) : $pageItem->productId,
			]);

			$rows[] = [
				'displayTitle' => $displayTitle,
				'cpEditUrl' => $product?->cpEditUrl,
				'frontEndUrl' => $product?->url,
				'sku' => $productsOrVariants === 'variants' ? ($pageItem->variantSku ?? '') : ($product?->defaultSku ?? ''),
				'productType' => $pageItem->productType,
				'unitsSold' => (int) $pageItem->unitsSold,
				'orderCount' => (int) $pageItem->orderCount,
				'itemSubtotal' => $this->formatCurrency((float) $pageItem->itemSubtotal),
				'revenue' => $this->formatCurrency((float) $pageItem->revenue),
				'avgPrice' => $this->formatCurrency((float) $pageItem->avgPrice),
				'ordersUrl' => $ordersUrl,
				'fromBundle' => $pageItem->fromBundle,
				'hasUnpaidOrder' => $pageItem->hasUnpaidOrder,
			];
		}

		$totalUnitsSold = 0;
		$totalOrderCount = 0;
		$totalItemSubtotal = 0.0;
		$totalRevenue = 0.0;
		foreach ($allItems as $allItem) {
			$totalUnitsSold += (int) $allItem->unitsSold;
			$totalOrderCount += (int) $allItem->orderCount;
			$totalItemSubtotal += (float) $allItem->itemSubtotal;
			$totalRevenue += (float) $allItem->revenue;
		}

		$totals = [
			'unitsSold' => number_format($totalUnitsSold),
			'orderCount' => number_format($totalOrderCount),
			'itemSubtotal' => $this->formatCurrency($totalItemSubtotal),
			'revenue' => $this->formatCurrency($totalRevenue),
		];

		return $this->asJson([
			'items' => $rows,
			'currentPage' => $page,
			'totalPages' => $totalPages,
			'totalItems' => $totalItems,
			'perPage' => self::PER_PAGE,
			'totals' => $totals,
		]);
	}

	/**
	 * CSV export of filtered products/variants.
	 */
	public function actionExportCsv(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveScope();

		/** @var string $productsOrVariants */
		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		/** @var string $sortBy */
		$sortBy = $request->getQueryParam('sortBy', 'revenue');
		$productTypeHandles = $this->resolveProductTypeHandles();
		/** @var string $rawSearch */
		$rawSearch = $request->getQueryParam('search', '');
		$search = trim($rawSearch);

		$plugin = Plugin::getInstance();
		$productStats = $plugin->productStats;

		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange, $sortBy, 10000, $productTypeHandles);
		} else {
			$allItems = $productStats->getTopProducts($dateRange, $sortBy, 10000, $productTypeHandles);
		}

		/** @var list<ProductRow> $allItems */

		if ($search !== '') {
			$searchLower = strtolower($search);
			$allItems = array_values(array_filter($allItems, function (ProductRow $item) use ($searchLower): bool {
				$searchable = strtolower($item->productTitle . ' ' . ($item->variantTitle ?? '') . ' ' . ($item->variantSku ?? '') . ' ' . $item->productType);
				return str_contains($searchable, $searchLower);
			}));
		}

		$csvRows = [];
		$totalUnitsSold = 0;
		$totalOrderCount = 0;
		$totalItemSubtotal = 0.0;
		$totalRevenue = 0.0;

		foreach ($allItems as $allItem) {
			$displayTitle = $productsOrVariants === 'variants'
				? VariantTitleHelper::buildDisplayTitle($allItem->productTitle, $allItem->variantTitle)
				: $allItem->productTitle;

			$unitsSold = (int) $allItem->unitsSold;
			$orderCount = (int) $allItem->orderCount;
			$itemSubtotal = (float) $allItem->itemSubtotal;
			$revenue = (float) $allItem->revenue;

			$totalUnitsSold += $unitsSold;
			$totalOrderCount += $orderCount;
			$totalItemSubtotal += $itemSubtotal;
			$totalRevenue += $revenue;

			$csvRows[] = [
				'product' => $displayTitle,
				'sku' => $allItem->variantSku ?? '',
				'type' => $allItem->productType,
				'unitsSold' => $unitsSold,
				'orders' => $orderCount,
				'itemSubtotal' => $itemSubtotal,
				'revenue' => $revenue,
				'avgPrice' => (float) $allItem->avgPrice,
			];
		}

		$csvRows[] = [
			'product' => 'TOTAL',
			'sku' => '',
			'type' => '',
			'unitsSold' => $totalUnitsSold,
			'orders' => $totalOrderCount,
			'itemSubtotal' => $totalItemSubtotal,
			'revenue' => $totalRevenue,
			'avgPrice' => '',
		];

		return $this->asCsv($csvRows, [
			Craft::t('best-sellers', 'products.col.product'),
			Craft::t('commerce', 'SKU'),
			Craft::t('app', 'Type'),
			Craft::t('best-sellers', 'products.col.unitsSold'),
			Craft::t('commerce', 'Orders'),
			Craft::t('commerce', 'Item Subtotal'),
			Craft::t('best-sellers', 'products.col.itemSalesNet'),
			Craft::t('best-sellers', 'products.col.avgPrice'),
		], 'products');
	}

	/**
	 * Show orders containing a specific product or variant.
	 */
	public function actionOrders(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveScope();

		/** @var int|string $productId */
		$productId = $request->getQueryParam('productId', 0);
		$productId = (int) $productId;

		/** @var int|string $variantId */
		$variantId = $request->getQueryParam('variantId', 0);
		$variantId = (int) $variantId;

		if ($productId === 0 && $variantId === 0) {
			throw new BadRequestHttpException(Craft::t('best-sellers', 'productOrders.error.idRequired'));
		}

		/** @var array{productTitle: string, variantTitle: string}|null $titleRow */
		$titleRow = (new Query())
			->select(['[[variantSales.productTitle]]', '[[variantSales.variantTitle]]'])
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->where($variantId !== 0 ? [
				'[[variantSales.variantId]]' => $variantId,
			] : [
				'[[variantSales.productId]]' => $productId,
			])
			->limit(1)
			->one();

		$itemTitle = $titleRow !== null
			? ($variantId !== 0
				? VariantTitleHelper::buildDisplayTitle($titleRow['productTitle'], $titleRow['variantTitle'])
				: $titleRow['productTitle'])
			: 'Unknown';

		return $this->renderTemplate('best-sellers/_product-orders', [
			'title' => $itemTitle,
			'selectedSubnavItem' => 'products',
			'from' => $dateRange->from,
			'to' => $dateRange->to,
			'preset' => $dateRange->preset,
			'itemTitle' => $itemTitle,
			'productId' => $productId,
			'variantId' => $variantId,
		]);
	}

	/**
	 * AJAX endpoint for product orders data.
	 */
	public function actionProductOrdersData(): Response
	{
		$this->requireAcceptsJson();

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveScope();

		/** @var int|string $productId */
		$productId = $request->getQueryParam('productId', 0);
		$productId = (int) $productId;

		/** @var int|string $variantId */
		$variantId = $request->getQueryParam('variantId', 0);
		$variantId = (int) $variantId;

		/** @var int|string $page */
		$page = $request->getQueryParam('page', 1);
		$page = max(1, (int) $page);

		/** @var string $rawSort */
		$rawSort = $request->getQueryParam('sort', 'dateOrdered');
		$sort = trim($rawSort);
		/** @var string $rawSortDir */
		$rawSortDir = $request->getQueryParam('sortDir', 'desc');
		$sortDir = trim($rawSortDir);

		$query = (new Query())
			->select(['[[variantSales.orderId]]', '[[variantSales.qty]]', '[[variantSales.lineItemTotal]]'])
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->where($dateRange->dateRange->dateCondition('[[variantSales.dateOrdered]]'));

		if ($variantId !== 0) {
			$query->andWhere([
				'[[variantSales.variantId]]' => $variantId,
			]);
		} else {
			$query->andWhere([
				'[[variantSales.productId]]' => $productId,
			]);
		}

		/** @var array<int, array{orderId: int, qty: int|string, lineItemTotal: float|string}> $salesRows */
		$salesRows = $query->all();

		$lineItemsByOrder = [];
		foreach ($salesRows as $saleRow) {
			$lineItemsByOrder[$saleRow['orderId']][] = $saleRow;
		}

		$orderIds = array_keys($lineItemsByOrder);
		$totalItems = count($orderIds);
		$totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
		$offset = ($page - 1) * self::PER_PAGE;

		$elementSortColumns = ['reference', 'dateOrdered', 'email', 'totalPrice'];
		$sortMapping = [
			'orderTotal' => 'totalPrice',
		];
		$elementSort = $sortMapping[$sort] ?? $sort;
		$direction = strtolower($sortDir) === 'asc' ? SORT_ASC : SORT_DESC;

		$orders = [];
		if ($orderIds !== []) {
			$orderQuery = Order::find()
				->id($orderIds)
				->isCompleted(true);

			if (in_array($elementSort, $elementSortColumns, true)) {
				$orderQuery->orderBy([
					$elementSort => $direction,
				]);
			} else {
				$orderQuery->orderBy([
					'dateOrdered' => SORT_DESC,
				]);
			}

			$allOrderRows = [];
			foreach ($orderQuery->all() as $order) {
				$lineInfo = $lineItemsByOrder[$order->id] ?? [];
				$totalQty = array_sum(array_column($lineInfo, 'qty'));
				$totalRevenue = array_sum(array_column($lineInfo, 'lineItemTotal'));

				$allOrderRows[] = [
					'reference' => $order->reference,
					'cpEditUrl' => $order->cpEditUrl,
					'dateOrdered' => $order->dateOrdered ? $order->dateOrdered->format('Y-m-d') : '',
					'email' => $order->email ?? '',
					'qty' => (int) $totalQty,
					'lineRevenueRaw' => (float) $totalRevenue,
					'lineRevenue' => $this->formatCurrency((float) $totalRevenue),
					'orderTotalRaw' => (float) $order->totalPrice,
					'orderTotal' => $this->formatCurrency($order->totalPrice),
				];
			}

			if ($sort === 'qty' || $sort === 'lineRevenue') {
				$sortKey = $sort === 'lineRevenue' ? 'lineRevenueRaw' : 'qty';
				usort($allOrderRows, function (array $rowA, array $rowB) use ($sortKey, $sortDir): int {
					$comparison = $rowA[$sortKey] <=> $rowB[$sortKey];
					return $sortDir === 'asc' ? $comparison : -$comparison;
				});
			}

			$orders = array_slice($allOrderRows, $offset, self::PER_PAGE);
		}

		return $this->asJson([
			'items' => $orders,
			'currentPage' => $page,
			'totalPages' => $totalPages,
			'totalItems' => $totalItems,
			'perPage' => self::PER_PAGE,
		]);
	}

	/**
	 * Resolve the selected product type handles from the request. Accepts the
	 * `productType[]` multi-select array and tolerates a bare string. Empty
	 * selection (or the legacy `all` sentinel) means no product type filter.
	 *
	 * @return list<string>
	 */
	private function resolveProductTypeHandles(): array
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$rawProductType = $request->getQueryParam('productType', []);

		if (is_string($rawProductType)) {
			return $rawProductType !== '' && $rawProductType !== 'all' ? [$rawProductType] : [];
		}

		$handles = [];
		if (is_array($rawProductType)) {
			foreach ($rawProductType as $value) {
				if (is_string($value) && $value !== '' && $value !== 'all') {
					$handles[] = $value;
				}
			}
		}

		return $handles;
	}
}
