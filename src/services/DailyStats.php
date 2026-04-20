<?php

namespace fostercommerce\bestsellers\services;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use DateTime;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\MoneyMath;
use fostercommerce\bestsellers\models\PeriodStats;
use fostercommerce\bestsellers\records\DailyStat;
use yii\base\Component;

class DailyStats extends Component
{
	/**
	 * Aggregate stats for a single day (idempotent upsert).
	 */
	public function aggregateDay(string $date): void
	{
		$dateStart = $date . ' 00:00:00';
		$dateEnd = $date . ' 23:59:59';

		$dateCondition = [
			'and',
			['=', '[[isCompleted]]', true],
			['>=', '[[dateOrdered]]', $dateStart],
			['<=', '[[dateOrdered]]', $dateEnd],
		];

		// Order-level aggregates
		/** @var array{totalOrders: string, totalRevenue: string, totalDiscount: string, totalShipping: string, totalTax: string, uniqueCustomers: string}|false $orderStats */
		$orderStats = (new Query())
			->select([
				'totalOrders' => 'COUNT(*)',
				'totalRevenue' => 'COALESCE(SUM([[totalPrice]]), 0)',
				'totalDiscount' => 'COALESCE(SUM([[totalDiscount]]), 0)',
				'totalShipping' => 'COALESCE(SUM([[totalShippingCost]]), 0)',
				'totalTax' => 'COALESCE(SUM([[totalTax]]), 0)',
				'uniqueCustomers' => 'COUNT(DISTINCT [[email]])',
			])
			->from(CommerceTable::ORDERS)
			->where($dateCondition)
			->one();

		$totalOrders = (int) ($orderStats['totalOrders'] ?? 0);
		$totalRevenue = (float) ($orderStats['totalRevenue'] ?? 0);
		$totalDiscount = (float) ($orderStats['totalDiscount'] ?? 0);
		$totalShipping = (float) ($orderStats['totalShipping'] ?? 0);
		$totalTax = (float) ($orderStats['totalTax'] ?? 0);
		$uniqueCustomers = (int) ($orderStats['uniqueCustomers'] ?? 0);

		// Items sold (JOIN query, must qualify columns)
		$totalItemsSold = (int) (new Query())
			->select(['COALESCE(SUM([[lineItems.qty]]), 0)'])
			->from([
				'lineItems' => CommerceTable::LINEITEMS,
			])
			->innerJoin([
				'orders' => CommerceTable::ORDERS,
			], '[[lineItems.orderId]] = [[orders.id]]')
			->where([
				'and',
				['=', '[[orders.isCompleted]]', true],
				['>=', '[[orders.dateOrdered]]', $dateStart],
				['<=', '[[orders.dateOrdered]]', $dateEnd],
			])
			->scalar();

		// New vs returning customers (tracked by email across all time)
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

		$newCustomers = 0;
		$returningCustomers = 0;

		if (! empty($customerEmails)) {
			$newCustomers = (int) (new Query())
				->from([
					'firstOrders' => (new Query())
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
						->groupBy('[[email]]'),
				])
				->andWhere(['>=', 'firstOrder', $dateStart])
				->andWhere(['<=', 'firstOrder', $dateEnd])
				->count();

			$returningCustomers = max(0, $uniqueCustomers - $newCustomers);
		}

		$averageOrderValue = MoneyMath::toFloat(MoneyMath::average($totalRevenue, $totalOrders));
		$averageItemsPerOrder = $totalOrders > 0 ? round($totalItemsSold / $totalOrders, 2) : 0;

		$row = [
			'date' => $date,
			'totalOrders' => $totalOrders,
			'totalRevenue' => $totalRevenue,
			'totalDiscount' => $totalDiscount,
			'totalShipping' => $totalShipping,
			'totalTax' => $totalTax,
			'totalItemsSold' => $totalItemsSold,
			'uniqueCustomers' => $uniqueCustomers,
			'newCustomers' => $newCustomers,
			'returningCustomers' => $returningCustomers,
			'averageOrderValue' => $averageOrderValue,
			'averageItemsPerOrder' => $averageItemsPerOrder,
		];

		// Idempotent upsert
		/** @var DailyStat|null $existing */
		$existing = DailyStat::find()->where([
			'date' => $date,
		])->one();

		if ($existing) {
			$existing->setAttributes($row, false);
			$existing->save(false);
		} else {
			$record = new DailyStat();
			$record->setAttributes($row, false);
			$record->save(false);
		}
	}

