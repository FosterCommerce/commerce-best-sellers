<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\db\Query;
use fostercommerce\bestsellers\records\DailyStat;
use yii\base\Component;

class OperationsStats extends Component
{
	/**
	 * Get operations KPIs.
	 *
	 * @return array{avgItemsPerOrder: float, avgDiscount: float, pctWithCoupon: float, topShippingMethod: string}
	 */
	public function getOperationsKpis(string $fromDT, string $toDT): array
	{
		$dateCondition = [
			'and',
			['=', 'isCompleted', true],
			['>=', 'dateOrdered', $fromDT],
			['<=', 'dateOrdered', $toDT],
		];

		$orderStats = (new Query())
			->select([
				'totalOrders' => 'COUNT(*)',
				'avgDiscount' => 'COALESCE(AVG(ABS([[totalDiscount]])), 0)',
				'withCoupon' => 'SUM(CASE WHEN [[couponCode]] IS NOT NULL AND [[couponCode]] != \'\' THEN 1 ELSE 0 END)',
			])
			->from('{{%commerce_orders}}')
			->where($dateCondition)
			->one();

		$totalOrders = (int) ($orderStats['totalOrders'] ?? 0);
		$avgDiscount = round((float) ($orderStats['avgDiscount'] ?? 0), 2);
		$withCoupon = (int) ($orderStats['withCoupon'] ?? 0);
		$pctWithCoupon = $totalOrders > 0 ? round(($withCoupon / $totalOrders) * 100, 1) : 0;

		// Total items sold
		$totalItemsSold = (int) (new Query())
			->select('COALESCE(SUM(li.[[qty]]), 0)')
			->from(['li' => '{{%commerce_lineitems}}'])
			->innerJoin(['o' => '{{%commerce_orders}}'], 'li.[[orderId]] = o.[[id]]')
			->where([
				'and',
				['=', 'o.[[isCompleted]]', true],
				['>=', 'o.[[dateOrdered]]', $fromDT],
				['<=', 'o.[[dateOrdered]]', $toDT],
			])
			->scalar();

		$avgItemsPerOrder = $totalOrders > 0 ? round($totalItemsSold / $totalOrders, 2) : 0;

		// Top shipping method
		$topShipping = (new Query())
			->select([
				'method' => 'COALESCE([[shippingMethodName]], \'None\')',
				'cnt' => 'COUNT(*)',
			])
			->from('{{%commerce_orders}}')
			->where($dateCondition)
			->groupBy('[[shippingMethodName]]')
			->orderBy(['cnt' => SORT_DESC])
			->limit(1)
			->one();

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
	public function getItemsPerOrderDistribution(string $fromDT, string $toDT): array
	{
		$dateCondition = [
			'and',
			['=', 'o.[[isCompleted]]', true],
			['>=', 'o.[[dateOrdered]]', $fromDT],
			['<=', 'o.[[dateOrdered]]', $toDT],
		];

		$orders = (new Query())
			->select([
				'itemCount' => 'SUM(li.[[qty]])',
			])
			->from(['li' => '{{%commerce_lineitems}}'])
			->innerJoin(['o' => '{{%commerce_orders}}'], 'li.[[orderId]] = o.[[id]]')
			->where($dateCondition)
			->groupBy('o.[[id]]')
			->column();

		$buckets = [
			'1' => 0,
			'2' => 0,
			'3' => 0,
			'4-5' => 0,
			'6-10' => 0,
			'11+' => 0,
		];

		foreach ($orders as $count) {
			$count = (int) $count;
			if ($count <= 1) {
				$buckets['1']++;
			} elseif ($count === 2) {
				$buckets['2']++;
			} elseif ($count === 3) {
				$buckets['3']++;
			} elseif ($count <= 5) {
				$buckets['4-5']++;
			} elseif ($count <= 10) {
				$buckets['6-10']++;
			} else {
				$buckets['11+']++;
			}
		}

		return [
			'labels' => array_keys($buckets),
			'counts' => array_values($buckets),
		];
	}

	/**
	 * Get shipping method breakdown.
	 *
	 * @return array<int, array{method: string, count: int, revenue: float}>
	 */
	public function getShippingMethods(string $fromDT, string $toDT): array
	{
		$dateCondition = [
			'and',
			['=', 'isCompleted', true],
			['>=', 'dateOrdered', $fromDT],
			['<=', 'dateOrdered', $toDT],
		];

		return (new Query())
			->select([
				'method' => 'COALESCE([[shippingMethodName]], \'None\')',
				'count' => 'COUNT(*)',
				'revenue' => 'COALESCE(SUM([[totalShippingCost]]), 0)',
			])
			->from('{{%commerce_orders}}')
			->where($dateCondition)
			->groupBy('[[shippingMethodName]]')
			->orderBy(['count' => SORT_DESC])
			->all();
	}

	/**
	 * Get coupon usage statistics.
	 *
	 * @return array<int, array{code: string, uses: int, totalDiscount: float}>
	 */
	public function getCouponUsage(string $fromDT, string $toDT): array
	{
		$dateCondition = [
			'and',
			['=', 'isCompleted', true],
			['>=', 'dateOrdered', $fromDT],
			['<=', 'dateOrdered', $toDT],
			['not', ['couponCode' => null]],
			['!=', 'couponCode', ''],
		];

		return (new Query())
			->select([
				'code' => '[[couponCode]]',
				'uses' => 'COUNT(*)',
				'totalDiscount' => 'COALESCE(SUM(ABS([[totalDiscount]])), 0)',
			])
			->from('{{%commerce_orders}}')
			->where($dateCondition)
			->groupBy('[[couponCode]]')
			->orderBy(['uses' => SORT_DESC])
			->all();
	}

	/**
	 * Get discount trend over time.
	 *
	 * @return array{labels: list<string>, discounts: list<float>}
	 */
	public function getDiscountTrend(string $fromDT, string $toDT): array
	{
		$rows = (new Query())
			->select([
				'date',
				'totalDiscount',
			])
			->from(DailyStat::tableName())
			->where(['>=', 'date', substr($fromDT, 0, 10)])
			->andWhere(['<=', 'date', substr($toDT, 0, 10)])
			->orderBy(['date' => SORT_ASC])
			->all();

		return [
			'labels' => array_column($rows, 'date'),
			'discounts' => array_map('floatval', array_column($rows, 'totalDiscount')),
		];
	}
}
