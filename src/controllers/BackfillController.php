<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\Queue as QueueHelper;
use craft\web\Controller;
use craft\web\Request;
use DateTime;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\jobs\RebuildDailyStatsJob;
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

	/**
	 * @param \yii\base\Action<static> $action
	 */
	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requirePermission(Plugin::PERMISSION_BACKFILL);

		return true;
	}

	public function actionIndex(): Response
	{
		$this->requirePostRequest();
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
			$query->andWhere(['<', 'dateOrdered', (new DateTime())->format('Y-m-d H:i:s')]);
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

		Craft::$app->session->setNotice(Craft::t('best-sellers', 'Backfill queued for {count} orders.', [
			'count' => $totalOrders,
		]));
		return $this->redirectToPostedUrl();
	}

	public function actionRebuildDailyStats(): Response
	{
		$this->requirePostRequest();

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
			Craft::$app->session->setNotice(Craft::t('best-sellers', 'No completed orders found.'));
			return $this->redirectToPostedUrl();
		}

		$startDate = (new DateTime((string) $row['minDate']))->format('Y-m-d');
		$endDate = (new DateTime((string) $row['maxDate']))->format('Y-m-d');

		QueueHelper::push(new RebuildDailyStatsJob([
			'startDate' => $startDate,
			'endDate' => $endDate,
		]));

		Craft::$app->session->setNotice(Craft::t('best-sellers', 'Daily stats rebuild queued.'));
		return $this->redirectToPostedUrl();
	}
}
