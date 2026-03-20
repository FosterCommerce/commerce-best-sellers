<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\db\Query;
use craft\helpers\UrlHelper;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;
use yii\web\Response;

class ProductsController extends BaseReportController
{
	private const PER_PAGE = 100;

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productsOrVariantsParam = $request->getQueryParam('productsOrVariants', 'products');
		$productsOrVariants = is_string($productsOrVariantsParam) ? $productsOrVariantsParam : 'products';

		$sortByParam = $request->getQueryParam('sortBy', 'revenue');
		$sortBy = is_string($sortByParam) ? $sortByParam : 'revenue';

		$productTypeParam = $request->getQueryParam('productType', 'all');
		$productType = is_string($productTypeParam) ? $productTypeParam : 'all';

		$plugin = Plugin::getInstance();
		assert($plugin !== null);
		$productStats = $plugin->productStats;

		// Summary stats for the written summary
		$summaryStats = $productStats->getSummaryStats($dateRange['fromDT'], $dateRange['toDT']);

		return $this->renderTemplate('best-sellers/_products', [
			'title' => 'Products',
			'selectedSubnavItem' => 'products',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
			'productsOrVariants' => $productsOrVariants,
			'sortBy' => $sortBy,
			'productType' => $productType,
			'summaryStats' => $summaryStats,
		]);
	}

	/**
	 * AJAX endpoint for paginated products/variants data.
	 */
	public function actionProductsData(): Response
	{
		$this->requireAcceptsJson();

		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$pageParam = $request->getQueryParam('page', '1');
		$page = max(1, is_numeric($pageParam) ? (int) $pageParam : 1);

		$productsOrVariantsParam = $request->getQueryParam('productsOrVariants', 'products');
		$productsOrVariants = is_string($productsOrVariantsParam) ? $productsOrVariantsParam : 'products';

		$sortByParam = $request->getQueryParam('sortBy', 'revenue');
		$sortBy = is_string($sortByParam) ? $sortByParam : 'revenue';

		$productTypeParam = $request->getQueryParam('productType', 'all');
		$productType = is_string($productTypeParam) ? $productTypeParam : 'all';

		$searchParam = $request->getQueryParam('search', '');
		$search = trim(is_string($searchParam) ? $searchParam : '');

		$sortParam = $request->getQueryParam('sort', '');
		$sort = trim(is_string($sortParam) ? $sortParam : '');

		$sortDirParam = $request->getQueryParam('sortDir', 'desc');
		$sortDir = trim(is_string($sortDirParam) ? $sortDirParam : 'desc');

		$plugin = Plugin::getInstance();
		assert($plugin !== null);
		$productStats = $plugin->productStats;

		// Get all matching items
		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 10000, $productType);
		} else {
			$allItems = $productStats->getTopProducts($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 10000, $productType);
		}

		/** @var array<int, array<string, mixed>> $allItems */

		// Map sort keys to raw data column names
		$sortKeyMap = [
			'sku' => $productsOrVariants === 'variants' ? 'variantSku' : 'productTitle',
		];
		$effectiveSort = $sortKeyMap[$sort] ?? $sort;

		// Server-side column sort override
		if ($effectiveSort !== '' && ! empty($allItems) && isset($allItems[0][$effectiveSort])) {
			usort($allItems, function ($itemA, $itemB) use ($effectiveSort, $sortDir): int {
				$valueA = $itemA[$effectiveSort] ?? 0;
				$valueB = $itemB[$effectiveSort] ?? 0;
				if (is_numeric($valueA) && is_numeric($valueB)) {
					$comparison = (float) $valueA <=> (float) $valueB;
				} else {
					$strA = is_scalar($valueA) ? (string) $valueA : '';
					$strB = is_scalar($valueB) ? (string) $valueB : '';
					$comparison = strcasecmp($strA, $strB);
				}

				return $sortDir === 'asc' ? $comparison : -$comparison;
			});
		}

		// Server-side search filter
		if ($search !== '') {
			$searchLower = strtolower($search);
			$allItems = array_values(array_filter($allItems, function ($item) use ($searchLower): bool {
				$productTitle = is_string($item['productTitle'] ?? null) ? $item['productTitle'] : '';
				$variantTitle = is_string($item['variantTitle'] ?? null) ? $item['variantTitle'] : '';
				$variantSku = is_string($item['variantSku'] ?? null) ? $item['variantSku'] : '';
				$productType = is_string($item['productType'] ?? null) ? $item['productType'] : '';
				$searchable = strtolower($productTitle . ' ' . $variantTitle . ' ' . $variantSku . ' ' . $productType);
				return str_contains($searchable, $searchLower);
			}));
		}

		$totalItems = count($allItems);
		$totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
		$offset = ($page - 1) * self::PER_PAGE;
		$pageItems = array_slice($allItems, $offset, self::PER_PAGE);

		// Batch-load product elements for URLs
		$productIds = array_unique(array_column($pageItems, 'productId'));
		$productElements = [];
		if ($productIds !== []) {
			$products = Product::find()->id($productIds)->status(null)->all();
			foreach ($products as $product) {
				$productElements[$product->id] = $product;
			}
		}

		$rows = [];
		foreach ($pageItems as $item) {
			$product = $productElements[$item['productId']] ?? null;

			if ($productsOrVariants === 'variants') {
				$displayTitle = $item['productTitle'] . ': ' . $item['variantTitle'];
			} else {
				$displayTitle = $item['productTitle'];
			}

			$ordersUrl = UrlHelper::cpUrl('best-sellers/products/orders', [
				($productsOrVariants === 'variants' ? 'variantId' : 'productId') => $productsOrVariants === 'variants' ? $item['variantId'] : $item['productId'],
			]);

			$unitsSold = isset($item['unitsSold']) && is_numeric($item['unitsSold']) ? (int) $item['unitsSold'] : 0;
			$orderCount = isset($item['orderCount']) && is_numeric($item['orderCount']) ? (int) $item['orderCount'] : 0;
			$revenue = isset($item['revenue']) && is_numeric($item['revenue']) ? (float) $item['revenue'] : 0.0;
			$avgPrice = isset($item['avgPrice']) && is_numeric($item['avgPrice']) ? (float) $item['avgPrice'] : 0.0;

			$rows[] = [
				'displayTitle' => $displayTitle,
				'cpEditUrl' => $product ? $product->cpEditUrl : null,
				'frontEndUrl' => $product ? $product->url : null,
				'sku' => $productsOrVariants === 'variants' ? ($item['variantSku'] ?? '') : ($product ? $product->defaultSku : ''),
				'productType' => $item['productType'] ?? '',
				'unitsSold' => $unitsSold,
				'orderCount' => $orderCount,
				'revenue' => $this->formatCurrency($revenue),
				'avgPrice' => $this->formatCurrency($avgPrice),
				'ordersUrl' => $ordersUrl,
			];
		}

		// Page totals
		$totalUnitsSold = 0;
		$totalOrderCount = 0;
		$totalRevenue = 0.0;
		foreach ($pageItems as $pageItem) {
			$totalUnitsSold += isset($pageItem['unitsSold']) && is_numeric($pageItem['unitsSold']) ? (int) $pageItem['unitsSold'] : 0;
			$totalOrderCount += isset($pageItem['orderCount']) && is_numeric($pageItem['orderCount']) ? (int) $pageItem['orderCount'] : 0;
			$totalRevenue += isset($pageItem['revenue']) && is_numeric($pageItem['revenue']) ? (float) $pageItem['revenue'] : 0.0;
		}

		$totals = [
			'unitsSold' => number_format($totalUnitsSold),
			'orderCount' => number_format($totalOrderCount),
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
		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productsOrVariantsParam = $request->getQueryParam('productsOrVariants', 'products');
		$productsOrVariants = is_string($productsOrVariantsParam) ? $productsOrVariantsParam : 'products';

		$sortByParam = $request->getQueryParam('sortBy', 'revenue');
		$sortBy = is_string($sortByParam) ? $sortByParam : 'revenue';

		$productTypeParam = $request->getQueryParam('productType', 'all');
		$productType = is_string($productTypeParam) ? $productTypeParam : 'all';

		$searchParam = $request->getQueryParam('search', '');
		$search = trim(is_string($searchParam) ? $searchParam : '');

		$plugin = Plugin::getInstance();
		assert($plugin !== null);
		$productStats = $plugin->productStats;

		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 10000, $productType);
		} else {
			$allItems = $productStats->getTopProducts($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 10000, $productType);
		}

		/** @var array<int, array<string, mixed>> $allItems */

		if ($search !== '') {
			$searchLower = strtolower($search);
			$allItems = array_values(array_filter($allItems, function ($item) use ($searchLower): bool {
				$productTitle = is_string($item['productTitle'] ?? null) ? $item['productTitle'] : '';
				$variantTitle = is_string($item['variantTitle'] ?? null) ? $item['variantTitle'] : '';
				$variantSku = is_string($item['variantSku'] ?? null) ? $item['variantSku'] : '';
				$productTypeVal = is_string($item['productType'] ?? null) ? $item['productType'] : '';
				$searchable = strtolower($productTitle . ' ' . $variantTitle . ' ' . $variantSku . ' ' . $productTypeVal);
				return str_contains($searchable, $searchLower);
			}));
		}

		$csvRows = [];
		$totalUnitsSold = 0;
		$totalOrderCount = 0;
		$totalRevenue = 0.0;

		foreach ($allItems as $allItem) {
			$displayTitle = $productsOrVariants === 'variants'
				? $allItem['productTitle'] . ': ' . $allItem['variantTitle']
				: $allItem['productTitle'];

			$unitsSold = isset($allItem['unitsSold']) && is_numeric($allItem['unitsSold']) ? (int) $allItem['unitsSold'] : 0;
			$orderCount = isset($allItem['orderCount']) && is_numeric($allItem['orderCount']) ? (int) $allItem['orderCount'] : 0;
			$revenue = isset($allItem['revenue']) && is_numeric($allItem['revenue']) ? (float) $allItem['revenue'] : 0.0;
			$avgPrice = isset($allItem['avgPrice']) && is_numeric($allItem['avgPrice']) ? (float) $allItem['avgPrice'] : 0.0;

			$totalUnitsSold += $unitsSold;
			$totalOrderCount += $orderCount;
			$totalRevenue += $revenue;

			$csvRows[] = [
				'product' => $displayTitle,
				'sku' => $allItem['variantSku'] ?? '',
				'type' => $allItem['productType'] ?? '',
				'unitsSold' => $unitsSold,
				'orders' => $orderCount,
				'revenue' => $revenue,
				'avgPrice' => $avgPrice,
			];
		}

		$csvRows[] = [
			'product' => 'TOTAL',
			'sku' => '',
			'type' => '',
			'unitsSold' => $totalUnitsSold,
			'orders' => $totalOrderCount,
			'revenue' => $totalRevenue,
			'avgPrice' => '',
		];

		return $this->asCsv($csvRows, [
			'Product', 'SKU', 'Type', 'Units Sold', 'Orders', 'Revenue', 'Avg Price',
		], 'products');
	}

	/**
	 * Show orders containing a specific product or variant.
	 */
	public function actionOrders(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productIdParam = $request->getQueryParam('productId', 0);
		$productId = is_numeric($productIdParam) ? (int) $productIdParam : 0;

		$variantIdParam = $request->getQueryParam('variantId', 0);
		$variantId = is_numeric($variantIdParam) ? (int) $variantIdParam : 0;

		if ($productId === 0 && $variantId === 0) {
			throw new \yii\web\BadRequestHttpException('productId or variantId is required.');
		}

		// Get product/variant title for the heading
		/** @var array<string, mixed>|null $titleRow */
		$titleRow = (new Query())
			->select(['[[variantSales.productTitle]]', '[[variantSales.variantTitle]]'])
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->where($variantId !== 0 ? [
				'[[variantSales.variantId]]' => $variantId,
			] : [
				'[[variantSales.productId]]' => $productId,
			])
			->limit(1)
			->one();

		if ($titleRow !== null) {
			$productTitle = is_string($titleRow['productTitle']) ? $titleRow['productTitle'] : '';
			$variantTitle = is_string($titleRow['variantTitle']) ? $titleRow['variantTitle'] : '';
			$itemTitle = $variantId !== 0 ? ($productTitle . ': ' . $variantTitle) : $productTitle;
		} else {
			$itemTitle = 'Unknown';
		}

		return $this->renderTemplate('best-sellers/_product-orders', [
			'title' => $itemTitle,
			'selectedSubnavItem' => 'products',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
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

		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productIdParam = $request->getQueryParam('productId', 0);
		$productId = is_numeric($productIdParam) ? (int) $productIdParam : 0;

		$variantIdParam = $request->getQueryParam('variantId', 0);
		$variantId = is_numeric($variantIdParam) ? (int) $variantIdParam : 0;

		$pageParam = $request->getQueryParam('page', '1');
		$page = max(1, is_numeric($pageParam) ? (int) $pageParam : 1);

		$sortParam = $request->getQueryParam('sort', 'dateOrdered');
		$sort = trim(is_string($sortParam) ? $sortParam : 'dateOrdered');

		$sortDirParam = $request->getQueryParam('sortDir', 'desc');
		$sortDir = trim(is_string($sortDirParam) ? $sortDirParam : 'desc');

		$query = (new Query())
			->select(['[[variantSales.orderId]]', '[[variantSales.qty]]', '[[variantSales.lineItemTotal]]'])
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->where(['>=', '[[variantSales.dateOrdered]]', $dateRange['fromDT']])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $dateRange['toDT']]);

		if ($variantId !== 0) {
			$query->andWhere([
				'[[variantSales.variantId]]' => $variantId,
			]);
		} else {
			$query->andWhere([
				'[[variantSales.productId]]' => $productId,
			]);
		}

		/** @var array<int, array<string, mixed>> $salesRows */
		$salesRows = $query->all();

		$lineItemsByOrder = [];
		foreach ($salesRows as $saleRow) {
			$orderId = $saleRow['orderId'];
			if (! is_int($orderId) && ! is_string($orderId)) {
				continue;
			}

			$lineItemsByOrder[$orderId][] = $saleRow;
		}

		$orderIds = array_keys($lineItemsByOrder);
		$totalItems = count($orderIds);
		$totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
		$offset = ($page - 1) * self::PER_PAGE;

		// Determine sort column for the element query
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

			// Sort by non-element columns if needed
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
}
