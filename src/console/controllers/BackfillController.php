<?php

namespace fostercommerce\bestsellers\console\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Queue as QueueHelper;
use DateTime;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\NotTrashed;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\jobs\RebuildDailyStatsJob;
use fostercommerce\bestsellers\Plugin;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * @extends Controller<\yii\base\Module>
 */
class BackfillController extends Controller
{
	public ?string $startDate = null;

	public ?string $endDate = null;

	public ?string $date = null;

	public function options($actionID): array
	{
		$options = parent::options($actionID);
		if ($actionID === 'index' || $actionID === 'refresh-orders') {
			$options[] = 'startDate';
			$options[] = 'endDate';
		}

		if ($actionID === 'daily-stats' || $actionID === 'refresh-daily-stats') {
			$options[] = 'date';
		}

		return $options;
	}

	/**
	 * Backfill previous orders in batches of 25.
	 *
	 * Run with: ./craft best-sellers/backfill
	 *           ./craft best-sellers/backfill --start-date=2025-01-01 --end-date=2025-12-31
	 */
	public function actionIndex(int $batchSize = 25): int
	{
		return $this->_queueOrders($batchSize);
	}

	/**
	 * Clear and reprocess the variant sales table.
	 *
	 * Run with: ./craft best-sellers/backfill/refresh-orders
	 *           ./craft best-sellers/backfill/refresh-orders --start-date=2025-01-01 --end-date=2025-12-31
	 */
	public function actionRefreshOrders(int $batchSize = 25): int
	{
		$deleteQuery = Craft::$app->db->createCommand();
		if ($this->startDate && $this->endDate) {
			$deleteQuery->delete(Table::VARIANT_SALES, [
				'between', 'dateOrdered', $this->startDate, $this->endDate . ' 23:59:59',
			]);
			$this->stdout("Cleared variant sales for {$this->startDate} to {$this->endDate}.\n");
		} else {
			$deleteQuery->truncateTable(Table::VARIANT_SALES);
			$this->stdout("Cleared all variant sales.\n");
		}

		$deleteQuery->execute();

		return $this->_queueOrders($batchSize);
	}

	/**
	 * Rebuild daily stats table from commerce_orders.
	 *
	 * Pass --date=YYYY-MM-DD to rebuild a single day.
	 *
	 * Run with: ./craft best-sellers/backfill/daily-stats
	 *           ./craft best-sellers/backfill/daily-stats --date=2026-03-15
	 */
	public function actionDailyStats(): int
	{
		if ($this->date !== null) {
			$plugin = Plugin::getInstance();
			$plugin->dailyStats->aggregateDay($this->date);
			$this->stdout("Daily stats rebuilt for {$this->date}.\n");
			return ExitCode::OK;
		}

		return $this->_rebuildDailyStats();
	}

	/**
	 * Clear and rebuild the daily stats table.
	 *
	 * Pass --date=YYYY-MM-DD to clear and rebuild a single day.
	 *
	 * Run with: ./craft best-sellers/backfill/refresh-daily-stats
	 *           ./craft best-sellers/backfill/refresh-daily-stats --date=2026-03-15
	 */
	public function actionRefreshDailyStats(): int
	{
		if ($this->date !== null) {
			Craft::$app->db->createCommand()
				->delete(Table::DAILY_STATS, [
					'date' => $this->date,
				])
				->execute();
			$this->stdout("Cleared daily stats for {$this->date}.\n");

			$plugin = Plugin::getInstance();
			$plugin->dailyStats->aggregateDay($this->date);
			$this->stdout("Daily stats rebuilt for {$this->date}.\n");
			return ExitCode::OK;
		}

		Craft::$app->db->createCommand()
			->truncateTable(Table::DAILY_STATS)
			->execute();
		$this->stdout("Cleared all daily stats.\n");

		return $this->_rebuildDailyStats();
	}

	/**
	 * Clear the variant sales table.
	 *
	 * Run with: ./craft best-sellers/backfill/clear-orders
	 */
	public function actionClearOrders(): int
	{
		Craft::$app->db->createCommand()
			->truncateTable(Table::VARIANT_SALES)
			->execute();
		$this->stdout("Cleared all variant sales.\n");

		return ExitCode::OK;
	}

	/**
	 * Clear the daily stats table.
	 *
	 * Run with: ./craft best-sellers/backfill/clear-daily-stats
	 */
	public function actionClearDailyStats(): int
	{
		Craft::$app->db->createCommand()
			->truncateTable(Table::DAILY_STATS)
			->execute();
		$this->stdout("Cleared all daily stats.\n");

		return ExitCode::OK;
	}

	/**
	 * Clear all backfill log entries.
	 *
	 * Run with: ./craft best-sellers/backfill/clear-logs
	 */
	public function actionClearLogs(): int
	{
		$count = Plugin::getInstance()->backfillLogs->deleteAll();
		$this->stdout("Cleared {$count} log entries.\n");

		return ExitCode::OK;
	}

	private function _queueOrders(int $batchSize): int
	{
		// Already-processed orders are short-circuited inside Sales::logOrderSales,
		// so the offset/limit pagination is stable across job runs.
		$query = Order::find()
			->isCompleted(true);

		if ($this->startDate && $this->endDate) {
			$query->andWhere(['between', 'dateOrdered', $this->startDate, $this->endDate]);
		}

		$totalOrders = $query->count();

		for ($offset = 0; $offset < $totalOrders; $offset += $batchSize) {
			Craft::$app->queue->push(new BackfillOrdersJob([
				'offset' => $offset,
				'limit' => $batchSize,
				'startDate' => $this->startDate,
				'endDate' => $this->endDate,
			]));
			$this->stdout("Queued orders offset {$offset} to " . ($offset + $batchSize - 1) . "\n");
		}

		$this->stdout("Queued {$totalOrders} orders for backfill.\n");
		return ExitCode::OK;
	}

	private function _rebuildDailyStats(): int
	{
		$rangeQuery = (new Query())
			->select([
				'minDate' => 'MIN([[orders.dateOrdered]])',
				'maxDate' => 'MAX([[orders.dateOrdered]])',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->where(['=', '[[orders.isCompleted]]', true]);

		/** @var array{minDate: ?string, maxDate: ?string}|false $row */
		$row = NotTrashed::join($rangeQuery, 'orders')->one();

		if (! $row || ! $row['minDate']) {
			$this->stdout("No completed orders found.\n");
			return ExitCode::OK;
		}

		$startDate = (new DateTime((string) $row['minDate']))->format('Y-m-d');
		$endDate = (new DateTime((string) $row['maxDate']))->format('Y-m-d');

		QueueHelper::push(new RebuildDailyStatsJob([
			'startDate' => $startDate,
			'endDate' => $endDate,
		]));

		$this->stdout("Daily stats rebuild queued for {$startDate} to {$endDate}.\n");
		return ExitCode::OK;
	}
}
