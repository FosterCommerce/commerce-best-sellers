<?php

namespace fostercommerce\bestsellers\services;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use DateTime;
use fostercommerce\bestsellers\models\AbandonmentStats;
use fostercommerce\bestsellers\models\AgeBucket;
use fostercommerce\bestsellers\models\ReportScope;
use yii\base\Component;
use yii\db\Expression;

class CartAbandonment extends Component
{
	/**
	 * Time buckets for abandoned cart age.
	 */
	private const AGE_BUCKETS = [
		'4-24h' => [
			'minHours' => 4,
			'maxHours' => 24,
		],
		'1-7d' => [
			'minHours' => 24,
			'maxHours' => 168,
		],
		'7d+' => [
			'minHours' => 168,
			'maxHours' => null,
		],
	];

	/**
	 * Get the highest-value abandoned carts.
	 *
	 * @return list<array{id: int, number: string, email: string, totalPrice: float, dateUpdated: string, hoursOld: float}>
	 */
	public function getTopAbandonedCarts(ReportScope $scope, int $limit = 5): array
	{
		$cutoff = (new DateTime())->modify('-4 hours')->format('Y-m-d H:i:s');

		/** @var list<array{id: string, number: string, email: string|null, totalPrice: string, dateUpdated: string}> $rows */
		$rows = (new Query())
			->select([
				'orders.[[id]]',
				'orders.[[number]]',
				'orders.[[email]]',
				'orders.[[totalPrice]]',
				'orders.[[dateUpdated]]',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->innerJoin(
				[
					'lineItemCheck' => (new Query())
						->select('DISTINCT [[orderId]]')
						->from(CommerceTable::LINEITEMS),
				],
				'[[lineItemCheck.orderId]] = [[orders.id]]'
			)
			->where([
				'and',
				['=', '[[orders.isCompleted]]', false],
				['>=', '[[orders.dateUpdated]]', $scope->fromDT],
				['<=', '[[orders.dateUpdated]]', $scope->toDT],
				['<=', '[[orders.dateUpdated]]', $cutoff],
			])
			->orderBy([
				'orders.[[totalPrice]]' => SORT_DESC,
			])
			->limit($limit)
			->all();

		$now = new DateTime();

		return array_map(function (array $row) use ($now): array {
			$updatedAt = new DateTime($row['dateUpdated']);
			$hoursOld = ($now->getTimestamp() - $updatedAt->getTimestamp()) / 3600;

			return [
				'id' => (int) $row['id'],
				'number' => $row['number'],
				'email' => $row['email'] ?? '',
				'totalPrice' => (float) $row['totalPrice'],
				'dateUpdated' => $row['dateUpdated'],
				'hoursOld' => round($hoursOld, 1),
			];
		}, $rows);
	}

	/**
	 * Get cart abandonment stats for the Overview widget.
	 *
	 * An abandoned cart is any incomplete order with line items, older than 4 hours.
	 */
	public function getAbandonmentStats(ReportScope $scope): AbandonmentStats
	{
		$cutoff = (new DateTime())->modify('-4 hours')->format('Y-m-d H:i:s');

		// Abandoned carts: incomplete orders with line items, older than 4 hours
		// Note: status filter does NOT apply to abandoned carts (they are incomplete)
		$abandonedCarts = (new Query())
			->select([
				'orders.[[id]]',
				'orders.[[totalPrice]]',
				'orders.[[customerId]]',
				'orders.[[email]]',
				'orders.[[dateUpdated]]',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->innerJoin(
				[
					'lineItemCheck' => (new Query())
						->select('DISTINCT [[orderId]]')
						->from(CommerceTable::LINEITEMS),
				],
				'[[lineItemCheck.orderId]] = [[orders.id]]'
			)
			->where([
				'and',
				['=', '[[orders.isCompleted]]', false],
				['>=', '[[orders.dateUpdated]]', $scope->fromDT],
				['<=', '[[orders.dateUpdated]]', $scope->toDT],
				['<=', '[[orders.dateUpdated]]', $cutoff],
			])
			->all();

		// Completed orders in the same period (for rate calculation)
		// Status filter applies here
		$completedCondition = [
			'and',
			['=', '[[isCompleted]]', true],
			['>=', '[[dateOrdered]]', $scope->fromDT],
			['<=', '[[dateOrdered]]', $scope->toDT],
		];
		$statusCondition = $scope->statusCondition();
		if ($statusCondition !== null) {
			$completedCondition[] = $statusCondition;
		}

		$totalCompleted = (int) (new Query())
			->select('COUNT(*)')
			->from(CommerceTable::ORDERS)
			->where($completedCondition)
			->scalar();

		$completedValue = (float) (new Query())
			->select(new Expression('COALESCE(SUM([[totalPrice]]), 0)'))
			->from(CommerceTable::ORDERS)
			->where($completedCondition)
			->scalar();

		$totalAbandoned = count($abandonedCarts);
		$abandonedValue = 0.0;
		$abandonedValueWithEmail = 0.0;
		$withCustomer = 0;
		$withoutCustomer = 0;

		$now = new DateTime();
		$byAgeData = [];
		foreach (self::AGE_BUCKETS as $label => $bucket) {
			$byAgeData[$label] = [
				'count' => 0,
				'value' => 0.0,
				'withCustomer' => 0,
				'valueWithEmail' => 0.0,
			];
		}

		foreach ($abandonedCarts as $abandonedCart) {
			/** @var array{totalPrice: string|float, customerId: int|null, email: string|null, dateUpdated: string} $abandonedCart */
			$cartValue = (float) $abandonedCart['totalPrice'];
			$abandonedValue += $cartValue;

			$hasCustomer = ! empty($abandonedCart['customerId']) || (! empty($abandonedCart['email']) && $abandonedCart['email'] !== '');
			if ($hasCustomer) {
				$withCustomer++;
				$abandonedValueWithEmail += $cartValue;
			} else {
				$withoutCustomer++;
			}

			$updatedAt = new DateTime($abandonedCart['dateUpdated']);
			$hoursOld = ($now->getTimestamp() - $updatedAt->getTimestamp()) / 3600;

			foreach (self::AGE_BUCKETS as $label => $bucket) {
				$minHours = $bucket['minHours'];
				$maxHours = $bucket['maxHours'];

				if ($hoursOld >= $minHours && ($maxHours === null || $hoursOld < $maxHours)) {
					$byAgeData[$label]['count']++;
					$byAgeData[$label]['value'] += $cartValue;
					if ($hasCustomer) {
						$byAgeData[$label]['withCustomer']++;
						$byAgeData[$label]['valueWithEmail'] += $cartValue;
					}

					break;
				}
			}
		}

		$byAgeBuckets = [];
		foreach ($byAgeData as $label => $data) {
			$byAgeBuckets[$label] = new AgeBucket([
				'count' => $data['count'],
				'value' => $data['value'],
				'withCustomer' => $data['withCustomer'],
				'valueWithEmail' => $data['valueWithEmail'],
			]);
		}

		$totalCartsCreated = $totalCompleted + $totalAbandoned;
		$abandonmentRate = $totalCartsCreated > 0
			? round(($totalAbandoned / $totalCartsCreated) * 100, 1)
			: 0;

		return new AbandonmentStats([
			'totalAbandoned' => $totalAbandoned,
			'totalCompleted' => $totalCompleted,
			'abandonmentRate' => $abandonmentRate,
			'abandonedValue' => $abandonedValue,
			'abandonedValueWithEmail' => $abandonedValueWithEmail,
			'completedValue' => $completedValue,
			'withCustomer' => $withCustomer,
			'withoutCustomer' => $withoutCustomer,
			'byAge' => $byAgeBuckets,
		]);
	}
}