	/**
	 * Rebuild daily stats for a date range.
	 */
	public function rebuildRange(string $startDate, string $endDate): int
	{
		$current = new DateTime($startDate);
		$end = new DateTime($endDate);
		$count = 0;

		while ($current <= $end) {
			$this->aggregateDay($current->format('Y-m-d'));
			$current->modify('+1 day');
			$count++;
		}

		return $count;
	}

	/**
	 * Get aggregated stats for a date range from the daily_stats table.
	 */
	public function getStatsForRange(string $fromDate, string $toDate): PeriodStats
	{
		/** @var array{totalOrders: string, totalRevenue: string, totalDiscount: string, totalShipping: string, totalTax: string, totalItemsSold: string, uniqueCustomers: string, newCustomers: string, returningCustomers: string}|false $row */
		$row = (new Query())
			->select([
				'totalOrders' => 'COALESCE(SUM([[totalOrders]]), 0)',
				'totalRevenue' => 'COALESCE(SUM([[totalRevenue]]), 0)',
				'totalDiscount' => 'COALESCE(SUM([[totalDiscount]]), 0)',
				'totalShipping' => 'COALESCE(SUM([[totalShipping]]), 0)',
				'totalTax' => 'COALESCE(SUM([[totalTax]]), 0)',
				'totalItemsSold' => 'COALESCE(SUM([[totalItemsSold]]), 0)',
				'uniqueCustomers' => 'COALESCE(SUM([[uniqueCustomers]]), 0)',
				'newCustomers' => 'COALESCE(SUM([[newCustomers]]), 0)',
				'returningCustomers' => 'COALESCE(SUM([[returningCustomers]]), 0)',
			])
			->from(Table::DAILY_STATS)
			->where(['>=', '[[date]]', $fromDate])
			->andWhere(['<=', '[[date]]', $toDate])
			->one();

		$totalOrders = (int) ($row['totalOrders'] ?? 0);
		$totalRevenue = (float) ($row['totalRevenue'] ?? 0);
		$totalItemsSold = (int) ($row['totalItemsSold'] ?? 0);

		return new PeriodStats([
			'totalOrders' => $totalOrders,
			'totalRevenue' => $totalRevenue,
			'totalDiscount' => (float) ($row['totalDiscount'] ?? 0),
			'totalShipping' => (float) ($row['totalShipping'] ?? 0),
			'totalTax' => (float) ($row['totalTax'] ?? 0),
			'totalItemsSold' => $totalItemsSold,
			'uniqueCustomers' => (int) ($row['uniqueCustomers'] ?? 0),
			'newCustomers' => (int) ($row['newCustomers'] ?? 0),
			'returningCustomers' => (int) ($row['returningCustomers'] ?? 0),
			'averageOrderValue' => MoneyMath::toFloat(MoneyMath::average($totalRevenue, $totalOrders)),
			'averageItemsPerOrder' => $totalOrders > 0 ? round($totalItemsSold / $totalOrders, 2) : 0,
		]);
	}

	/**
	 * Get daily stats rows for charts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getDailyRows(string $fromDate, string $toDate): array
	{
		/** @var array<int, array<string, mixed>> $rows */
		$rows = (new Query())
			->from(Table::DAILY_STATS)
			->where(['>=', '[[date]]', $fromDate])
			->andWhere(['<=', '[[date]]', $toDate])
			->orderBy([
				'date' => SORT_ASC,
			])
			->all();

		return $rows;
	}

	/**
	 * Get sparkline data (array of values) for a specific column.
	 *
	 * @return array<int, float|int>
	 */
	public function getSparklineData(string $column, string $fromDate, string $toDate): array
	{
		return (new Query())
			->select($column)
			->from(Table::DAILY_STATS)
			->where(['>=', '[[date]]', $fromDate])
			->andWhere(['<=', '[[date]]', $toDate])
			->orderBy([
				'date' => SORT_ASC,
			])
			->column();
	}
}
