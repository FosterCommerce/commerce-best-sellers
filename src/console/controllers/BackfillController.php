<?php

namespace fostercommerce\bestsellers\console\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Queue as QueueHelper;
use DateTime;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\jobs\RebuildDailyStatsJob;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;
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

	public bool $fresh = false;

	public function options($actionID): array
	{
		$options = parent::options($actionID);
		if ($actionID === 'index') {
			$options[] = 'startDate';
			$options[] = 'endDate';
			$options[] = 'fresh';
		}

		if ($actionID === 'daily-stats') {
			$options[] = 'date';
			$options[] = 'fresh';
		}

		return $options;
	}

	/**
	 * Backfill previous orders in batches of 25.
	 *
	 * Run with: ./craft best-sellers/backfill
	 *           ./craft best-sellers/backfill --start-date=2025-01-01 --end-date=2025-12-31
	 *           ./craft best-sellers/backfill --fresh
	 */
	public function actionIndex(int $batchSize = 25): int
	{
		if ($this->fresh) {
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
		}

		// Get IDs of processed orders.
		$processedOrderIds = VariantSale::find()
			->select('orderId')
			->column();

		// Count orders that are completed and not yet processed.
		$query = Order::find()
			->isCompleted(true)
			->andWhere(['not in', 'id', $processedOrderIds]);

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

	/**
	 * Rebuild daily stats table from commerce_orders.
	 *
	 * Pass --date=YYYY-MM-DD to rebuild a single day.
	 * Pass --fresh to truncate the table before rebuilding.
	 * Without --date, rebuilds the entire range.
	 *
	 * Run with: ./craft best-sellers/backfill/daily-stats
	 *           ./craft best-sellers/backfill/daily-stats --date=2026-03-15
	 *           ./craft best-sellers/backfill/daily-stats --fresh
	 */
	public function actionDailyStats(): int
	{
		if ($this->date !== null) {
			$plugin = Plugin::getInstance();
			$plugin->dailyStats->aggregateDay($this->date);
			$this->stdout("Daily stats rebuilt for {$this->date}.\n");
			return ExitCode::OK;
		}

		if ($this->fresh) {
			Craft::$app->db->createCommand()
				->truncateTable(Table::DAILY_STATS)
				->execute();
			$this->stdout("Cleared all daily stats.\n");
		}

		/** @var array{minDate: ?string, maxDate: ?string}|false $row */
		$row = (new Query())
			->select([
				'minDate' => 'MIN([[dateOrdered]])',
				'maxDate' => 'MAX([[dateOrdered]])',
			])
			->from(CommerceTable::ORDERS)
			->where(['=', 'isCompleted', true])
			->one();

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

	/**
	 * Clear all backfill log entries.
	 *
	 * Run with: ./craft best-sellers/backfill/clear-logs
	 */
	public function actionClearLogs(): int
	{
		$plugin = Plugin::getInstance();
		$count = $plugin->backfillLogs->deleteAll();
		$this->stdout("Cleared {$count} log entries.\n");

		return ExitCode::OK;
	}
}
