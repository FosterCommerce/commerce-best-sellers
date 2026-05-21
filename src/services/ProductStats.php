<?php

namespace fostercommerce\bestsellers\services;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\MoneyMath;
use fostercommerce\bestsellers\models\ProductRow;
use fostercommerce\bestsellers\models\ProductSummary;
use fostercommerce\bestsellers\models\ReportScope;
use yii\base\Component;
use yii\db\Expression;

class ProductStats extends Component
{
	/**
	 * Get top products by revenue or units.
	 *
	 * @return list<ProductRow>
	 */
	public function getTopProducts(ReportScope $scope, string $sortBy = 'revenue', int $limit = 50, ?string $productTypeHandle = null): array
	{
		$query = (new Query())
			->select([
				'productId' => '[[variantSales.productId]]',
				'productTitle' => '[[variantSales.productTitle]]',
				'unitsSold' => 'SUM([[variantSales.qty]])',
				'orderCount' => 'COUNT(DISTINCT [[variantSales.orderId]])',
				'itemSubtotal' => 'COALESCE(SUM([[variantSales.lineItemTotal]]), 0)',
				'revenue' => 'COALESCE(SUM([[variantSales.lineItemTotal]] + [[variantSales.lineDiscount]]), 0)',
				'avgPrice' => 'COALESCE(SUM([[variantSales.catalogPrice]] * [[variantSales.qty]]) / NULLIF(SUM([[variantSales.qty]]), 0), 0)',
				'productType' => "COALESCE([[productTypes.name]], 'Unknown')",
				'fromBundle' => 'MAX(CASE WHEN [[variantSales.sourceBundleId]] IS NOT NULL THEN 1 ELSE 0 END)',
				// Live partial-payment flag: 1 if any contributing order has
				// totalPaid < totalPrice. Fully-unpaid orders (totalPaid <= 0
				// AND totalPrice > 0) are excluded upstream, so a 1 here means
				// at least one order is partially paid right now. Reflects
				// current state, not the state at order completion
				// (variant_sales is frozen but orders are live).
				'hasUnpaidOrder' => 'MAX(CASE WHEN [[orders.totalPaid]] < [[orders.totalPrice]] THEN 1 ELSE 0 END)',
			])
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->leftJoin([
				'productTypes' => CommerceTable::PRODUCTTYPES,
			], '[[variantSales.productTypeId]] = [[productTypes.id]]')
			->where($scope->dateRange->dateCondition('[[variantSales.dateOrdered]]'))
			->groupBy([
				'[[variantSales.productId]]',
				'[[variantSales.productTitle]]',
				new Expression("COALESCE([[productTypes.name]], 'Unknown')"),
			]);

		$this->applyOrdersJoinAndFilters($query, $scope);

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere([
				'[[productTypes.handle]]' => $productTypeHandle,
			]);
		}

		$orderColumn = match ($sortBy) {
			'units' => 'unitsSold',
			'itemSubtotal' => 'itemSubtotal',
			default => 'revenue',
		};
		$query->orderBy([
			$orderColumn => SORT_DESC,
		])->limit($limit);

		/** @var list<array{productId: int|string, productTitle: string, unitsSold: int|string, orderCount: int|string, itemSubtotal: float|string, revenue: float|string, avgPrice: float|string, productType: string, fromBundle: int|string, hasUnpaidOrder: int|string}> $rows */
		$rows = $query->all();

