<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\web\Controller;
use craft\web\Request;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;
use yii\web\Response;

class BackfillController extends Controller
{
	/**
	 * @var int
	 */
	public const BATCH_SIZE = 25;

	protected array|int|bool $allowAnonymous = false;

	public function actionIndex(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		// Get date range from form POST data.
		$startDate = $request->getBodyParam('startDate'); // YYYY-MM-DD
		$endDate = $request->getBodyParam('endDate');   // YYYY-MM-DD

		// Get processed order IDs.
		$processedOrderIds = VariantSale::find()
			->select('orderId')
			->column();

		// Build query filtering by isCompleted and date range.
		$query = Order::find()
			->isCompleted(true)
			->andWhere(['not in', 'id', $processedOrderIds]);

		// If both dates are provided, filter orders between them.
		if ($startDate && $endDate) {
			$query->andWhere(['between', 'dateOrdered', $startDate, $endDate]);
		} else {
			// Otherwise, default to orders ordered before now.
			$query->andWhere(['<', 'dateOrdered', (new \DateTime())->format('Y-m-d H:i:s')]);
		}

		$totalOrders = $query->count();

		// Queue jobs in batches, passing the date range.
		for ($offset = 0; $offset < $totalOrders; $offset += self::BATCH_SIZE) {
			Craft::$app->queue->push(new BackfillOrdersJob([
				'offset' => $offset,
				'limit' => self::BATCH_SIZE,
				'startDate' => $startDate,
				'endDate' => $endDate,
			]));
		}

		Craft::$app->session->setNotice('Backfill queued for ' . $totalOrders . ' orders.');
		return $this->redirectToPostedUrl();
	}

	public function actionRebuildDailyStats(): Response
	{
		$dailyStats = Plugin::getInstance()?->dailyStats;
		if (! $dailyStats) {
			Craft::$app->session->setError('DailyStats service not available.');
			return $this->redirectToPostedUrl();
		}

		$row = (new Query())
			->select([
				'minDate' => 'MIN([[dateOrdered]])',
				'maxDate' => 'MAX([[dateOrdered]])',
			])
			->from('{{%commerce_orders}}')
			->where(['=', 'isCompleted', true])
			->one();

		if (! $row || ! $row['minDate']) {
			Craft::$app->session->setNotice('No completed orders found.');
			return $this->redirectToPostedUrl();
		}

		$startDate = (new \DateTime($row['minDate']))->format('Y-m-d');
		$endDate = (new \DateTime($row['maxDate']))->format('Y-m-d');

		$count = $dailyStats->rebuildRange($startDate, $endDate);

		Craft::$app->session->setNotice("Rebuilt {$count} daily stat records.");
		return $this->redirectToPostedUrl();
	}
}
