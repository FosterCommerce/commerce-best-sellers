<?php

namespace fostercommerce\bestsellers\console\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use DateTime;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\Plugin;
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
			echo "Queued orders offset {$offset} to " . ($offset + $batchSize - 1) . "\n";
		}

		echo "Queued {$totalOrders} orders for backfill.\n";
		return ExitCode::OK;
	}

	/**
	 * Rebuild daily stats table from commerce_orders.
	 *
	 * Run with: ./craft best-sellers/backfill/daily-stats
	 */
	public function actionDailyStats(): int
	{
		$dailyStats = Plugin::getInstance()?->dailyStats;
		if (! $dailyStats) {
			$this->stderr("DailyStats service not available.\n");
			return ExitCode::UNSPECIFIED_ERROR;
		}

		// Find the date range of completed orders
		/** @var array{minDate: ?string, maxDate: ?string}|false $row */
		$row = (new Query())
			->select([
				'minDate' => 'MIN([[dateOrdered]])',
				'maxDate' => 'MAX([[dateOrdered]])',
			])
			->from('{{%commerce_orders}}')
			->where(['=', 'isCompleted', true])
			->one();

		if (! $row || ! $row['minDate']) {
			$this->stdout("No completed orders found.\n");
			return ExitCode::OK;
		}

		$startDate = (new DateTime((string) $row['minDate']))->format('Y-m-d');
		$endDate = (new DateTime((string) $row['maxDate']))->format('Y-m-d');

		$this->stdout("Rebuilding daily stats from {$startDate} to {$endDate}...\n");

		$count = $dailyStats->rebuildRange($startDate, $endDate);

		$this->stdout("Rebuilt {$count} daily stat records.\n");
		return ExitCode::OK;
	}
}
