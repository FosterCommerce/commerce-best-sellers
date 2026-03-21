<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use craft\db\Table as CraftTable;
use fostercommerce\bestsellers\models\CustomerKpis;
use fostercommerce\bestsellers\models\CustomerRow;
use fostercommerce\bestsellers\models\LtvComparison;
use fostercommerce\bestsellers\models\LtvSegment;
use fostercommerce\bestsellers\models\ReportScope;
use yii\base\Component;

class CustomerStats extends Component
{
	/**
	 * Get customer KPIs for a report scope.
	 */
	public function getCustomerKpis(ReportScope $scope): CustomerKpis
	{
		$dateCondition = $this->buildDateCondition($scope);

		$total = (int) (new Query())
			->select('COUNT(DISTINCT [[email]])')
			->from(CommerceTable::ORDERS)
			->where($dateCondition)
			->andWhere([
				'not', [
					'[[email]]' => null,
				]])
			->andWhere(['!=', '[[email]]', ''])
			->scalar();

		// Emails who ordered in this period
		$customerEmails = (new Query())
			->select('DISTINCT [[email]]')
			->from(CommerceTable::ORDERS)
			->where($dateCondition)
			->andWhere([
				'not', [
					'[[email]]' => null,
				]])
			->andWhere(['!=', '[[email]]', ''])
			->column();

		$new = 0;
		$returning = 0;

		if (! empty($customerEmails)) {
			// Find emails whose first-ever completed order is within this period
			$firstOrderQuery = (new Query())
				->select([
					'email' => '[[email]]',
					'firstOrder' => 'MIN([[dateOrdered]])',
				])
				->from(CommerceTable::ORDERS)
				->where([
					'and',
					['=', '[[isCompleted]]', true],
					['in', '[[email]]', $customerEmails],
				])
				->groupBy('[[email]]');

			$new = (int) (new Query())
				->from([
					'firstOrders' => $firstOrderQuery,
				])
				->andWhere(['>=', 'firstOrder', $scope->fromDT])
				->andWhere(['<=', 'firstOrder', $scope->toDT])
				->count();

			$returning = max(0, $total - $new);
		}

		$repeatRate = $total > 0 ? round(($returning / $total) * 100, 1) : 0;

		return new CustomerKpis([
			'total' => $total,
			'new' => $new,
			'returning' => $returning,
			'repeatRate' => $repeatRate,
		]);
	}