		return array_map(fn (array $row): ProductRow => new ProductRow([
			'productId' => (int) $row['productId'],
			'productTitle' => $row['productTitle'],
			'unitsSold' => (int) $row['unitsSold'],
			'orderCount' => (int) $row['orderCount'],
			'itemSubtotal' => (float) $row['itemSubtotal'],
			'revenue' => (float) $row['revenue'],
			'avgPrice' => (float) $row['avgPrice'],
			'productType' => $row['productType'],
			'fromBundle' => (bool) $row['fromBundle'],
			'hasUnpaidOrder' => (bool) $row['hasUnpaidOrder'],
		]), $rows);
	}

	/**
	 * Get top variants by revenue or units.
	 *
	 * @return list<ProductRow>
	 */
	public function getTopVariants(ReportScope $scope, string $sortBy = 'revenue', int $limit = 50, ?string $productTypeHandle = null): array
	{
		$query = (new Query())
			->select([
				'productId' => '[[variantSales.productId]]',
				'variantId' => '[[variantSales.variantId]]',
				'variantTitle' => '[[variantSales.variantTitle]]',
				'variantSku' => '[[variantSales.variantSku]]',
				'productTitle' => '[[variantSales.productTitle]]',
				'unitsSold' => 'SUM([[variantSales.qty]])',
				'orderCount' => 'COUNT(DISTINCT [[variantSales.orderId]])',
				'itemSubtotal' => 'COALESCE(SUM([[variantSales.lineItemTotal]]), 0)',
				'revenue' => 'COALESCE(SUM([[variantSales.lineItemTotal]] + [[variantSales.lineDiscount]]), 0)',
				'avgPrice' => 'COALESCE(SUM([[variantSales.catalogPrice]] * [[variantSales.qty]]) / NULLIF(SUM([[variantSales.qty]]), 0), 0)',
				'productType' => "COALESCE([[productTypes.name]], 'Unknown')",
				'fromBundle' => 'MAX(CASE WHEN [[variantSales.sourceBundleId]] IS NOT NULL THEN 1 ELSE 0 END)',
				'hasUnpaidOrder' => 'MAX(CASE WHEN [[orders.totalPaid]] < [[orders.totalPrice]] THEN 1 ELSE 0 END)',
			])
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->leftJoin([
				'productTypes' => CommerceTable::PRODUCTTYPES,
			], '[[variantSales.productTypeId]] = [[productTypes.id]]')
			->where($scope->dateRange->dateCondition('[[variantSales.dateOrdered]]'))
			->groupBy([
				'[[variantSales.productId]]',
				'[[variantSales.variantId]]',
				'[[variantSales.variantTitle]]',
				'[[variantSales.variantSku]]',
				'[[variantSales.productTitle]]',
				new Expression("COALESCE([[productTypes.name]], 'Unknown')"),
			]);

		$this->applyOrdersJoinAndFilters($query, $scope);

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere([
				'[[productTypes.handle]]' => $productTypeHandle,
			]);
		}

		$orderColumn = match ($sortBy) {
			'units' => 'unitsSold',
			'itemSubtotal' => 'itemSubtotal',
			default => 'revenue',
		};
		$query->orderBy([
			$orderColumn => SORT_DESC,
		])->limit($limit);

		/** @var list<array{productId: int|string, variantId: int|string, variantTitle: string, variantSku: string, productTitle: string, unitsSold: int|string, orderCount: int|string, itemSubtotal: float|string, revenue: float|string, avgPrice: float|string, productType: string, fromBundle: int|string, hasUnpaidOrder: int|string}> $rows */
		$rows = $query->all();

		return array_map(fn (array $row): ProductRow => new ProductRow([
			'productId' => (int) $row['productId'],
			'productTitle' => $row['productTitle'],
			'unitsSold' => (int) $row['unitsSold'],
			'orderCount' => (int) $row['orderCount'],
			'itemSubtotal' => (float) $row['itemSubtotal'],
			'revenue' => (float) $row['revenue'],
			'avgPrice' => (float) $row['avgPrice'],
			'productType' => $row['productType'],
			'variantId' => (int) $row['variantId'],
			'variantTitle' => $row['variantTitle'],
			'variantSku' => $row['variantSku'],
			'fromBundle' => (bool) $row['fromBundle'],
			'hasUnpaidOrder' => (bool) $row['hasUnpaidOrder'],
		]), $rows);
	}

	/**
	 * Get daily revenue for the top N products (for trend chart).
	 *
	 * @return array{labels: list<string>, datasets: array<int, array{label: string, data: list<float>}>}
	 */
	public function getTopProductsTrend(ReportScope $scope, bool $variants = false, int $limit = 5): array
	{
		$idCol = $variants ? 'variantId' : 'productId';
		$titleCol = $variants ? 'variantTitle' : 'productTitle';

		// Get top N by total revenue
		$selectFields = [
			'itemId' => "[[variantSales.{$idCol}]]",
			'title' => "[[variantSales.{$titleCol}]]",
		];
		$groupFields = "[[variantSales.{$idCol}]], [[variantSales.{$titleCol}]]";

		if ($variants) {
			$selectFields['productTitle'] = '[[variantSales.productTitle]]';
			$groupFields .= ', [[variantSales.productTitle]]';
		}

		$topQuery = (new Query())
			->select($selectFields)
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->where($scope->dateRange->dateCondition('[[variantSales.dateOrdered]]'))
			->groupBy($groupFields)
			->orderBy([
				'SUM([[variantSales.lineItemTotal]] + [[variantSales.lineDiscount]])' => SORT_DESC,
			])
			->limit($limit);

		$this->applyOrdersJoinAndFilters($topQuery, $scope);

		$topItems = $topQuery->all();

		if (empty($topItems)) {
			return [
				'labels' => [],
				'datasets' => [],
			];
		}

		$itemIds = array_column($topItems, 'itemId');
		$titleMap = [];
		/** @var array{itemId: string, title: string, productTitle?: string} $topItem */
		foreach ($topItems as $topItem) {
			$title = $topItem['title'];
			if ($variants && ! empty($topItem['productTitle'])) {
				$title = $topItem['productTitle'] . ': ' . $title;
			}

			$titleMap[$topItem['itemId']] = $title;
		}

		// Pulls every sale row for the top-N items in the window into PHP and
		// buckets by app TZ day. SQL DATE() bucketing would be cheaper but
		// labels rows in UTC, drifting the chart off DailyStats. Trade-off:
		// full window in PHP memory for these items; long ranges on busy
		// stores are slower than the old SQL grouping.
		$dailyQuery = (new Query())
			->select([
				'dateOrdered' => '[[variantSales.dateOrdered]]',
				'itemId' => "[[variantSales.{$idCol}]]",
				'revenue' => '[[variantSales.lineItemTotal]] + [[variantSales.lineDiscount]]',
			])
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->where($scope->dateRange->dateCondition('[[variantSales.dateOrdered]]'))
			->andWhere(['in', "[[variantSales.{$idCol}]]", $itemIds]);

		$this->applyOrdersJoinAndFilters($dailyQuery, $scope);

		$rows = $dailyQuery->all();

		$allDays = [];
		$byItem = [];
		/** @var array{dateOrdered: string, itemId: string, revenue: string|float|null} $row */
		foreach ($rows as $row) {
			$saleDate = DateTimeHelper::toDateTime((string) $row['dateOrdered']);
			if ($saleDate === false) {
				continue;
			}

			$day = $saleDate->format('Y-m-d');
			$itemId = (string) $row['itemId'];
			$allDays[$day] = true;
			$byItem[$itemId][$day] = ($byItem[$itemId][$day] ?? 0.0) + (float) $row['revenue'];
		}

		ksort($allDays);
		$labels = array_keys($allDays);

		$datasets = [];
		foreach ($itemIds as $itemId) {
			$data = [];
			foreach ($labels as $label) {
				$data[] = $byItem[$itemId][$label] ?? 0;
			}

			$datasets[] = [
				'label' => $titleMap[$itemId] ?? "#{$itemId}",
				'data' => $data,
			];
		}

		return [
			'labels' => $labels,
			'datasets' => $datasets,
		];
	}

	/**
	 * Get Pareto (cumulative revenue concentration) data.
	 *
	 * @return array{labels: list<string>, values: list<float>, cumulative: list<float>}
	 */
	public function getParetoData(ReportScope $scope, bool $variants = false, ?string $productTypeHandle = null): array
	{
		$idCol = $variants ? 'variantId' : 'productId';
		$titleCol = $variants ? 'variantTitle' : 'productTitle';

		$query = (new Query())
			->select([
				'title' => "[[variantSales.{$titleCol}]]",
				'revenue' => 'COALESCE(SUM([[variantSales.lineItemTotal]] + [[variantSales.lineDiscount]]), 0)',
			])
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->leftJoin([
				'productTypes' => CommerceTable::PRODUCTTYPES,
			], '[[variantSales.productTypeId]] = [[productTypes.id]]')
			->where($scope->dateRange->dateCondition('[[variantSales.dateOrdered]]'))
			->groupBy("[[variantSales.{$idCol}]], [[variantSales.{$titleCol}]]")
			->orderBy([
				'revenue' => SORT_DESC,
			]);

		$this->applyOrdersJoinAndFilters($query, $scope);

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere([
				'[[productTypes.handle]]' => $productTypeHandle,
			]);
		}

		$rows = $query->all();

		if (empty($rows)) {
			return [
				'labels' => [],
				'values' => [],
				'cumulative' => [],
			];
		}

		$totalRevenue = array_sum(array_column($rows, 'revenue'));
		$labels = [];
		$values = [];
		$cumulative = [];
		$runningTotal = 0;

		/** @var array{title: string, revenue: string} $row */
		foreach ($rows as $row) {
			$labels[] = $row['title'];
			$rev = (float) $row['revenue'];
			$values[] = $rev;
			$runningTotal += $rev;
			$cumulative[] = $totalRevenue > 0 ? round(($runningTotal / $totalRevenue) * 100, 1) : 0;
		}

		return [
			'labels' => $labels,
			'values' => $values,
			'cumulative' => $cumulative,
		];
	}

	/**
	 * Get price vs units data for scatter chart.
	 *
	 * @return array<int, array{label: string, price: float, units: int}>
	 */
	public function getPriceVsUnits(ReportScope $scope, bool $variants = false, ?string $productTypeHandle = null): array
	{
		$idCol = $variants ? 'variantId' : 'productId';
		$titleCol = $variants ? 'variantTitle' : 'productTitle';

		$query = (new Query())
			->select([
				'title' => "[[variantSales.{$titleCol}]]",
				'avgPrice' => 'COALESCE(SUM([[variantSales.catalogPrice]] * [[variantSales.qty]]) / NULLIF(SUM([[variantSales.qty]]), 0), 0)',
				'unitsSold' => 'SUM([[variantSales.qty]])',
			])
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->leftJoin([
				'productTypes' => CommerceTable::PRODUCTTYPES,
			], '[[variantSales.productTypeId]] = [[productTypes.id]]')
			->where($scope->dateRange->dateCondition('[[variantSales.dateOrdered]]'))
			->groupBy("[[variantSales.{$idCol}]], [[variantSales.{$titleCol}]]")
			->orderBy([
				'unitsSold' => SORT_DESC,
			]);

		$this->applyOrdersJoinAndFilters($query, $scope);

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere([
				'[[productTypes.handle]]' => $productTypeHandle,
			]);
		}

		/** @var array<int, array{title: string, avgPrice: string, unitsSold: string}> $queryRows */
		$queryRows = $query->all();

		return array_map(fn (array $row): array => [
			'label' => $row['title'],
			'price' => MoneyMath::toFloat(MoneyMath::toMoney($row['avgPrice'])),
			'units' => (int) $row['unitsSold'],
		], $queryRows);
	}

	/**
	 * Get product summary stats for KPI cards.
	 */
	public function getSummaryStats(ReportScope $scope): ProductSummary
	{
		$dateConditions = $scope->dateRange->dateCondition('[[variantSales.dateOrdered]]');

		$uniqueQuery = (new Query())
			->select('COUNT(DISTINCT [[variantSales.productId]])')
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->where($dateConditions);
		$this->applyOrdersJoinAndFilters($uniqueQuery, $scope);
		$uniqueProducts = (int) $uniqueQuery->scalar();

		$topQuery = (new Query())
			->select([
				'title' => '[[variantSales.productTitle]]',
				'unitsSold' => 'SUM([[variantSales.qty]])',
				'revenue' => new Expression('COALESCE(SUM([[variantSales.lineItemTotal]] + [[variantSales.lineDiscount]]), 0)'),
			])
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->where($dateConditions)
			->groupBy('[[variantSales.productId]], [[variantSales.productTitle]]')
			->orderBy([
				'unitsSold' => SORT_DESC,
			])
			->limit(1);
		$this->applyOrdersJoinAndFilters($topQuery, $scope);

		/** @var array{title: string, unitsSold: string, revenue: string}|false $topProduct */
		$topProduct = $topQuery->one();

		$revenueQuery = (new Query())
			->select(new Expression('COALESCE(SUM([[variantSales.lineItemTotal]] + [[variantSales.lineDiscount]]), 0)'))
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->where($dateConditions);
		$this->applyOrdersJoinAndFilters($revenueQuery, $scope);
		$totalProductRevenue = (float) $revenueQuery->scalar();

		return new ProductSummary([
			'uniqueProducts' => $uniqueProducts,
			'bestSeller' => $topProduct ? $topProduct['title'] : '-',
			'bestSellerUnits' => $topProduct ? (int) $topProduct['unitsSold'] : 0,
			'totalProductRevenue' => $totalProductRevenue,
		]);
	}

	/**
	 * Revenue by product type.
	 *
	 * @return array<int, array{productType: string, revenue: float, unitsSold: int}>
	 */
	public function getRevenueByType(ReportScope $scope): array
	{
		$query = (new Query())
			->select([
				'productType' => "COALESCE([[productTypes.name]], 'Unknown')",
				'revenue' => 'COALESCE(SUM([[variantSales.lineItemTotal]] + [[variantSales.lineDiscount]]), 0)',
				'unitsSold' => 'SUM([[variantSales.qty]])',
			])
			->from([
				'variantSales' => Table::VARIANT_SALES,
			])
			->leftJoin([
				'productTypes' => CommerceTable::PRODUCTTYPES,
			], '[[variantSales.productTypeId]] = [[productTypes.id]]')
			->where($scope->dateRange->dateCondition('[[variantSales.dateOrdered]]'))
			->groupBy([
				new Expression("COALESCE([[productTypes.name]], 'Unknown')"),
			])
			->orderBy([
				'revenue' => SORT_DESC,
			]);

		$this->applyOrdersJoinAndFilters($query, $scope);

		/** @var array<int, array{productType: string, revenue: float, unitsSold: int}> $rows */
		$rows = $query->all();

		return $rows;
	}

	/**
	 * Join commerce_orders and apply standard filters that the Products report
	 * needs on every aggregate: status filter (when active) and exclusion of
	 * orders with a full balance owed.
	 *
	 * "Full balance owed" = totalPaid <= 0 AND totalPrice > 0. Catches orders
	 * that were authorized but never captured, were fully refunded, or whose
	 * payment failed but the order completed. Those orders should not
	 * contribute to "what was sold" reporting because no money was realized
	 * and (in the refund case) the items effectively came back.
	 *
	 * Caller must NOT pre-join orders; this helper owns the alias.
	 *
	 * @param Query<array-key, mixed> $query
	 */
	private function applyOrdersJoinAndFilters(Query $query, ReportScope $scope): void
	{
		$query->innerJoin(
			[
				'orders' => CommerceTable::ORDERS,
			],
			'[[variantSales.orderId]] = [[orders.id]]',
		);

		// Exclude orders with a full balance owed.
		$query->andWhere([
			'not', [
				'and',
				['<=', '[[orders.totalPaid]]', 0],
				['>', '[[orders.totalPrice]]', 0],
			],
		]);

		if ($scope->hasStatusFilter()) {
			/** @var array<mixed> $condition */
			$condition = $scope->statusCondition('orders');
			$query->andWhere($condition);
		}
	}
}
