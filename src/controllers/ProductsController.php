<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\models\ProductType;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\UrlHelper;
use craft\web\Request;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\MoneyMath;
use fostercommerce\bestsellers\helpers\VariantTitleHelper;
use fostercommerce\bestsellers\models\FieldFilter;
use fostercommerce\bestsellers\models\ProductRow;
use fostercommerce\bestsellers\Plugin;
use Money\Money;
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
		$showProfitToggle = Plugin::getInstance()->variantFields->hasUnitCostField();

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
			'showProfitToggle' => $showProfitToggle,
			'reportMode' => $showProfitToggle && $request->getQueryParam('reportMode') === 'profit' ? 'profit' : 'sales',
			'fieldFilterGroups' => $this->getFieldFilterGroups(),
			'onlyProductTypeHandle' => $this->getOnlyProductType()?->handle,
			'activeProductTypeHandle' => $this->getActiveProductType()?->handle,
			'selectedFilterValues' => $this->resolveFilterValues(),
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
		$isProfitView = $this->isProfitView();

		$plugin = Plugin::getInstance();
		$productStats = $plugin->productStats;

		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange, $sortBy, 10000, $productTypeHandles, $this->resolveFieldFilters(), $isProfitView);
		} else {
			$allItems = $productStats->getTopProducts($dateRange, $sortBy, 10000, $productTypeHandles, $this->resolveFieldFilters(), $isProfitView);
		}

		/** @var list<ProductRow> $allItems */

		$sortKeyMap = [
			'sku' => $productsOrVariants === 'variants' ? 'variantSku' : 'productTitle',
		];
		$effectiveSort = $sortKeyMap[$sort] ?? $sort;

		// Use array_key_exists, since isset rejects a column that is null on the first row
		if ($effectiveSort !== '' && $allItems !== [] && array_key_exists($effectiveSort, $allItems[0]->toArray())) {
			usort($allItems, function (ProductRow $itemA, ProductRow $itemB) use ($effectiveSort, $sortDir): int {
				$arrA = $itemA->toArray();
				$arrB = $itemB->toArray();
				/** @var string|int|float|null $valueA */
				$valueA = $arrA[$effectiveSort];
				/** @var string|int|float|null $valueB */
				$valueB = $arrB[$effectiveSort];
				// Keep rows without a value last in either direction
				if ($valueA === null || $valueB === null) {
					return ($valueA === null) <=> ($valueB === null);
				}

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
				'cost' => $this->formatOptionalCurrency($pageItem->cost),
				'grossProfit' => $this->formatOptionalCurrency($pageItem->grossProfit),
				'grossMargin' => $this->formatOptionalPercent($pageItem->grossMargin),
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

		if ($isProfitView) {
			$costTotals = $this->getCostTotals($allItems);
			$totals['cost'] = $this->formatCurrency($costTotals['cost']);
			$totals['grossProfit'] = $this->formatCurrency($costTotals['grossProfit']);
			$totals['grossMargin'] = $this->formatOptionalPercent($costTotals['grossMargin']);
		}

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
		$isProfitView = $this->isProfitView();

		$plugin = Plugin::getInstance();
		$productStats = $plugin->productStats;

		if ($productsOrVariants === 'variants') {
			$allItems = $productStats->getTopVariants($dateRange, $sortBy, 10000, $productTypeHandles, $this->resolveFieldFilters(), $isProfitView);
		} else {
			$allItems = $productStats->getTopProducts($dateRange, $sortBy, 10000, $productTypeHandles, $this->resolveFieldFilters(), $isProfitView);
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

			$csvRow = [
				'product' => $displayTitle,
				'sku' => $allItem->variantSku ?? '',
				'type' => $allItem->productType,
				'unitsSold' => $unitsSold,
				'orders' => $orderCount,
				'itemSubtotal' => $itemSubtotal,
				'revenue' => $revenue,
				'avgPrice' => (float) $allItem->avgPrice,
			];
			if ($isProfitView) {
				$csvRow['cost'] = $allItem->cost;
				$csvRow['grossProfit'] = $allItem->grossProfit;
				$csvRow['grossMargin'] = $allItem->grossMargin === null ? '' : round($allItem->grossMargin * 100, 1);
			}

			$csvRows[] = $csvRow;
		}

		$totalsRow = [
			'product' => 'TOTAL',
			'sku' => '',
			'type' => '',
			'unitsSold' => $totalUnitsSold,
			'orders' => $totalOrderCount,
			'itemSubtotal' => $totalItemSubtotal,
			'revenue' => $totalRevenue,
			'avgPrice' => '',
		];
		if ($isProfitView) {
			$costTotals = $this->getCostTotals($allItems);
			$totalsRow['cost'] = $costTotals['cost'];
			$totalsRow['grossProfit'] = $costTotals['grossProfit'];
			$totalsRow['grossMargin'] = $costTotals['grossMargin'] === null ? '' : round($costTotals['grossMargin'] * 100, 1);
		}

		$csvRows[] = $totalsRow;

		$headers = [
			Craft::t('best-sellers', 'products.col.product'),
			Craft::t('commerce', 'SKU'),
			Craft::t('app', 'Type'),
			Craft::t('best-sellers', 'products.col.unitsSold'),
			Craft::t('commerce', 'Orders'),
			Craft::t('commerce', 'Item Subtotal'),
			Craft::t('best-sellers', 'products.col.itemSalesNet'),
			Craft::t('best-sellers', 'products.col.avgPrice'),
		];
		if ($isProfitView) {
			$headers[] = Craft::t('best-sellers', 'products.col.cost');
			$headers[] = Craft::t('best-sellers', 'products.col.grossProfit');
			$headers[] = Craft::t('best-sellers', 'products.col.grossMarginPercent');
		}

		return $this->asCsv($csvRows, $headers, 'products');
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

	/**
	 * Get the filters for every product type, grouped by product type handle.
	 *
	 * @return list<array{productTypeHandle: string, filters: list<array{instanceUid: string, label: string, options: array<int|string, string>}>}>
	 */
	private function getFieldFilterGroups(): array
	{
		$plugin = Plugin::getInstance();
		$fieldFilterGroups = [];
		foreach ($this->getCommerce()->getProductTypes()->getAllProductTypes() as $productType) {
			$filters = [];
			foreach ($plugin->variantFields->getFilterFields($productType) as $instanceUid => $field) {
				$filters[] = [
					'instanceUid' => $instanceUid,
					'label' => Craft::t('site', (string) $field->name),
					'options' => $plugin->productStats->getFilterFieldOptions($field, $productType),
				];
			}

			if ($filters !== []) {
				$fieldFilterGroups[] = [
					'productTypeHandle' => (string) $productType->handle,
					'filters' => $filters,
				];
			}
		}

		return $fieldFilterGroups;
	}

	/**
	 * Field filters apply only when the report covers a single product type.
	 */
	private function getActiveProductType(): ?ProductType
	{
		$onlyProductType = $this->getOnlyProductType();
		if ($onlyProductType instanceof ProductType) {
			return $onlyProductType;
		}

		$productTypeHandles = $this->resolveProductTypeHandles();

		return count($productTypeHandles) === 1
			? $this->getCommerce()->getProductTypes()->getProductTypeByHandle($productTypeHandles[0])
			: null;
	}

	private function getOnlyProductType(): ?ProductType
	{
		$productTypes = $this->getCommerce()->getProductTypes()->getAllProductTypes();

		return count($productTypes) === 1 ? reset($productTypes) : null;
	}

	/**
	 * @return list<FieldFilter>
	 */
	private function resolveFieldFilters(): array
	{
		$activeProductType = $this->getActiveProductType();
		if (! $activeProductType instanceof ProductType) {
			return [];
		}

		$filterValues = $this->resolveFilterValues();
		$fieldFilters = [];
		foreach (Plugin::getInstance()->variantFields->getFilterFields($activeProductType) as $instanceUid => $field) {
			if (($filterValues[$instanceUid] ?? []) !== []) {
				$fieldFilters[] = new FieldFilter([
					'productTypeId' => (int) $activeProductType->id,
					'field' => $field,
					'values' => $filterValues[$instanceUid],
				]);
			}
		}

		return $fieldFilters;
	}

	/**
	 * @return array<string, list<string>>
	 */
	private function resolveFilterValues(): array
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$rawFieldFilters = $request->getQueryParam('fieldFilter', []);
		if (! is_array($rawFieldFilters)) {
			return [];
		}

		$filterValues = [];
		foreach ($rawFieldFilters as $instanceUid => $rawValues) {
			if (! is_array($rawValues)) {
				continue;
			}

			foreach ($rawValues as $rawValue) {
				if (is_scalar($rawValue) && (string) $rawValue !== '') {
					$filterValues[(string) $instanceUid][] = (string) $rawValue;
				}
			}
		}

		return $filterValues;
	}

	private function getCommerce(): Commerce
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return $commerce;
	}

	private function isProfitView(): bool
	{
		return Plugin::getInstance()->variantFields->hasUnitCostField() && $this->request->getQueryParam('reportMode') === 'profit';
	}

	/**
	 * @param list<ProductRow> $productRows
	 * @return array{cost: float, grossProfit: float, grossMargin: float|null}
	 */
	private function getCostTotals(array $productRows): array
	{
		$currency = MoneyMath::currency();
		$totalCost = new Money(0, $currency);
		$totalRevenue = new Money(0, $currency);
		foreach ($productRows as $productRow) {
			$totalCost = $totalCost->add(MoneyMath::toMoney((float) $productRow->cost, $currency));
			$totalRevenue = $totalRevenue->add(MoneyMath::toMoney($productRow->revenue, $currency));
		}

		return Plugin::getInstance()->productStats->getCostFigures(MoneyMath::toDecimal($totalCost), MoneyMath::toDecimal($totalRevenue));
	}

	private function formatOptionalCurrency(?float $amount): string
	{
		return $amount === null ? '-' : $this->formatCurrency($amount);
	}

	private function formatOptionalPercent(?float $ratio): string
	{
		return $ratio === null ? '-' : Craft::$app->getFormatter()->asPercent($ratio, 1);
	}
}
