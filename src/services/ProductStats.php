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
				'productId' => 'vs.[[productId]]',
				'productTitle' => 'vs.[[productTitle]]',
				'unitsSold' => 'SUM(vs.[[qty]])',
				'orderCount' => 'COUNT(DISTINCT vs.[[orderId]])',
				'revenue' => 'COALESCE(SUM(vs.[[lineItemTotal]]), 0)',
				'avgPrice' => 'COALESCE(AVG(vs.[[lineItemPrice]]), 0)',
				'productType' => 'pt.[[name]]',
			])
			->from(['vs' => VariantSale::tableName()])
			->innerJoin(['p' => '{{%commerce_products}}'], 'vs.[[productId]] = p.[[id]]')
			->innerJoin(['pt' => '{{%commerce_producttypes}}'], 'p.[[typeId]] = pt.[[id]]')
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->groupBy('vs.[[productId]], vs.[[productTitle]], pt.[[name]]');

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere(['pt.[[handle]]' => $productTypeHandle]);
		}

		$orderColumn = $sortBy === 'units' ? 'unitsSold' : 'revenue';
		$query->orderBy([$orderColumn => SORT_DESC])->limit($limit);

		return $query->all();
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
				'productId' => 'vs.[[productId]]',
				'variantId' => 'vs.[[variantId]]',
				'variantTitle' => 'vs.[[variantTitle]]',
				'variantSku' => 'vs.[[variantSku]]',
				'productTitle' => 'vs.[[productTitle]]',
				'unitsSold' => 'SUM(vs.[[qty]])',
				'orderCount' => 'COUNT(DISTINCT vs.[[orderId]])',
				'revenue' => 'COALESCE(SUM(vs.[[lineItemTotal]]), 0)',
				'avgPrice' => 'COALESCE(AVG(vs.[[lineItemPrice]]), 0)',
				'productType' => 'pt.[[name]]',
			])
			->from(['vs' => VariantSale::tableName()])
			->innerJoin(['p' => '{{%commerce_products}}'], 'vs.[[productId]] = p.[[id]]')
			->innerJoin(['pt' => '{{%commerce_producttypes}}'], 'p.[[typeId]] = pt.[[id]]')
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->groupBy('vs.[[productId]], vs.[[variantId]], vs.[[variantTitle]], vs.[[variantSku]], vs.[[productTitle]], pt.[[name]]');

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere(['pt.[[handle]]' => $productTypeHandle]);
		}

		$orderColumn = $sortBy === 'units' ? 'unitsSold' : 'revenue';
		$query->orderBy([$orderColumn => SORT_DESC])->limit($limit);

		return $query->all();
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
		$dayExpr = $isMysql ? 'DATE(vs.[[dateOrdered]])' : 'CAST(vs.[[dateOrdered]] AS DATE)';

		$idCol = $variants ? 'variantId' : 'productId';
		$titleCol = $variants ? 'variantTitle' : 'productTitle';

		// Get top N by total revenue
		$selectFields = [
			'itemId' => "vs.[[{$idCol}]]",
			'title' => "vs.[[{$titleCol}]]",
		];
		$groupFields = "vs.[[{$idCol}]], vs.[[{$titleCol}]]";

		if ($variants) {
			$selectFields['productTitle'] = 'vs.[[productTitle]]';
			$groupFields .= ', vs.[[productTitle]]';
		}

		$topItems = (new Query())
			->select($selectFields)
			->from(['vs' => VariantSale::tableName()])
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->groupBy($groupFields)
			->orderBy(['SUM(vs.[[lineItemTotal]])' => SORT_DESC])
			->limit($limit)
			->all();

		if (empty($topItems)) {
			return ['labels' => [], 'datasets' => []];
		}

		$itemIds = array_column($topItems, 'itemId');
		$titleMap = [];
		foreach ($topItems as $item) {
			$title = $item['title'];
			if ($variants && ! empty($item['productTitle'])) {
				$title = $item['productTitle'] . ': ' . $title;
			}
			$titleMap[$item['itemId']] = $title;
		}

		// Get daily revenue for these items
		$rows = (new Query())
			->select([
				'day' => $dayExpr,
				'itemId' => "vs.[[{$idCol}]]",
				'revenue' => 'COALESCE(SUM(vs.[[lineItemTotal]]), 0)',
			])
			->from(['vs' => VariantSale::tableName()])
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->andWhere(['in', "vs.[[{$idCol}]]", $itemIds])
			->groupBy([$dayExpr, "vs.[[{$idCol}]]"])
			->orderBy(['day' => SORT_ASC])
			->all();

		// Collect all unique days
		$allDays = [];
		$byItem = [];
		foreach ($rows as $row) {
			$allDays[$row['day']] = true;
			$byItem[$row['itemId']][$row['day']] = (float) $row['revenue'];
		}
		ksort($allDays);
		$labels = array_keys($allDays);

		$datasets = [];
		foreach ($itemIds as $id) {
			$data = [];
			foreach ($labels as $day) {
				$data[] = $byItem[$id][$day] ?? 0;
			}
			$datasets[] = [
				'label' => $titleMap[$id] ?? "#{$id}",
				'data' => $data,
			];
		}

		return ['labels' => $labels, 'datasets' => $datasets];
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
				'title' => "vs.[[{$titleCol}]]",
				'revenue' => 'COALESCE(SUM(vs.[[lineItemTotal]]), 0)',
			])
			->from(['vs' => VariantSale::tableName()])
			->innerJoin(['p' => '{{%commerce_products}}'], 'vs.[[productId]] = p.[[id]]')
			->innerJoin(['pt' => '{{%commerce_producttypes}}'], 'p.[[typeId]] = pt.[[id]]')
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->groupBy("vs.[[{$idCol}]], vs.[[{$titleCol}]]")
			->orderBy(['revenue' => SORT_DESC]);

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere(['pt.[[handle]]' => $productTypeHandle]);
		}

		$rows = $query->all();

		if (empty($rows)) {
			return ['labels' => [], 'values' => [], 'cumulative' => []];
		}

		$totalRevenue = array_sum(array_column($rows, 'revenue'));
		$labels = [];
		$values = [];
		$cumulative = [];
		$runningTotal = 0;

		foreach ($rows as $row) {
			$labels[] = $row['title'];
			$rev = (float) $row['revenue'];
			$values[] = $rev;
			$runningTotal += $rev;
			$cumulative[] = $totalRevenue > 0 ? round(($runningTotal / $totalRevenue) * 100, 1) : 0;
		}

		return ['labels' => $labels, 'values' => $values, 'cumulative' => $cumulative];
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
				'title' => "vs.[[{$titleCol}]]",
				'avgPrice' => 'COALESCE(AVG(vs.[[lineItemPrice]]), 0)',
				'unitsSold' => 'SUM(vs.[[qty]])',
			])
			->from(['vs' => VariantSale::tableName()])
			->innerJoin(['p' => '{{%commerce_products}}'], 'vs.[[productId]] = p.[[id]]')
			->innerJoin(['pt' => '{{%commerce_producttypes}}'], 'p.[[typeId]] = pt.[[id]]')
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->groupBy("vs.[[{$idCol}]], vs.[[{$titleCol}]]")
			->orderBy(['unitsSold' => SORT_DESC]);

		if ($productTypeHandle && $productTypeHandle !== 'all') {
			$query->andWhere(['pt.[[handle]]' => $productTypeHandle]);
		}

		return array_map(fn ($row) => [
			'label' => $row['title'],
			'price' => round((float) $row['avgPrice'], 2),
			'units' => (int) $row['unitsSold'],
		], $query->all());
	}

	/**
	 * Get product summary stats for KPI cards.
	 *
	 * @return array{uniqueProducts: int, bestSeller: string, bestSellerUnits: int, totalProductRevenue: float}
	 */
	public function getSummaryStats(string $fromDT, string $toDT): array
	{
		$uniqueProducts = (int) (new Query())
			->select('COUNT(DISTINCT vs.[[productId]])')
			->from(['vs' => VariantSale::tableName()])
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->scalar();

		$topProduct = (new Query())
			->select([
				'title' => 'vs.[[productTitle]]',
				'unitsSold' => 'SUM(vs.[[qty]])',
				'revenue' => new \yii\db\Expression('COALESCE(SUM(vs.[[lineItemTotal]]), 0)'),
			])
			->from(['vs' => VariantSale::tableName()])
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->groupBy('vs.[[productId]], vs.[[productTitle]]')
			->orderBy(['unitsSold' => SORT_DESC])
			->limit(1)
			->one();

		$totalProductRevenue = (float) (new Query())
			->select(new \yii\db\Expression('COALESCE(SUM(vs.[[lineItemTotal]]), 0)'))
			->from(['vs' => VariantSale::tableName()])
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->scalar();

		return [
			'uniqueProducts' => $uniqueProducts,
			'bestSeller' => $topProduct ? $topProduct['title'] : '—',
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
		return (new Query())
			->select([
				'productType' => 'pt.[[name]]',
				'revenue' => 'COALESCE(SUM(vs.[[lineItemTotal]]), 0)',
				'unitsSold' => 'SUM(vs.[[qty]])',
			])
			->from(['vs' => VariantSale::tableName()])
			->innerJoin(['p' => '{{%commerce_products}}'], 'vs.[[productId]] = p.[[id]]')
			->innerJoin(['pt' => '{{%commerce_producttypes}}'], 'p.[[typeId]] = pt.[[id]]')
			->where(['>=', 'vs.[[dateOrdered]]', $fromDT])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $toDT])
			->groupBy('pt.[[name]]')
			->orderBy(['revenue' => SORT_DESC])
			->all();
	}
}
