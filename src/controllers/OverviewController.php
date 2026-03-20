<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Product;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\helpers\KpiCards;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class OverviewController extends BaseReportController
{
	private const CARD_GROUPS = [
		'Orders' => [
			'keys' => ['revenue', 'orders', 'aov', 'totalDiscount', 'itemsSold', 'avgItemsPerOrder'],
			'link' => 'best-sellers/orders',
			'linkLabel' => 'See all orders',
		],
		'Customers' => [
			'keys' => ['customers', 'newCustomers', 'repeatRate'],
			'link' => 'best-sellers/customers',
			'linkLabel' => 'See all customers',
		],
	];

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$dateRange = $this->resolveDateRange();
		$dailyStats = Plugin::getInstance()->dailyStats;

		$stats = $dailyStats->getStatsForRange($dateRange['from'], $dateRange['to']);
		$prevStats = $dailyStats->getStatsForRange($dateRange['prev']['from'], $dateRange['prev']['to']);

		// Build grouped cards
		$allKeys = [];
		$cardGroups = [];
		foreach (self::CARD_GROUPS as $groupLabel => $group) {
			$keys = $group['keys'];
			$cards = KpiCards::build($stats, $prevStats, $keys, [$this, 'percentChange']);
			$cardGroups[] = [
				'label' => $groupLabel,
				'cards' => $cards,
				'link' => $group['link'],
				'linkLabel' => $group['linkLabel'],
			];
			$allKeys = array_merge($allKeys, $keys);
		}

		// Sparkline data for all cards
		$sparklines = [];
		foreach (KpiCards::sparklineColumns($allKeys) as $id => $column) {
			$sparklines[$id] = $dailyStats->getSparklineData($column, $dateRange['from'], $dateRange['to']);
		}

		// Products group
		$productStats = Plugin::getInstance()->productStats;
		$productSummary = $productStats->getSummaryStats($dateRange['fromDT'], $dateRange['toDT']);
		$prevProductSummary = $productStats->getSummaryStats($dateRange['prev']['fromDT'], $dateRange['prev']['toDT']);
		$bestSellers = $productStats->getTopProducts($dateRange['fromDT'], $dateRange['toDT'], 'units', 10);

		// Batch-load product elements for CP URLs
		$bestSellerProductIds = array_unique(array_column($bestSellers, 'productId'));
		$bestSellerElements = [];
		if (! empty($bestSellerProductIds)) {
			$productElements = Product::find()->id($bestSellerProductIds)->status(null)->all();
			foreach ($productElements as $productElement) {
				$bestSellerElements[$productElement->id] = $productElement;
			}
		}

		$cardGroups[] = [
			'label' => 'Products',
			'link' => 'best-sellers/products',
			'linkLabel' => 'See all products',
			'cards' => [
				[
					'label' => 'Unique Products Sold',
					'value' => $productSummary['uniqueProducts'],
					'change' => $this->percentChange($productSummary['uniqueProducts'], $prevProductSummary['uniqueProducts']),
					'format' => 'number',
				],
				[
					'label' => 'Product Revenue',
					'value' => $productSummary['totalProductRevenue'],
					'change' => $this->percentChange($productSummary['totalProductRevenue'], $prevProductSummary['totalProductRevenue']),
					'format' => 'currency',
				],
			],
		];

		// Customer charts
		$customerStats = Plugin::getInstance()->customerStats;
		$newVsReturning = $customerStats->getNewVsReturningByDay($dateRange['fromDT'], $dateRange['toDT']);
		$ltvDistribution = $customerStats->getLtvDistribution($dateRange['fromDT'], $dateRange['toDT']);

		// Daily chart data
		$dailyRows = $dailyStats->getDailyRows($dateRange['from'], $dateRange['to']);
		$dailyLabels = array_column($dailyRows, 'date');
		$dailyOrders = array_map('intval', array_column($dailyRows, 'totalOrders'));
		$dailyRevenue = array_map('floatval', array_column($dailyRows, 'totalRevenue'));
		$dailyAov = array_map('floatval', array_column($dailyRows, 'averageOrderValue'));

		// Previous period
		$prevDailyRows = $dailyStats->getDailyRows($dateRange['prev']['from'], $dateRange['prev']['to']);
		$prevDailyOrders = array_map('intval', array_column($prevDailyRows, 'totalOrders'));
		$prevDailyRevenue = array_map('floatval', array_column($prevDailyRows, 'totalRevenue'));
		$prevDailyAov = array_map('floatval', array_column($prevDailyRows, 'averageOrderValue'));

		return $this->renderTemplate('best-sellers/_overview', [
			'title' => 'Overview',
			'selectedSubnavItem' => 'overview',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
			'cardGroups' => $cardGroups,
			'sparklines' => $sparklines,
			'dailyLabels' => $dailyLabels,
			'dailyOrders' => $dailyOrders,
			'dailyRevenue' => $dailyRevenue,
			'dailyAov' => $dailyAov,
			'prevDailyOrders' => $prevDailyOrders,
			'prevDailyRevenue' => $prevDailyRevenue,
			'prevDailyAov' => $prevDailyAov,
			'bestSellers' => $bestSellers,
			'bestSellerElements' => $bestSellerElements,
			'newVsReturning' => $newVsReturning,
			'ltvDistribution' => $ltvDistribution,
		]);
	}
}
