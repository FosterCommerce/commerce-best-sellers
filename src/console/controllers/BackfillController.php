<?php
namespace fostercommerce\bestsellers\console\controllers;

use Craft;
use yii\console\Controller;
use craft\commerce\elements\Order;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\records\VariantSale;

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
		return 0;
	}
}