	/**
	 * Get new vs returning customer counts by day.
	 *
	 * @return array{labels: list<string>, new: list<int>, returning: list<int>}
	 */
	public function getNewVsReturningByDay(ReportScope $scope): array
	{
		$db = Craft::$app->getDb();
		$isMysql = $db->getIsMysql();

		$dayExpression = $isMysql
			? 'DATE([[dateOrdered]])'
			: 'CAST([[dateOrdered]] AS DATE)';

		$dateCondition = $this->buildDateCondition($scope);

		// Get emails who ordered in the range
		$customerEmails = (new Query())
			->select('DISTINCT [[email]]')
			->from(CommerceTable::ORDERS)
			->where($dateCondition)
			->andWhere([
				'not', [
					'[[email]]' => null,
				]])
			->andWhere(['!=', '[[email]]', ''])
			->column();

		if (empty($customerEmails)) {
			return [
				'labels' => [],
				'new' => [],
				'returning' => [],
			];
		}

		// Get first-ever order date for these emails (across all time)
		$firstOrders = (new Query())
			->select([
				'email' => '[[email]]',
				'firstOrder' => 'MIN([[dateOrdered]])',
			])
			->from(CommerceTable::ORDERS)
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				['in', '[[email]]', $customerEmails],
			])
			->groupBy('[[email]]')
			->all();

		$firstOrderMap = [];
		foreach ($firstOrders as $firstOrder) {
			/** @var array{email: string, firstOrder: string} $firstOrder */
			$firstOrderMap[$firstOrder['email']] = substr($firstOrder['firstOrder'], 0, 10);
		}

		// Get all orders in the range grouped by day and email
		$orders = (new Query())
			->select([
				'day' => $dayExpression,
				'email' => '[[email]]',
			])
			->from(CommerceTable::ORDERS)
			->where($dateCondition)
			->andWhere([
				'not', [
					'[[email]]' => null,
				]])
			->andWhere(['!=', '[[email]]', ''])
			->all();

		// Group by day
		$byDay = [];
		/** @var array{day: string, email: string} $order */
		foreach ($orders as $order) {
			$day = $order['day'];
			$email = $order['email'];
			if (! isset($byDay[$day])) {
				$byDay[$day] = [
					'new' => [],
					'returning' => [],
				];
			}

			$isNew = isset($firstOrderMap[$email]) && $firstOrderMap[$email] === $day;
			if ($isNew) {
				$byDay[$day]['new'][$email] = true;
			} else {
				$byDay[$day]['returning'][$email] = true;
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
	 * @return list<CustomerRow>
	 */
	public function getTopCustomers(ReportScope $scope, int $limit = 100): array
	{
		$query = (new Query())
			->select([
				'email' => '[[orders.email]]',
				'customerId' => '[[orders.customerId]]',
				'userActive' => 'MAX([[users.active]])',
				'orderCount' => 'COUNT(*)',
				'totalSpent' => 'COALESCE(SUM([[orders.totalPrice]]), 0)',
				'lastOrder' => 'MAX([[orders.dateOrdered]])',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->leftJoin(
				[
					'users' => CraftTable::USERS,
				],
				'[[orders.customerId]] = [[users.id]]',
			)
			->where($this->buildDateCondition($scope, 'orders'))
			->groupBy('[[orders.email]], [[orders.customerId]]')
			->orderBy([
				'totalSpent' => SORT_DESC,
			])
			->limit($limit);

		$rows = $query->all();

		return array_map(function ($row): CustomerRow {
			/** @var array{email: string, customerId: ?string, userActive: ?string, orderCount: string, totalSpent: string, lastOrder: ?string} $row */
			$orderCount = (int) $row['orderCount'];
			$totalSpent = (float) $row['totalSpent'];
			$customerId = $row['customerId'] ? (int) $row['customerId'] : null;
			$isActive = (bool) ($row['userActive'] ?? false);
			$isGuest = $customerId === null || ! $isActive;

			return new CustomerRow([
				'email' => $row['email'],
				'customerId' => $customerId,
				'isGuest' => $isGuest,
				'status' => $isGuest ? 'guest' : 'credentialed',
				'orderCount' => $orderCount,
				'totalSpent' => $totalSpent,
				'aov' => $orderCount > 0 ? round($totalSpent / $orderCount, 2) : 0,
				'lastOrder' => $row['lastOrder'],
			]);
		}, $rows);
	}

	/**
	 * Get top shipping locations by customer count.
	 *
	 * @return array<int, array{country: string, state: string, count: int}>
	 */
	public function getTopShippingLocations(ReportScope $scope, int $limit = 10): array
	{
		/** @var array<int, array{country: string, state: string, count: string}> $rows */
		$rows = (new Query())
			->select([
				'country' => '[[addresses.countryCode]]',
				'state' => "COALESCE([[addresses.administrativeArea]], '')",
				'count' => 'COUNT(DISTINCT [[orders.email]])',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->innerJoin([
				'addresses' => CraftTable::ADDRESSES,
			], '[[orders.shippingAddressId]] = [[addresses.id]]')
			->where($this->buildDateCondition($scope, 'orders'))
			->andWhere([
				'not', [
					'[[addresses.countryCode]]' => null,
				]])
			->groupBy('[[addresses.countryCode]], [[addresses.administrativeArea]]')
			->orderBy([
				'count' => SORT_DESC,
			])
			->limit($limit)
			->all();

		return array_map(fn (array $row): array => [
			'country' => $row['country'],
			'state' => $row['state'],
			'count' => (int) $row['count'],
		], $rows);
	}

	/**
	 * Get LTV comparison between credentialed and guest customers.
	 */
	public function getLtvComparison(ReportScope $scope): LtvComparison
	{
		$dateCondition = $this->buildDateCondition($scope, 'orders');
		$dateCondition[] = [
			'not', [
				'[[orders.email]]' => null,
			]];
		$dateCondition[] = ['!=', '[[orders.email]]', ''];

		// Credentialed: has an active user account
		$credentialedRows = (new Query())
			->select([
				'totalSpent' => 'COALESCE(SUM([[orders.totalPrice]]), 0)',
				'orderCount' => 'COUNT(*)',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->innerJoin(
				[
					'users' => CraftTable::USERS,
				],
				'[[orders.customerId]] = [[users.id]]',
			)
			->where($dateCondition)
			->andWhere([
				'[[users.active]]' => true,
			])
			->groupBy('[[orders.email]]')
			->all();

		// Guest: no customerId OR inactive user
		$guestRows = (new Query())
			->select([
				'totalSpent' => 'COALESCE(SUM([[orders.totalPrice]]), 0)',
				'orderCount' => 'COUNT(*)',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->leftJoin(
				[
					'users' => CraftTable::USERS,
				],
				'[[orders.customerId]] = [[users.id]]',
			)
			->where($dateCondition)
			->andWhere([
				'or',
				[
					'[[orders.customerId]]' => null,
				],
				[
					'[[users.active]]' => false,
				],
				[
					'[[users.id]]' => null,
				],
			])
			->groupBy('[[orders.email]]')
			->all();

		$credentialedCount = count($credentialedRows);
		$credentialedRevenue = array_sum(array_column($credentialedRows, 'totalSpent'));
		$credentialedOrders = array_sum(array_column($credentialedRows, 'orderCount'));

		$guestCount = count($guestRows);
		$guestRevenue = array_sum(array_column($guestRows, 'totalSpent'));
		$guestOrders = array_sum(array_column($guestRows, 'orderCount'));

		return new LtvComparison([
			'credentialed' => new LtvSegment([
				'count' => $credentialedCount,
				'totalRevenue' => (float) $credentialedRevenue,
				'avgLtv' => $credentialedCount > 0 ? round($credentialedRevenue / $credentialedCount, 2) : 0,
				'avgOrders' => $credentialedCount > 0 ? round($credentialedOrders / $credentialedCount, 2) : 0,
			]),
			'guest' => new LtvSegment([
				'count' => $guestCount,
				'totalRevenue' => (float) $guestRevenue,
				'avgLtv' => $guestCount > 0 ? round($guestRevenue / $guestCount, 2) : 0,
				'avgOrders' => $guestCount > 0 ? round($guestOrders / $guestCount, 2) : 0,
			]),
		]);
	}

	/**
	 * Build a standard date + status condition for commerce_orders queries.
	 *
	 * @return list<mixed>
	 */
	private function buildDateCondition(ReportScope $scope, string $tableAlias = ''): array
	{
		$prefix = $tableAlias !== '' ? $tableAlias . '.' : '';

		$condition = [
			'and',
			['=', "[[{$prefix}isCompleted]]", true],
			['>=', "[[{$prefix}dateOrdered]]", $scope->fromDT],
			['<=', "[[{$prefix}dateOrdered]]", $scope->toDT],
		];

		$statusCondition = $scope->statusCondition($tableAlias);
		if ($statusCondition !== null) {
			$condition[] = $statusCondition;
		}

		return $condition;
	}
}
