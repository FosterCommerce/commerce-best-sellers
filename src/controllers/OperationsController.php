<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\Plugin as CommercePlugin;
use craft\db\Query;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use yii\web\Response;

class OperationsController extends BaseReportController
{
	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$commerce = CommercePlugin::getInstance();
		$store = $commerce?->getStores()->getPrimaryStore();
		$storeHandle = $store?->handle ?? 'default';

		// Order statuses with their associated emails
		$allStatuses = $commerce?->getOrderStatuses()->getAllOrderStatuses() ?? [];
		$statusEmails = [];
		foreach ($allStatuses as $status) {
			$emails = $status->getEmails();
			$statusEmails[] = [
				'status' => $status,
				'emails' => $emails,
			];
		}

		// All emails
		$allEmails = $commerce?->getEmails()->getAllEmails() ?? [];

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
						'couponCode' => null,
					],
				],
				['!=', 'couponCode', ''],
			])
			->groupBy('[[couponCode]]')
			->orderBy([
				'uses' => SORT_DESC,
			])
			->all();

		return $this->renderTemplate('best-sellers/_operations', [
			'title' => Craft::t('best-sellers', 'Operations'),
			'selectedSubnavItem' => 'operations',
			'storeHandle' => $storeHandle,
			'storeId' => $store?->id,
			'statusEmails' => $statusEmails,
			'allEmails' => $allEmails,
			'couponUsage' => $couponUsage,
		]);
	}
}
