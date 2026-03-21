<?php

namespace fostercommerce\bestsellers\console\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Queue as QueueHelper;
use DateTime;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\jobs\RebuildDailyStatsJob;
use fostercommerce\bestsellers\records\VariantSale;
use yii\console\Controller;
use yii\console\ExitCode;

/**
 * @extends Controller<\yii\base\Module>
 */
class BackfillController extends Controller
{
	/**
	 * Backfill previous orders in batches of 25.
	 *
	 * Run with: ./craft best-sellers/backfill
	 */
	public function actionIndex(int $batchSize = 25): int
	{
		// Get IDs of processed orders.
		$processedOrderIds = VariantSale::find()
			->select('orderId')
			->column();

		// Count orders that are completed and not yet processed.
		$totalOrders = Order::find()
			->isCompleted(true)
			->andWhere(['not in', 'id', $processedOrderIds])
			->count();

		for ($offset = 0; $offset < $totalOrders; $offset += $batchSize) {
			Craft::$app->queue->push(new BackfillOrdersJob([
				'offset' => $offset,
				'limit' => $batchSize,
			]));
			$this->stdout("Queued orders offset {$offset} to " . ($offset + $batchSize - 1) . "\n");
		}

		$this->stdout("Queued {$totalOrders} orders for backfill.\n");
		return ExitCode::OK;
	}

	/**
	 * Rebuild daily stats table from commerce_orders.
	 *
	 * Run with: ./craft best-sellers/backfill/daily-stats
	 */
	public function actionDailyStats(): int
	{
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
}
