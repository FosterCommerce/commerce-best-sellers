<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Queue as QueueHelper;
use craft\web\Controller;
use craft\web\Request;
use DateTime;
use Exception;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\NotTrashed;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\jobs\RebuildDailyStatsJob;
use fostercommerce\bestsellers\Plugin;
use yii\base\Action;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\MethodNotAllowedHttpException;
use yii\web\Response;

class BackfillController extends Controller
{
	/**
	 * @var int
	 */
	public const BATCH_SIZE = 25;

	protected array|int|bool $allowAnonymous = false;

	/**
	 * @param Action<static> $action
	 * @throws ForbiddenHttpException
	 */
	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requirePermission(Plugin::PERMISSION_BACKFILL);

		return true;
	}

	/**
	 * @throws MethodNotAllowedHttpException
	 * @throws BadRequestHttpException
	 */
	public function actionIndex(): Response
	{
		$this->requirePostRequest();
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		// Craft date fields post arrays; convert to Y-m-d strings.
		/** @var array<string, string>|string|null $rawStart */
		$rawStart = $request->getBodyParam('startDate');
		/** @var array<string, string>|string|null $rawEnd */
		$rawEnd = $request->getBodyParam('endDate');
		$startDateTime = DateTimeHelper::toDateTime($rawStart);
		$endDateTime = DateTimeHelper::toDateTime($rawEnd);
		$startDate = $startDateTime ? $startDateTime->format('Y-m-d') : null;
		$endDate = $endDateTime ? $endDateTime->format('Y-m-d') : null;

		// Build query filtering by isCompleted and date range.
		// Already-processed orders are short-circuited inside Sales::logOrderSales,
		// so the offset/limit pagination is stable across job runs.
		$query = Order::find()
			->isCompleted(true);

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

	/**
	 * @throws BadRequestHttpException
	 * @throws MethodNotAllowedHttpException
	 * @throws Exception
	 */
	public function actionRebuildDailyStats(): Response
	{
		$this->requirePostRequest();

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

	/**
	 * @throws MethodNotAllowedHttpException
	 * @throws BadRequestHttpException
	 */
	public function actionClearOrders(): Response
	{
		$this->requirePostRequest();

		Craft::$app->db->createCommand()
			->truncateTable(Table::VARIANT_SALES)
			->execute();

		Craft::$app->session->setNotice(Craft::t('best-sellers', 'Variant sales cleared.'));
		return $this->redirectToPostedUrl();
	}

	/**
	 * @throws MethodNotAllowedHttpException
	 * @throws BadRequestHttpException
	 */
	public function actionClearDailyStats(): Response
	{
		$this->requirePostRequest();

		Craft::$app->db->createCommand()
			->truncateTable(Table::DAILY_STATS)
			->execute();

		Craft::$app->session->setNotice(Craft::t('best-sellers', 'Daily stats cleared.'));
		return $this->redirectToPostedUrl();
	}

	/**
	 * @throws MethodNotAllowedHttpException
	 * @throws BadRequestHttpException
	 * @throws Exception
	 */
	public function actionRefreshOrders(): Response
	{
		$this->requirePostRequest();

		Craft::$app->db->createCommand()
			->truncateTable(Table::VARIANT_SALES)
			->execute();

		return $this->actionIndex();
	}

	/**
	 * @throws MethodNotAllowedHttpException
	 * @throws BadRequestHttpException
	 * @throws Exception
	 */
	public function actionRefreshDailyStats(): Response
	{
		$this->requirePostRequest();

		Craft::$app->db->createCommand()
			->truncateTable(Table::DAILY_STATS)
			->execute();

		return $this->actionRebuildDailyStats();
	}
}
