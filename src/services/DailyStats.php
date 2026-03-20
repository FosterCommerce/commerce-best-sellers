<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\db\Query;
use fostercommerce\bestsellers\records\DailyStat;
use yii\base\Component;

class DailyStats extends Component
{
	/**
	 * Aggregate stats for a single day (idempotent upsert).
	 */
	public function aggregateDay(string $date): void
	{
		$db = Craft::$app->getDb();
		$isMysql = $db->getIsMysql();

		$dateStart = $date . ' 00:00:00';
		$dateEnd = $date . ' 23:59:59';

		$dateCondition = [
			'and',
			['=', 'isCompleted', true],
			['>=', 'dateOrdered', $dateStart],
			['<=', 'dateOrdered', $dateEnd],
		];

		// Order-level aggregates
		$orderStats = (new Query())
			->select([
				'totalOrders' => 'COUNT(*)',
				'totalRevenue' => 'COALESCE(SUM([[totalPrice]]), 0)',
				'totalDiscount' => 'COALESCE(SUM([[totalDiscount]]), 0)',
				'totalShipping' => 'COALESCE(SUM([[totalShippingCost]]), 0)',
				'totalTax' => 'COALESCE(SUM([[totalTax]]), 0)',
				'uniqueCustomers' => 'COUNT(DISTINCT [[customerId]])',
			])
			->from('{{%commerce_orders}}')
			->where($dateCondition)
			->one();

		$totalOrders = (int) ($orderStats['totalOrders'] ?? 0);
		$totalRevenue = (float) ($orderStats['totalRevenue'] ?? 0);
		$totalDiscount = (float) ($orderStats['totalDiscount'] ?? 0);
		$totalShipping = (float) ($orderStats['totalShipping'] ?? 0);
		$totalTax = (float) ($orderStats['totalTax'] ?? 0);
		$uniqueCustomers = (int) ($orderStats['uniqueCustomers'] ?? 0);

		// Items sold
		$totalItemsSold = (int) (new Query())
			->select(['COALESCE(SUM(li.[[qty]]), 0)'])
			->from(['li' => '{{%commerce_lineitems}}'])
			->innerJoin(['o' => '{{%commerce_orders}}'], 'li.[[orderId]] = o.[[id]]')
			->where($dateCondition)
			->scalar();

		// New vs returning customers
		$customerIds = (new Query())
			->select('[[customerId]]')
			->from('{{%commerce_orders}}')
			->where($dateCondition)
			->andWhere(['not', ['customerId' => null]])
			->column();

		$newCustomers = 0;
		$returningCustomers = 0;

		if (! empty($customerIds)) {
			// A customer is "new" if their earliest completed order is on this date
			$newCustomers = (int) (new Query())
				->select('COUNT(DISTINCT [[customerId]])')
				->from('{{%commerce_orders}}')
				->where([
					'and',
					['=', 'isCompleted', true],
					['in', 'customerId', $customerIds],
				])
				->groupBy('[[customerId]]')
				->having(['>=', 'MIN([[dateOrdered]])', $dateStart])
				->andHaving(['<=', 'MIN([[dateOrdered]])', $dateEnd])
				->count();

			// Actually we need to count how many customers have their min dateOrdered in this day
			$newCustomers = (int) (new Query())
				->from([
					'sub' => (new Query())
						->select([
							'customerId',
							'firstOrder' => 'MIN([[dateOrdered]])',
						])
						->from('{{%commerce_orders}}')
						->where([
							'and',
							['=', 'isCompleted', true],
							['in', 'customerId', $customerIds],
						])
						->groupBy('[[customerId]]'),
				])
				->andWhere(['>=', 'firstOrder', $dateStart])
				->andWhere(['<=', 'firstOrder', $dateEnd])
				->count();

			$returningCustomers = $uniqueCustomers - $newCustomers;
			if ($returningCustomers < 0) {
				$returningCustomers = 0;
			}
		}

		$averageOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 4) : 0;
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
		$existing = DailyStat::find()->where(['date' => $date])->one();

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
		$current = new \DateTime($startDate);
		$end = new \DateTime($endDate);
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
	 *
	 * @return array<string, mixed>
	 */
	public function getStatsForRange(string $fromDate, string $toDate): array
	{
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
			->from(DailyStat::tableName())
			->where(['>=', 'date', $fromDate])
			->andWhere(['<=', 'date', $toDate])
			->one();

		$totalOrders = (int) ($row['totalOrders'] ?? 0);
		$totalRevenue = (float) ($row['totalRevenue'] ?? 0);
		$totalItemsSold = (int) ($row['totalItemsSold'] ?? 0);

		return [
			'totalOrders' => $totalOrders,
			'totalRevenue' => $totalRevenue,
			'totalDiscount' => (float) ($row['totalDiscount'] ?? 0),
			'totalShipping' => (float) ($row['totalShipping'] ?? 0),
			'totalTax' => (float) ($row['totalTax'] ?? 0),
			'totalItemsSold' => $totalItemsSold,
			'uniqueCustomers' => (int) ($row['uniqueCustomers'] ?? 0),
			'newCustomers' => (int) ($row['newCustomers'] ?? 0),
			'returningCustomers' => (int) ($row['returningCustomers'] ?? 0),
			'averageOrderValue' => $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0,
			'averageItemsPerOrder' => $totalOrders > 0 ? round($totalItemsSold / $totalOrders, 2) : 0,
		];
	}

	/**
	 * Get daily stats rows for charts.
	 *
	 * @return array<int, array<string, mixed>>
	 */
	public function getDailyRows(string $fromDate, string $toDate): array
	{
		return (new Query())
			->from(DailyStat::tableName())
			->where(['>=', 'date', $fromDate])
			->andWhere(['<=', 'date', $toDate])
			->orderBy(['date' => SORT_ASC])
			->all();
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
			->from(DailyStat::tableName())
			->where(['>=', 'date', $fromDate])
			->andWhere(['<=', 'date', $toDate])
			->orderBy(['date' => SORT_ASC])
			->column();
	}
}
