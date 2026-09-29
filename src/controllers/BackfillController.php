<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\commerce\models\ProductType;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Queue as QueueHelper;
use craft\web\Controller;
use craft\web\Request;
use DateTime;
use Exception;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\NotTrashed;
use fostercommerce\bestsellers\helpers\Query as QueryHelper;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\jobs\FillUnitCostsJob;
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

		$totalOrders = $this->queueOrderJobs(BackfillOrdersJob::class);

		Craft::$app->session->setNotice(Craft::t('best-sellers', 'backfill.notice.queued', [
			'count' => $totalOrders,
		]));
		return $this->redirectToPostedUrl();
	}

	/**
	 * @throws MethodNotAllowedHttpException
	 * @throws BadRequestHttpException
	 */
	public function actionFillUnitCosts(): Response
	{
		$this->requirePostRequest();

		$unitCostProductTypes = Plugin::getInstance()->variantFields->getUnitCostProductTypes();
		if ($unitCostProductTypes === []) {
			Craft::$app->session->setError(Craft::t('best-sellers', 'backfill.error.noUnitCostField'));
			return $this->redirectToPostedUrl();
		}

		$productTypeIds = $this->resolveUnitCostProductTypeIds($unitCostProductTypes);
		if ($productTypeIds === []) {
			Craft::$app->session->setError(Craft::t('best-sellers', 'backfill.error.noProductTypes'));
			return $this->redirectToPostedUrl();
		}

		$totalOrders = $this->queueOrderJobs(FillUnitCostsJob::class, [
			'productTypeIds' => $productTypeIds,
		]);

		Craft::$app->session->setNotice(Craft::t('best-sellers', 'backfill.notice.fillUnitCostsQueued', [
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
			Craft::$app->session->setNotice(Craft::t('best-sellers', 'backfill.notice.noCompletedOrders'));
			return $this->redirectToPostedUrl();
		}

		/** @var DateTime $earliestOrderDate */
		$earliestOrderDate = DateTimeHelper::toDateTime($row['minDate']);
		/** @var DateTime $latestOrderDate */
		$latestOrderDate = DateTimeHelper::toDateTime($row['maxDate']);
		$startDate = $earliestOrderDate->format('Y-m-d');
		$endDate = $latestOrderDate->format('Y-m-d');

		QueueHelper::push(new RebuildDailyStatsJob([
			'startDate' => $startDate,
			'endDate' => $endDate,
		]));

		Craft::$app->session->setNotice(Craft::t('best-sellers', 'backfill.notice.dailyStatsRebuildQueued'));
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

		Craft::$app->session->setNotice(Craft::t('best-sellers', 'backfill.notice.variantSalesCleared'));
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

		Craft::$app->session->setNotice(Craft::t('best-sellers', 'backfill.notice.dailyStatsCleared'));
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

	/**
	 * Queue one job per batch of completed orders in the posted date range.
	 *
	 * @param class-string<BackfillOrdersJob> $jobClass
	 * @param array<string, mixed> $jobConfig
	 */
	private function queueOrderJobs(string $jobClass, array $jobConfig = []): int
	{
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
		// Keep this query to completion and date so its offset/limit batches match the job's id-ordered query
		$query = Order::find()
			->isCompleted(true);

		$query->andWhere(QueryHelper::dateOrderedCondition($startDate, $endDate));

		$totalOrders = (int) $query->count();

		// Queue jobs in batches, passing the date range.
		for ($offset = 0; $offset < $totalOrders; $offset += self::BATCH_SIZE) {
			Craft::$app->queue->push(new $jobClass([
				'offset' => $offset,
				'limit' => self::BATCH_SIZE,
				'startDate' => $startDate,
				'endDate' => $endDate,
				...$jobConfig,
			]));
		}

		return $totalOrders;
	}

	/**
	 * Get the posted product types that have a unit cost field, treating "All" as every one of them.
	 *
	 * @param list<ProductType> $unitCostProductTypes
	 * @return list<int>
	 */
	private function resolveUnitCostProductTypeIds(array $unitCostProductTypes): array
	{
		$allowedProductTypeIds = array_map(
			static fn (ProductType $productType): int => (int) $productType->id,
			$unitCostProductTypes,
		);

		$rawProductTypeIds = $this->request->getBodyParam('productTypes', []);
		if ($rawProductTypeIds === '*') {
			return $allowedProductTypeIds;
		}

		if (! is_array($rawProductTypeIds)) {
			return [];
		}

		return array_values(array_intersect($allowedProductTypeIds, array_map('intval', $rawProductTypeIds)));
	}
}
