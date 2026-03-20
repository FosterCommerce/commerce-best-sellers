<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\db\Query;
use fostercommerce\bestsellers\records\VariantSale;
use yii\base\Component;

class ProductStats extends Component
{
	/**
	 * Get top products by revenue or units.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getTopProducts(string $fromDT, string $toDT, string $sortBy = 'revenue', int $limit = 50, ?string $productTypeHandle = null): array
	{
		$query = (new Query())
			->select([
				'productId' => '[[variantSales.productId]]',
				'productTitle' => '[[variantSales.productTitle]]',
				'unitsSold' => 'SUM([[variantSales.qty]])',
				'orderCount' => 'COUNT(DISTINCT [[variantSales.orderId]])',
				'revenue' => 'COALESCE(SUM([[variantSales.lineItemTotal]]), 0)',
				'avgPrice' => 'COALESCE(AVG([[variantSales.lineItemPrice]]), 0)',
				'productType' => '[[productTypes.name]]',
			])
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->innerJoin([
				'products' => '{{%commerce_products}}',
			], '[[variantSales.productId]] = [[products.id]]')
			->innerJoin([
				'productTypes' => '{{%commerce_producttypes}}',
			], '[[products.typeId]] = [[productTypes.id]]')
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->groupBy('[[variantSales.productId]], [[variantSales.productTitle]], [[productTypes.name]]');

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere([
				'[[productTypes.handle]]' => $productTypeHandle,
			]);
		}

		$orderColumn = $sortBy === 'units' ? 'unitsSold' : 'revenue';
		$query->orderBy([
			$orderColumn => SORT_DESC,
		])->limit($limit);

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $query->all();

		return $rows;
	}

	/**
	 * Get top variants by revenue or units.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getTopVariants(string $fromDT, string $toDT, string $sortBy = 'revenue', int $limit = 50, ?string $productTypeHandle = null): array
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
				'revenue' => 'COALESCE(SUM([[variantSales.lineItemTotal]]), 0)',
				'avgPrice' => 'COALESCE(AVG([[variantSales.lineItemPrice]]), 0)',
				'productType' => '[[productTypes.name]]',
			])
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->innerJoin([
				'products' => '{{%commerce_products}}',
			], '[[variantSales.productId]] = [[products.id]]')
			->innerJoin([
				'productTypes' => '{{%commerce_producttypes}}',
			], '[[products.typeId]] = [[productTypes.id]]')
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->groupBy('[[variantSales.productId]], [[variantSales.variantId]], [[variantSales.variantTitle]], [[variantSales.variantSku]], [[variantSales.productTitle]], [[productTypes.name]]');

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere([
				'[[productTypes.handle]]' => $productTypeHandle,
			]);
		}

		$orderColumn = $sortBy === 'units' ? 'unitsSold' : 'revenue';
		$query->orderBy([
			$orderColumn => SORT_DESC,
		])->limit($limit);

		/** @var array<int, array<string, mixed>> $rows */
		$rows = $query->all();

