<?php

namespace fostercommerce\bestsellers\services;

use craft\db\Query;
use DateTime;
use fostercommerce\bestsellers\models\AbandonmentStats;
use fostercommerce\bestsellers\models\AgeBucket;
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
	 * Get cart abandonment stats for the Overview widget.
	 *
	 * An abandoned cart is any incomplete order with line items, older than 4 hours.
	 */
	public function getAbandonmentStats(string $fromDT, string $toDT): AbandonmentStats
	{
		$cutoff = (new DateTime())->modify('-4 hours')->format('Y-m-d H:i:s');

		// Abandoned carts: incomplete orders with line items, older than 4 hours
		$abandonedCarts = (new Query())
			->select([
				'orders.[[id]]',
				'orders.[[totalPrice]]',
				'orders.[[customerId]]',
				'orders.[[email]]',
				'orders.[[dateUpdated]]',
			])
			->from([
				'orders' => '{{%commerce_orders}}',
			])
			->innerJoin(
				[
					'lineItemCheck' => (new Query())
						->select('DISTINCT [[orderId]]')
						->from('{{%commerce_lineitems}}'),
				],
				'[[lineItemCheck.orderId]] = [[orders.id]]'
			)
			->where([
				'and',
				['=', '[[orders.isCompleted]]', false],
				['>=', '[[orders.dateUpdated]]', $fromDT],
				['<=', '[[orders.dateUpdated]]', $toDT],
				['<=', '[[orders.dateUpdated]]', $cutoff],
			])
			->all();

		// Completed orders in the same period (for rate calculation)
		$totalCompleted = (int) (new Query())
			->select('COUNT(*)')
			->from('{{%commerce_orders}}')
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				['>=', '[[dateOrdered]]', $fromDT],
				['<=', '[[dateOrdered]]', $toDT],
			])
			->scalar();

		$completedValue = (float) (new Query())
			->select(new Expression('COALESCE(SUM([[totalPrice]]), 0)'))
			->from('{{%commerce_orders}}')
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				['>=', '[[dateOrdered]]', $fromDT],
				['<=', '[[dateOrdered]]', $toDT],
			])
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
