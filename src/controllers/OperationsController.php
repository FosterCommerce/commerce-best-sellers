<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class OperationsController extends BaseReportController
{
	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$store = $commerce->getStores()->getPrimaryStore();

		// Order statuses with their associated emails
		$allStatuses = $commerce->getOrderStatuses()->getAllOrderStatuses();
		$statusEmails = [];
		foreach ($allStatuses as $allRectorPrefix202411Status) {
			$emails = $allRectorPrefix202411Status->getEmails();
			$statusEmails[] = [
				'status' => $allRectorPrefix202411Status,
				'emails' => $emails,
			];
		}

		// All emails
		$allEmails = $commerce->getEmails()->getAllEmails();

		// TODO: Move this query to a service method if the Operations page gets bigger
		// All-time coupon usage
		$couponUsage = (new Query())
			->select([
				'code' => '[[couponCode]]',
				'uses' => 'COUNT(*)',
				'totalDiscount' => 'COALESCE(SUM(ABS([[totalDiscount]])), 0)',
			])
			->from(CommerceTable::ORDERS)
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				[
					'not', [
						'[[couponCode]]' => null,
					],
				],
				['!=', '[[couponCode]]', ''],
			])
			->groupBy('[[couponCode]]')
			->orderBy([
				'uses' => SORT_DESC,
			])
			->all();

		$backfillLogs = Plugin::getInstance()->backfillLogs->getAll();

		return $this->renderTemplate('best-sellers/_operations', [
			'title' => Craft::t('best-sellers', 'Operations'),
			'selectedSubnavItem' => 'operations',
			'storeId' => $store?->id,
			'statusEmails' => $statusEmails,
			'allEmails' => $allEmails,
			'couponUsage' => $couponUsage,
			'backfillLogs' => $backfillLogs,
		]);
	}

	public function actionClearLogs(): Response
	{
		$this->requirePostRequest();
		Plugin::getInstance()->backfillLogs->deleteAll();
		Craft::$app->session->setNotice(Craft::t('best-sellers', 'Backfill logs cleared.'));

		return $this->redirectToPostedUrl();
	}
}
