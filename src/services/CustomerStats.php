<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\db\Query;
use yii\base\Component;

class CustomerStats extends Component
{
	/**
	 * Get customer KPIs for a date range.
	 *
	 * @return array{total: int, new: int, returning: int, repeatRate: float}
	 */
	public function getCustomerKpis(string $fromDT, string $toDT): array
	{
		$dateCondition = [
			'and',
			['=', '[[isCompleted]]', true],
			['>=', '[[dateOrdered]]', $fromDT],
			['<=', '[[dateOrdered]]', $toDT],
		];

		$total = (int) (new Query())
			->select('COUNT(DISTINCT [[customerId]])')
			->from('{{%commerce_orders}}')
			->where($dateCondition)
			->andWhere(['not', ['customerId' => null]])
			->scalar();

		// Customer IDs who ordered in this period
		$customerIds = (new Query())
			->select('DISTINCT [[customerId]]')
			->from('{{%commerce_orders}}')
			->where($dateCondition)
			->andWhere(['not', ['customerId' => null]])
			->column();

		$new = 0;
		$returning = 0;

		if (! empty($customerIds)) {
			$new = (int) (new Query())
				->from([
					'firstOrders' => (new Query())
						->select([
							'customerId',
							'firstOrder' => 'MIN([[dateOrdered]])',
						])
						->from('{{%commerce_orders}}')
						->where([
							'and',
							['=', '[[isCompleted]]', true],
							['in', '[[customerId]]', $customerIds],
						])
						->groupBy('[[customerId]]'),
				])
				->andWhere(['>=', 'firstOrder', $fromDT])
				->andWhere(['<=', 'firstOrder', $toDT])
				->count();

			$returning = max(0, $total - $new);
		}

		$repeatRate = $total > 0 ? round(($returning / $total) * 100, 1) : 0;

		return [
			'total' => $total,
			'new' => $new,
			'returning' => $returning,
			'repeatRate' => $repeatRate,
		];
	}