		return $rows;
	}

	/**
	 * Get daily revenue for the top N products (for trend chart).
	 *
	 * @return array{labels: list<string>, datasets: array<int, array{label: string, data: list<float>}>}
	 */
	public function getTopProductsTrend(string $fromDT, string $toDT, bool $variants = false, int $limit = 5): array
	{
		$db = Craft::$app->getDb();
		$isMysql = $db->getIsMysql();
		$dayExpr = $isMysql ? 'DATE([[variantSales.dateOrdered]])' : 'CAST([[variantSales.dateOrdered]] AS DATE)';

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

		$topItems = (new Query())
			->select($selectFields)
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->groupBy($groupFields)
			->orderBy([
				'SUM([[variantSales.lineItemTotal]])' => SORT_DESC,
			])
			->limit($limit)
			->all();

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

		// Get daily revenue for these items
		$rows = (new Query())
			->select([
				'day' => $dayExpr,
				'itemId' => "[[variantSales.{$idCol}]]",
				'revenue' => 'COALESCE(SUM([[variantSales.lineItemTotal]]), 0)',
			])
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->andWhere(['in', "[[variantSales.{$idCol}]]", $itemIds])
			->groupBy([$dayExpr, "[[variantSales.{$idCol}]]"])
			->orderBy([
				'day' => SORT_ASC,
			])
			->all();

		// Collect all unique days
		$allDays = [];
		$byItem = [];
		/** @var array{day: string, itemId: string, revenue: string} $row */
		foreach ($rows as $row) {
			$allDays[$row['day']] = true;
			$byItem[$row['itemId']][$row['day']] = (float) $row['revenue'];
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
	public function getParetoData(string $fromDT, string $toDT, bool $variants = false, ?string $productTypeHandle = null): array
	{
		$idCol = $variants ? 'variantId' : 'productId';
		$titleCol = $variants ? 'variantTitle' : 'productTitle';

		$query = (new Query())
			->select([
				'title' => "[[variantSales.{$titleCol}]]",
				'revenue' => 'COALESCE(SUM([[variantSales.lineItemTotal]]), 0)',
			])
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->innerJoin([
				'products' => '{{%commerce_products}}',
			], '[[variantSales.productId]] = [[products.id]]')
			->innerJoin([
				'productTypes' => '{{%commerce_producttypes}}',
			], '[[products.typeId]] = [[productTypes.id]]')
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->groupBy("[[variantSales.{$idCol}]], [[variantSales.{$titleCol}]]")
			->orderBy([
				'revenue' => SORT_DESC,
			]);

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
	public function getPriceVsUnits(string $fromDT, string $toDT, bool $variants = false, ?string $productTypeHandle = null): array
	{
		$idCol = $variants ? 'variantId' : 'productId';
		$titleCol = $variants ? 'variantTitle' : 'productTitle';

		$query = (new Query())
			->select([
				'title' => "[[variantSales.{$titleCol}]]",
				'avgPrice' => 'COALESCE(AVG([[variantSales.lineItemPrice]]), 0)',
				'unitsSold' => 'SUM([[variantSales.qty]])',
			])
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->innerJoin([
				'products' => '{{%commerce_products}}',
			], '[[variantSales.productId]] = [[products.id]]')
			->innerJoin([
				'productTypes' => '{{%commerce_producttypes}}',
			], '[[products.typeId]] = [[productTypes.id]]')
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->groupBy("[[variantSales.{$idCol}]], [[variantSales.{$titleCol}]]")
			->orderBy([
				'unitsSold' => SORT_DESC,
			]);

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere([
				'[[productTypes.handle]]' => $productTypeHandle,
			]);
		}

		/** @var array<int, array{title: string, avgPrice: string, unitsSold: string}> $queryRows */
		$queryRows = $query->all();

		return array_map(fn (array $row): array => [
			'label' => $row['title'],
			'price' => round((float) $row['avgPrice'], 2),
			'units' => (int) $row['unitsSold'],
		], $queryRows);
	}

	/**
	 * Get product summary stats for KPI cards.
	 *
	 * @return array{uniqueProducts: int, bestSeller: string, bestSellerUnits: int, totalProductRevenue: float}
	 */
	public function getSummaryStats(string $fromDT, string $toDT): array
	{
		$uniqueProducts = (int) (new Query())
			->select('COUNT(DISTINCT [[variantSales.productId]])')
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->scalar();

		/** @var array{title: string, unitsSold: string, revenue: string}|false $topProduct */
		$topProduct = (new Query())
			->select([
				'title' => '[[variantSales.productTitle]]',
				'unitsSold' => 'SUM([[variantSales.qty]])',
				'revenue' => new \yii\db\Expression('COALESCE(SUM([[variantSales.lineItemTotal]]), 0)'),
			])
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->groupBy('[[variantSales.productId]], [[variantSales.productTitle]]')
			->orderBy([
				'unitsSold' => SORT_DESC,
			])
			->limit(1)
			->one();

		$totalProductRevenue = (float) (new Query())
			->select(new \yii\db\Expression('COALESCE(SUM([[variantSales.lineItemTotal]]), 0)'))
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->scalar();

		return [
			'uniqueProducts' => $uniqueProducts,
			'bestSeller' => $topProduct ? $topProduct['title'] : '-',
			'bestSellerUnits' => $topProduct ? (int) $topProduct['unitsSold'] : 0,
			'totalProductRevenue' => $totalProductRevenue,
		];
	}

	/**
	 * Revenue by product type.
	 *
	 * @return array<int, array{productType: string, revenue: float, unitsSold: int}>
	 */
	public function getRevenueByType(string $fromDT, string $toDT): array
	{
		/** @var array<int, array{productType: string, revenue: float, unitsSold: int}> $rows */
		$rows = (new Query())
			->select([
				'productType' => '[[productTypes.name]]',
				'revenue' => 'COALESCE(SUM([[variantSales.lineItemTotal]]), 0)',
				'unitsSold' => 'SUM([[variantSales.qty]])',
			])
			->from([
				'variantSales' => VariantSale::tableName(),
			])
			->innerJoin([
				'products' => '{{%commerce_products}}',
			], '[[variantSales.productId]] = [[products.id]]')
			->innerJoin([
				'productTypes' => '{{%commerce_producttypes}}',
			], '[[products.typeId]] = [[productTypes.id]]')
			->where(['>=', '[[variantSales.dateOrdered]]', $fromDT])
			->andWhere(['<=', '[[variantSales.dateOrdered]]', $toDT])
			->groupBy('[[productTypes.name]]')
			->orderBy([
				'revenue' => SORT_DESC,
			])
			->all();

		return $rows;
	}
}
