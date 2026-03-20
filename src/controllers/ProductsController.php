<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\db\Query;
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

		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		$sortBy = $request->getQueryParam('sortBy', 'revenue');
		$productType = $request->getQueryParam('productType', 'all');

		$productStats = Plugin::getInstance()->productStats;

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

		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$page = max(1, (int) $request->getQueryParam('page', 1));
		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		$sortBy = $request->getQueryParam('sortBy', 'revenue');
		$productType = $request->getQueryParam('productType', 'all');
		$search = trim((string) $request->getQueryParam('search', ''));
		$sort = trim((string) $request->getQueryParam('sort', ''));
		$sortDir = trim((string) $request->getQueryParam('sortDir', 'desc'));

		$productStats = Plugin::getInstance()->productStats;

		// Get all matching items
		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 10000, $productType);
		} else {
			$allItems = $productStats->getTopProducts($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 10000, $productType);
		}

		// Map sort keys to raw data column names
		$sortKeyMap = [
			'sku' => $productsOrVariants === 'variants' ? 'variantSku' : 'productTitle',
		];
		$effectiveSort = $sortKeyMap[$sort] ?? $sort;

		// Server-side column sort override
		if ($effectiveSort !== '' && ! empty($allItems) && isset($allItems[0][$effectiveSort])) {
			usort($allItems, function ($itemA, $itemB) use ($effectiveSort, $sortDir) {
				$valueA = $itemA[$effectiveSort] ?? 0;
				$valueB = $itemB[$effectiveSort] ?? 0;
				if (is_numeric($valueA) && is_numeric($valueB)) {
					$comparison = (float) $valueA <=> (float) $valueB;
				} else {
					$comparison = strcasecmp((string) $valueA, (string) $valueB);
				}
				return $sortDir === 'asc' ? $comparison : -$comparison;
			});
		}

		// Server-side search filter
		if ($search !== '') {
			$searchLower = strtolower($search);
			$allItems = array_values(array_filter($allItems, function ($item) use ($searchLower, $productsOrVariants) {
				$searchable = strtolower($item['productTitle'] . ' ' . ($item['variantTitle'] ?? '') . ' ' . ($item['variantSku'] ?? '') . ' ' . ($item['productType'] ?? ''));
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
		if (! empty($productIds)) {
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

			$ordersUrl = Craft::$app->getUrlManager()->createUrl('best-sellers/products/orders', [
				($productsOrVariants === 'variants' ? 'variantId' : 'productId') => $productsOrVariants === 'variants' ? $item['variantId'] : $item['productId'],
			]);

			$rows[] = [
				'displayTitle' => $displayTitle,
				'cpEditUrl' => $product ? $product->cpEditUrl : null,
				'frontEndUrl' => $product ? $product->url : null,
				'sku' => $productsOrVariants === 'variants' ? ($item['variantSku'] ?? '') : ($product ? $product->defaultSku : ''),
				'productType' => $item['productType'] ?? '',
				'unitsSold' => (int) $item['unitsSold'],
				'orderCount' => (int) $item['orderCount'],
				'revenue' => $this->formatCurrency((float) $item['revenue']),
				'avgPrice' => $this->formatCurrency((float) $item['avgPrice']),
				'ordersUrl' => $ordersUrl,
			];
		}

		// Page totals
		$totalUnitsSold = 0;
		$totalOrderCount = 0;
		$totalRevenue = 0;
		foreach ($pageItems as $item) {
			$totalUnitsSold += (int) $item['unitsSold'];
			$totalOrderCount += (int) $item['orderCount'];
			$totalRevenue += (float) $item['revenue'];
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
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		$sortBy = $request->getQueryParam('sortBy', 'revenue');
		$productType = $request->getQueryParam('productType', 'all');
		$search = trim((string) $request->getQueryParam('search', ''));

		$productStats = Plugin::getInstance()->productStats;

		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 10000, $productType);
		} else {
			$allItems = $productStats->getTopProducts($dateRange['fromDT'], $dateRange['toDT'], $sortBy, 10000, $productType);
		}

		if ($search !== '') {
			$searchLower = strtolower($search);
			$allItems = array_values(array_filter($allItems, function ($item) use ($searchLower) {
				$searchable = strtolower($item['productTitle'] . ' ' . ($item['variantTitle'] ?? '') . ' ' . ($item['variantSku'] ?? '') . ' ' . ($item['productType'] ?? ''));
				return str_contains($searchable, $searchLower);
			}));
		}

		$csvRows = [];
		$totalUnitsSold = 0;
		$totalOrderCount = 0;
		$totalRevenue = 0;

		foreach ($allItems as $item) {
			$displayTitle = $productsOrVariants === 'variants'
				? $item['productTitle'] . ': ' . $item['variantTitle']
				: $item['productTitle'];

			$unitsSold = (int) $item['unitsSold'];
			$orderCount = (int) $item['orderCount'];
			$revenue = (float) $item['revenue'];

			$totalUnitsSold += $unitsSold;
			$totalOrderCount += $orderCount;
			$totalRevenue += $revenue;

			$csvRows[] = [
				'product' => $displayTitle,
				'sku' => $item['variantSku'] ?? '',
				'type' => $item['productType'] ?? '',
				'unitsSold' => $unitsSold,
				'orders' => $orderCount,
				'revenue' => $revenue,
				'avgPrice' => (float) $item['avgPrice'],
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

		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productId = (int) $request->getQueryParam('productId', 0);
		$variantId = (int) $request->getQueryParam('variantId', 0);

		if (! $productId && ! $variantId) {
			throw new \yii\web\BadRequestHttpException('productId or variantId is required.');
		}

		// Get product/variant title for the heading
		$titleRow = (new Query())
			->select(['[[variantSales.productTitle]]', '[[variantSales.variantTitle]]'])
			->from(['variantSales' => VariantSale::tableName()])
			->where($variantId ? ['[[variantSales.variantId]]' => $variantId] : ['[[variantSales.productId]]' => $productId])
			->limit(1)
			->one();

		$itemTitle = $titleRow
			? ($variantId ? ($titleRow['productTitle'] . ': ' . $titleRow['variantTitle']) : $titleRow['productTitle'])
			: 'Unknown';

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

		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$productId = (int) $request->getQueryParam('productId', 0);
		$variantId = (int) $request->getQueryParam('variantId', 0);
		$page = max(1, (int) $request->getQueryParam('page', 1));
		$sort = trim((string) $request->getQueryParam('sort', 'dateOrdered'));
		$sortDir = trim((string) $request->getQueryParam('sortDir', 'desc'));

		$query = (new Query())
			->select(['[[variantSales.orderId]]', '[[variantSales.qty]]', '[[variantSales.lineItemTotal]]'])
			->from(['variantSales' => VariantSale::tableName()])
			->where(['>=', '[[variantSales.dateOrdered]]', $dateRange['fromDT']])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $dateRange['toDT']]);

		if ($variantId) {
			$query->andWhere(['[[variantSales.variantId]]' => $variantId]);
		} else {
			$query->andWhere(['[[variantSales.productId]]' => $productId]);
		}

		$salesRows = $query->all();

		$lineItemsByOrder = [];
		foreach ($salesRows as $row) {
			$lineItemsByOrder[$row['orderId']][] = $row;
		}

		$orderIds = array_keys($lineItemsByOrder);
		$totalItems = count($orderIds);
		$totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
		$offset = ($page - 1) * self::PER_PAGE;

		// Determine sort column for the element query
		$elementSortColumns = ['reference', 'dateOrdered', 'email', 'totalPrice'];
		$sortMapping = ['orderTotal' => 'totalPrice'];
		$elementSort = $sortMapping[$sort] ?? $sort;
		$direction = strtolower($sortDir) === 'asc' ? SORT_ASC : SORT_DESC;

		$orders = [];
		if (! empty($orderIds)) {
			$orderQuery = Order::find()
				->id($orderIds)
				->isCompleted(true);

			if (in_array($elementSort, $elementSortColumns, true)) {
				$orderQuery->orderBy([$elementSort => $direction]);
			} else {
				$orderQuery->orderBy(['dateOrdered' => SORT_DESC]);
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
				usort($allOrderRows, function ($rowA, $rowB) use ($sortKey, $sortDir) {
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
