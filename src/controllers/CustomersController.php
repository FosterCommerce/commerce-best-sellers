<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\helpers\KpiCards;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class CustomersController extends BaseReportController
{
	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$dateRange = $this->resolveDateRange();
		$dailyStats = Plugin::getInstance()->dailyStats;
		$customerStats = Plugin::getInstance()->customerStats;

		// Use daily_stats for KPI cards (consistent with other pages)
		$stats = $dailyStats->getStatsForRange($dateRange['from'], $dateRange['to']);
		$prevStats = $dailyStats->getStatsForRange($dateRange['prev']['from'], $dateRange['prev']['to']);

		$cardKeys = ['customers', 'newCustomers', 'repeatRate'];
		$cards = KpiCards::build($stats, $prevStats, $cardKeys, [$this, 'percentChange']);

		// Override descriptions for customer context
		foreach ($cards as &$card) {
			match ($card['label']) {
				'Customers' => $card['description'] = 'Customers with orders',
				'New Customers' => $card['description'] = 'New customers with orders',
				'Repeat Rate' => $card['description'] = 'Returning customers with orders',
				default => null,
			};
		}
		unset($card);

		// Sparkline data
		$sparklines = [];
		foreach (KpiCards::sparklineColumns($cardKeys) as $id => $column) {
			$sparklines[$id] = $dailyStats->getSparklineData($column, $dateRange['from'], $dateRange['to']);
		}

		// Written summary
		$kpis = $customerStats->getCustomerKpis($dateRange['fromDT'], $dateRange['toDT']);
		$prevKpis = $customerStats->getCustomerKpis($dateRange['prev']['fromDT'], $dateRange['prev']['toDT']);
		$newCustomersChange = $this->percentChange($kpis['new'], $prevKpis['new']);

		// Top shipping locations
		$topLocations = $customerStats->getTopShippingLocations($dateRange['fromDT'], $dateRange['toDT']);

		// Charts
		$newVsReturning = $customerStats->getNewVsReturningByDay($dateRange['fromDT'], $dateRange['toDT']);
		$ltvDistribution = $customerStats->getLtvDistribution($dateRange['fromDT'], $dateRange['toDT']);

		// Top customers table
		$topCustomers = $customerStats->getTopCustomers($dateRange['fromDT'], $dateRange['toDT']);

		return $this->renderTemplate('best-sellers/_customers', [
			'title' => 'Customers',
			'selectedSubnavItem' => 'customers',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
			'cards' => $cards,
			'sparklines' => $sparklines,
			'kpis' => $kpis,
			'newCustomersChange' => $newCustomersChange,
			'topLocations' => $topLocations,
			'newVsReturning' => $newVsReturning,
			'topCustomers' => $topCustomers,
			'ltvDistribution' => $ltvDistribution,
		]);
	}
}
