<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\db\Query;
use craft\helpers\UrlHelper;
use craft\web\Request;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\models\ProductRow;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;
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
		$dateRange = $this->resolveDateRange();

		/** @var string $productsOrVariants */
		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		/** @var string $sortBy */
		$sortBy = $request->getQueryParam('sortBy', 'revenue');
		/** @var string $productType */
		$productType = $request->getQueryParam('productType', 'all');

		$plugin = Plugin::getInstance();
		assert($plugin !== null);
		$productStats = $plugin->productStats;

		$summaryStats = $productStats->getSummaryStats($dateRange->fromDT, $dateRange->toDT);

		return $this->renderTemplate('best-sellers/_products', [
			'title' => Craft::t('best-sellers', 'Products'),
			'selectedSubnavItem' => 'products',
			'from' => $dateRange->from,
			'to' => $dateRange->to,
			'preset' => $dateRange->preset,
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

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		/** @var int|string $page */
		$page = $request->getQueryParam('page', 1);
		$page = max(1, (int) $page);

		/** @var string $productsOrVariants */
		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		/** @var string $sortBy */
		$sortBy = $request->getQueryParam('sortBy', 'revenue');
		/** @var string $productType */
		$productType = $request->getQueryParam('productType', 'all');
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
		assert($plugin !== null);
		$productStats = $plugin->productStats;

		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange->fromDT, $dateRange->toDT, $sortBy, 10000, $productType);
		} else {
			$allItems = $productStats->getTopProducts($dateRange->fromDT, $dateRange->toDT, $sortBy, 10000, $productType);
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

			if ($productsOrVariants === 'variants') {
				$displayTitle = $pageItem->productTitle . ': ' . ($pageItem->variantTitle ?? '');
			} else {
				$displayTitle = $pageItem->productTitle;
			}

			$ordersUrl = UrlHelper::cpUrl('best-sellers/products/orders', [
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
				'revenue' => $this->formatCurrency((float) $pageItem->revenue),
				'avgPrice' => $this->formatCurrency((float) $pageItem->avgPrice),
				'ordersUrl' => $ordersUrl,
			];
		}

		$totalUnitsSold = 0;
		$totalOrderCount = 0;
		$totalRevenue = 0.0;
		foreach ($allItems as $allItem) {
			$totalUnitsSold += (int) $allItem->unitsSold;
			$totalOrderCount += (int) $allItem->orderCount;
			$totalRevenue += (float) $allItem->revenue;
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
		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		/** @var string $productsOrVariants */
		$productsOrVariants = $request->getQueryParam('productsOrVariants', 'products');
		/** @var string $sortBy */
		$sortBy = $request->getQueryParam('sortBy', 'revenue');
		/** @var string $productType */
		$productType = $request->getQueryParam('productType', 'all');
		/** @var string $rawSearch */
		$rawSearch = $request->getQueryParam('search', '');
		$search = trim($rawSearch);

		$plugin = Plugin::getInstance();
		assert($plugin !== null);
		$productStats = $plugin->productStats;

		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange->fromDT, $dateRange->toDT, $sortBy, 10000, $productType);
		} else {
			$allItems = $productStats->getTopProducts($dateRange->fromDT, $dateRange->toDT, $sortBy, 10000, $productType);
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
		$totalRevenue = 0.0;

		foreach ($allItems as $allItem) {
			$displayTitle = $productsOrVariants === 'variants'
				? $allItem->productTitle . ': ' . ($allItem->variantTitle ?? '')
				: $allItem->productTitle;

			$unitsSold = (int) $allItem->unitsSold;
			$orderCount = (int) $allItem->orderCount;
			$revenue = (float) $allItem->revenue;

			$totalUnitsSold += $unitsSold;
			$totalOrderCount += $orderCount;
			$totalRevenue += $revenue;

			$csvRows[] = [
				'product' => $displayTitle,
				'sku' => $allItem->variantSku ?? '',
				'type' => $allItem->productType,
				'unitsSold' => $unitsSold,
				'orders' => $orderCount,
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
			'revenue' => $totalRevenue,
			'avgPrice' => '',
		];

		return $this->asCsv($csvRows, [
			Craft::t('best-sellers', 'Product'),
			Craft::t('best-sellers', 'SKU'),
			Craft::t('best-sellers', 'Type'),
			Craft::t('best-sellers', 'Units Sold'),
			Craft::t('best-sellers', 'Orders'),
			Craft::t('best-sellers', 'Revenue'),
			Craft::t('best-sellers', 'Avg Price'),
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
		$dateRange = $this->resolveDateRange();

		/** @var int|string $productId */
		$productId = $request->getQueryParam('productId', 0);
		$productId = (int) $productId;

		/** @var int|string $variantId */
		$variantId = $request->getQueryParam('variantId', 0);
		$variantId = (int) $variantId;

		if ($productId === 0 && $variantId === 0) {
			throw new BadRequestHttpException(Craft::t('best-sellers', 'productId or variantId is required.'));
		}

		/** @var array{productTitle: string, variantTitle: string}|null $titleRow */
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

		$itemTitle = $titleRow !== null
			? ($variantId !== 0 ? ($titleRow['productTitle'] . ': ' . $titleRow['variantTitle']) : $titleRow['productTitle'])
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
		$dateRange = $this->resolveDateRange();

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
				'variantSales' => VariantSale::tableName(),
			])
			->where(['>=', '[[variantSales.dateOrdered]]', $dateRange->fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $dateRange->toDT]);

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
}