	/**
	 * Get new vs returning customer counts by day.
	 *
	 * @return array{labels: list<string>, new: list<int>, returning: list<int>}
	 */
	public function getNewVsReturningByDay(string $fromDT, string $toDT): array
	{
		$db = Craft::$app->getDb();
		$isMysql = $db->getIsMysql();

		$dayExpression = $isMysql
			? 'DATE([[dateOrdered]])'
			: 'CAST([[dateOrdered]] AS DATE)';

		// Get first order date for each customer who ordered in the range
		$customerIds = (new Query())
			->select('DISTINCT [[customerId]]')
			->from('{{%commerce_orders}}')
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				['>=', '[[dateOrdered]]', $fromDT],
				['<=', '[[dateOrdered]]', $toDT],
				['not', ['customerId' => null]],
			])
			->column();

		if (empty($customerIds)) {
			return ['labels' => [], 'new' => [], 'returning' => []];
		}

		// Get first order date for these customers
		$firstOrders = (new Query())
			->select([
				'customerId',
				'firstOrder' => 'MIN([[dateOrdered]])',
			])
			->from('{{%commerce_orders}}')
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				['in', '[[customerId]]', $customerIds],
			])
			->groupBy('[[customerId]]')
			->all();

		$firstOrderMap = [];
		foreach ($firstOrders as $row) {
			$firstOrderMap[$row['customerId']] = substr($row['firstOrder'], 0, 10);
		}

		// Get all orders in the range grouped by day and customer
		$orders = (new Query())
			->select([
				'day' => $dayExpression,
				'customerId',
			])
			->from('{{%commerce_orders}}')
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				['>=', '[[dateOrdered]]', $fromDT],
				['<=', '[[dateOrdered]]', $toDT],
				['not', ['customerId' => null]],
			])
			->all();

		// Group by day
		$byDay = [];
		foreach ($orders as $row) {
			$day = $row['day'];
			$customerId = $row['customerId'];
			if (! isset($byDay[$day])) {
				$byDay[$day] = ['new' => [], 'returning' => []];
			}
			$isNew = isset($firstOrderMap[$customerId]) && $firstOrderMap[$customerId] === $day;
			if ($isNew) {
				$byDay[$day]['new'][$customerId] = true;
			} else {
				$byDay[$day]['returning'][$customerId] = true;
			}
		}

		ksort($byDay);

		$labels = [];
		$newCounts = [];
		$returningCounts = [];

		foreach ($byDay as $day => $data) {
			$labels[] = $day;
			$newCounts[] = count($data['new']);
			$returningCounts[] = count($data['returning']);
		}

		return [
			'labels' => $labels,
			'new' => $newCounts,
			'returning' => $returningCounts,
		];
	}

	/**
	 * Get top customers by total spent, with AOV and guest detection.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getTopCustomers(string $fromDT, string $toDT, int $limit = 100): array
	{
		$rows = (new Query())
			->select([
				'email' => '[[orders.email]]',
				'customerId' => '[[orders.customerId]]',
				'orderCount' => 'COUNT(*)',
				'totalSpent' => 'COALESCE(SUM([[orders.totalPrice]]), 0)',
				'lastOrder' => 'MAX([[orders.dateOrdered]])',
			])
			->from(['orders' => '{{%commerce_orders}}'])
			->where([
				'and',
				['=', '[[orders.isCompleted]]', true],
				['>=', '[[orders.dateOrdered]]', $fromDT],
				['<=', '[[orders.dateOrdered]]', $toDT],
			])
			->groupBy('[[orders.email]], [[orders.customerId]]')
			->orderBy(['totalSpent' => SORT_DESC])
			->limit($limit)
			->all();

		return array_map(function ($row) {
			$orderCount = (int) $row['orderCount'];
			$totalSpent = (float) $row['totalSpent'];
			return [
				'email' => $row['email'],
				'customerId' => $row['customerId'] ? (int) $row['customerId'] : null,
				'isGuest' => empty($row['customerId']),
				'orderCount' => $orderCount,
				'totalSpent' => $totalSpent,
				'aov' => $orderCount > 0 ? round($totalSpent / $orderCount, 2) : 0,
				'lastOrder' => $row['lastOrder'],
			];
		}, $rows);
	}

	/**
	 * Get top shipping locations by customer count.
	 *
	 * @return array<int, array{country: string, state: string, count: int}>
	 */
	public function getTopShippingLocations(string $fromDT, string $toDT, int $limit = 10): array
	{
		$rows = (new Query())
			->select([
				'country' => '[[addresses.countryCode]]',
				'state' => 'COALESCE([[addresses.administrativeArea]], \'\')',
				'count' => 'COUNT(DISTINCT [[orders.email]])',
			])
			->from(['orders' => '{{%commerce_orders}}'])
			->innerJoin(['addresses' => '{{%addresses}}'], '[[orders.shippingAddressId]] = [[addresses.id]]')
			->where([
				'and',
				['=', '[[orders.isCompleted]]', true],
				['>=', '[[orders.dateOrdered]]', $fromDT],
				['<=', '[[orders.dateOrdered]]', $toDT],
				['not', ['[[addresses.countryCode]]' => null]],
			])
			->groupBy('[[addresses.countryCode]], [[addresses.administrativeArea]]')
			->orderBy(['count' => SORT_DESC])
			->limit($limit)
			->all();

		return array_map(fn ($row) => [
			'country' => $row['country'],
			'state' => $row['state'],
			'count' => (int) $row['count'],
		], $rows);
	}

	/**
	 * Get LTV distribution buckets.
	 *
	 * @return array{labels: list<string>, counts: list<int>}
	 */
	public function getLtvDistribution(string $fromDT, string $toDT): array
	{
		$customers = (new Query())
			->select([
				'totalSpent' => 'COALESCE(SUM([[totalPrice]]), 0)',
			])
			->from('{{%commerce_orders}}')
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				['>=', '[[dateOrdered]]', $fromDT],
				['<=', '[[dateOrdered]]', $toDT],
				['not', ['customerId' => null]],
			])
			->groupBy('[[customerId]]')
			->column();

		$buckets = [
			'$0-50' => 0,
			'$50-100' => 0,
			'$100-250' => 0,
			'$250-500' => 0,
			'$500-1000' => 0,
			'$1000+' => 0,
		];

		foreach ($customers as $spent) {
			$spent = (float) $spent;
			if ($spent < 50) {
				$buckets['$0-50']++;
			} elseif ($spent < 100) {
				$buckets['$50-100']++;
			} elseif ($spent < 250) {
				$buckets['$100-250']++;
			} elseif ($spent < 500) {
				$buckets['$250-500']++;
			} elseif ($spent < 1000) {
				$buckets['$500-1000']++;
			} else {
				$buckets['$1000+']++;
			}
		}

		return [
			'labels' => array_keys($buckets),
			'counts' => array_values($buckets),
		];
	}
}
