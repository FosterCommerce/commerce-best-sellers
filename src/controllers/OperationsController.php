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

		$dateRange = $this->resolveScope();
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);
		$operationsStats = $plugin->operationsStats;

		$couponUsage = $operationsStats->getCouponUsage($dateRange);

		return $this->renderTemplate('best-sellers/_operations', [
			'title' => Craft::t('best-sellers', 'Operations'),
			'selectedSubnavItem' => 'operations',
			'from' => $dateRange->from,
			'to' => $dateRange->to,
			'preset' => $dateRange->preset,
			'scope' => $dateRange,
			'couponUsage' => $couponUsage,
		]);
	}
}
