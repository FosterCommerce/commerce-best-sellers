<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class OperationsController extends BaseReportController
{
	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$dateRange = $this->resolveDateRange();
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);
		$operationsStats = $plugin->operationsStats;

		$itemsPerOrder = $operationsStats->getItemsPerOrderDistribution($dateRange['fromDT'], $dateRange['toDT']);
		$shippingMethods = $operationsStats->getShippingMethods($dateRange['fromDT'], $dateRange['toDT']);
		$couponUsage = $operationsStats->getCouponUsage($dateRange['fromDT'], $dateRange['toDT']);
		$discountTrend = $operationsStats->getDiscountTrend($dateRange['fromDT'], $dateRange['toDT']);

		return $this->renderTemplate('best-sellers/_operations', [
			'title' => 'Operations',
			'selectedSubnavItem' => 'operations',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
			'itemsPerOrder' => $itemsPerOrder,
			'shippingMethods' => $shippingMethods,
			'couponUsage' => $couponUsage,
			'discountTrend' => $discountTrend,
		]);
	}
}
