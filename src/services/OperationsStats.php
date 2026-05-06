<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use fostercommerce\bestsellers\helpers\MoneyMath;
use fostercommerce\bestsellers\helpers\NotTrashed;
use fostercommerce\bestsellers\models\ReportScope;
use fostercommerce\bestsellers\traits\OrderQueryConditions;
use yii\base\Component;
use yii\db\Expression;

class OperationsStats extends Component
{
	use OrderQueryConditions;

	/**
	 * Get operations KPIs.
	 *
	 * @return array{avgItemsPerOrder: float, avgDiscount: float, pctWithCoupon: float, topShippingMethod: string}
	 */
	public function getOperationsKpis(ReportScope $scope): array
	{
		$dateCondition = $this->buildDateCondition($scope, 'orders');

		$orderStatsQuery = (new Query())
			->select([
				'totalOrders' => 'COUNT(*)',
				'avgDiscount' => 'COALESCE(AVG(ABS([[orders.totalDiscount]])), 0)',
				'withCoupon' => "SUM(CASE WHEN [[orders.couponCode]] IS NOT NULL AND [[orders.couponCode]] != '' THEN 1 ELSE 0 END)",
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->where($dateCondition);

		/** @var array{totalOrders: string, avgDiscount: string, withCoupon: string}|false $orderStats */
		$orderStats = NotTrashed::join($orderStatsQuery, 'orders')->one();

		$totalOrders = (int) ($orderStats['totalOrders'] ?? 0);
		$avgDiscount = MoneyMath::toFloat(MoneyMath::toMoney((float) ($orderStats['avgDiscount'] ?? 0)));
		$withCoupon = (int) ($orderStats['withCoupon'] ?? 0);
		$pctWithCoupon = $totalOrders > 0 ? round(($withCoupon / $totalOrders) * 100, 1) : 0;

		// Total items sold
		$itemsQuery = (new Query())
			->select('COALESCE(SUM([[lineItems.qty]]), 0)')
			->from([
				'lineItems' => CommerceTable::LINEITEMS,
			])
			->innerJoin([
				'orders' => CommerceTable::ORDERS,
			], '[[lineItems.orderId]] = [[orders.id]]')
			->where($dateCondition);

		$totalItemsSold = (int) NotTrashed::join($itemsQuery, 'orders')->scalar();

		$avgItemsPerOrder = $totalOrders > 0 ? round($totalItemsSold / $totalOrders, 2) : 0;

		// Top shipping method
		$topShippingQuery = (new Query())
			->select([
				'method' => "CASE WHEN [[orders.shippingMethodName]] IS NULL OR [[orders.shippingMethodName]] = '' THEN 'None' ELSE [[orders.shippingMethodName]] END",
				'cnt' => 'COUNT(*)',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->where($dateCondition)
			->groupBy('[[orders.shippingMethodName]]')
			->orderBy([
				'cnt' => SORT_DESC,
			])
			->limit(1);

		/** @var array{method: string, cnt: string}|false $topShipping */
		$topShipping = NotTrashed::join($topShippingQuery, 'orders')->one();

		$topShippingMethod = $topShipping ? ($topShipping['method'] ?: 'None') : 'None';

		return [
			'avgItemsPerOrder' => $avgItemsPerOrder,
			'avgDiscount' => $avgDiscount,
			'pctWithCoupon' => $pctWithCoupon,
			'topShippingMethod' => $topShippingMethod,
		];
	}

	/**
	 * Get items per order distribution.
	 *
	 * @return array{labels: list<string>, counts: list<int>}
	 */
	public function getItemsPerOrderDistribution(ReportScope $scope): array
	{
		$dateCondition = $this->buildDateCondition($scope, 'orders');

		$ordersQuery = (new Query())
			->select([
				'itemCount' => 'SUM([[lineItems.qty]])',
			])
			->from([
				'lineItems' => CommerceTable::LINEITEMS,
			])
			->innerJoin([
				'orders' => CommerceTable::ORDERS,
			], '[[lineItems.orderId]] = [[orders.id]]')
			->where($dateCondition)
			->groupBy('[[orders.id]]');

		$orders = NotTrashed::join($ordersQuery, 'orders')->column();

		$buckets = [
			'1' => 0,
			'2' => 0,
			'3' => 0,
			'4-5' => 0,
			'6-10' => 0,
			'11+' => 0,
		];

		foreach ($orders as $order) {
			$order = (int) $order;
			if ($order <= 1) {
				$buckets['1']++;
			} elseif ($order === 2) {
				$buckets['2']++;
			} elseif ($order === 3) {
				$buckets['3']++;
			} elseif ($order <= 5) {
				$buckets['4-5']++;
			} elseif ($order <= 10) {
				$buckets['6-10']++;
			} else {
				$buckets['11+']++;
			}
		}

		return [
			'labels' => array_map('strval', array_keys($buckets)),
			'counts' => array_values($buckets),
		];
	}

	/**
	 * Get shipping method breakdown.
	 *
	 * @return array<int, array{method: string, count: int, revenue: float}>
	 */
	public function getShippingMethods(ReportScope $scope): array
	{
		$dateCondition = $this->buildDateCondition($scope, 'orders');

		$query = (new Query())
			->select([
				'method' => "CASE WHEN [[orders.shippingMethodName]] IS NULL OR [[orders.shippingMethodName]] = '' THEN 'None' ELSE [[orders.shippingMethodName]] END",
				'count' => 'COUNT(*)',
				'revenue' => 'COALESCE(SUM([[orders.totalShippingCost]]), 0)',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->where($dateCondition)
			->groupBy('[[orders.shippingMethodName]]')
			->orderBy([
				'count' => SORT_DESC,
			]);

		/** @var array<int, array{method: string, count: int, revenue: float}> $rows */
		$rows = NotTrashed::join($query, 'orders')->all();

		return $rows;
	}

	/**
	 * Get discounted vs. full-price order breakdown.
	 *
	 * @return array{discounted: array{orders: int, revenue: float, aov: float}, fullPrice: array{orders: int, revenue: float, aov: float}}
	 */
	public function getDiscountedVsFullPrice(ReportScope $scope): array
	{
		$dateCondition = $this->buildDateCondition($scope, 'orders');

		$query = (new Query())
			->select([
				'discountedOrders' => 'SUM(CASE WHEN [[orders.totalDiscount]] < 0 THEN 1 ELSE 0 END)',
				'discountedRevenue' => 'COALESCE(SUM(CASE WHEN [[orders.totalDiscount]] < 0 THEN [[orders.totalPrice]] ELSE 0 END), 0)',
				'fullPriceOrders' => 'SUM(CASE WHEN [[orders.totalDiscount]] >= 0 OR [[orders.totalDiscount]] IS NULL THEN 1 ELSE 0 END)',
				'fullPriceRevenue' => 'COALESCE(SUM(CASE WHEN [[orders.totalDiscount]] >= 0 OR [[orders.totalDiscount]] IS NULL THEN [[orders.totalPrice]] ELSE 0 END), 0)',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->where($dateCondition);

		/** @var array{discountedOrders: string, discountedRevenue: string, fullPriceOrders: string, fullPriceRevenue: string}|false $row */
		$row = NotTrashed::join($query, 'orders')->one();

		$discountedOrders = (int) ($row['discountedOrders'] ?? 0);
		$discountedRevenue = (float) ($row['discountedRevenue'] ?? 0);
		$fullPriceOrders = (int) ($row['fullPriceOrders'] ?? 0);
		$fullPriceRevenue = (float) ($row['fullPriceRevenue'] ?? 0);

		return [
			'discounted' => [
				'orders' => $discountedOrders,
				'revenue' => $discountedRevenue,
				'aov' => MoneyMath::toFloat(MoneyMath::average($discountedRevenue, $discountedOrders)),
			],
			'fullPrice' => [
				'orders' => $fullPriceOrders,
				'revenue' => $fullPriceRevenue,
				'aov' => MoneyMath::toFloat(MoneyMath::average($fullPriceRevenue, $fullPriceOrders)),
			],
		];
	}

	/**
	 * Get the most used discounts (from order adjustments).
	 *
	 * @return list<array{discountId: int|null, name: string, uses: int, totalDiscount: float}>
	 */
	public function getTopDiscounts(ReportScope $scope, int $limit = 5): array
	{
		$dateCondition = $this->buildDateCondition($scope, 'orders');

		$isMysql = Craft::$app->getDb()->getIsMysql();
		$idExprSql = $isMysql
			? "JSON_EXTRACT([[adj.sourceSnapshot]], '$.id')"
			: "(([[adj.sourceSnapshot]])::json->>'id')::int";

		$query = (new Query())
			->select([
				'discountId' => new Expression($idExprSql),
				'name' => '[[adj.name]]',
				'uses' => 'COUNT(DISTINCT [[adj.orderId]])',
				'totalDiscount' => 'COALESCE(SUM(ABS([[adj.amount]])), 0)',
			])
			->from([
				'adj' => CommerceTable::ORDERADJUSTMENTS,
			])
			->innerJoin([
				'orders' => CommerceTable::ORDERS,
			], '[[adj.orderId]] = [[orders.id]]')
			->where($dateCondition)
			->andWhere(['=', '[[adj.type]]', 'discount'])
			->groupBy([new Expression($idExprSql), '[[adj.name]]'])
			->orderBy([
				'uses' => SORT_DESC,
			])
			->limit($limit);

		/** @var list<array{discountId: string|null, name: string, uses: string, totalDiscount: string}> $rows */
		$rows = NotTrashed::join($query, 'orders')->all();

		return array_map(fn (array $row): array => [
			'discountId' => $row['discountId'] !== null ? (int) $row['discountId'] : null,
			'name' => $row['name'],
			'uses' => (int) $row['uses'],
			'totalDiscount' => (float) $row['totalDiscount'],
		], $rows);
	}
}
