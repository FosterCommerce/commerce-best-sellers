<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as CommercePlugin;
use craft\helpers\DateTimeHelper;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\helpers\KpiCards;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class OverviewController extends BaseReportController
{
	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$dateRange = $this->resolveDateRange();
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);
		$dailyStats = $plugin->dailyStats;

		$stats = $dailyStats->getStatsForRange($dateRange->from, $dateRange->to);
		$prevStats = $dailyStats->getStatsForRange($dateRange->getPrev()->from, $dateRange->getPrev()->to);

		// Health Check hero row
		$healthCheckKeys = ['revenue', 'orders', 'aov', 'repeatRate'];
		$healthCheckCards = KpiCards::build($stats, $prevStats, $healthCheckKeys, $this->percentChange(...));

		// Discounts section
		$discountKeys = ['totalDiscount', 'itemsSold', 'avgItemsPerOrder'];
		$discountCards = KpiCards::build($stats, $prevStats, $discountKeys, $this->percentChange(...));

		// Discount & order composition widgets
		$operationsStats = $plugin->operationsStats;
		$discountedVsFullPrice = $operationsStats->getDiscountedVsFullPrice($dateRange->fromDT, $dateRange->toDT);
		$topDiscounts = $operationsStats->getTopDiscounts($dateRange->fromDT, $dateRange->toDT);
		$itemsPerOrder = $operationsStats->getItemsPerOrderDistribution($dateRange->fromDT, $dateRange->toDT);
		$shippingMethods = $operationsStats->getShippingMethods($dateRange->fromDT, $dateRange->toDT);

		// Customers & Retention section
		$customerKeys = ['customers', 'newCustomers'];
		$customerCards = KpiCards::build($stats, $prevStats, $customerKeys, $this->percentChange(...));

		// LTV card (built manually since it comes from LtvComparison, not PeriodStats)
		$customerStats = $plugin->customerStats;
		$ltvComparison = $customerStats->getLtvComparison($dateRange->fromDT, $dateRange->toDT);
		$prevLtvComparison = $customerStats->getLtvComparison($dateRange->getPrev()->fromDT, $dateRange->getPrev()->toDT);

		$totalCustomers = ($ltvComparison->credentialed->count ?? 0) + ($ltvComparison->guest->count ?? 0);
		$totalRevenueLtv = ($ltvComparison->credentialed->totalRevenue ?? 0) + ($ltvComparison->guest->totalRevenue ?? 0);
		$avgLtv = $totalCustomers > 0 ? $totalRevenueLtv / $totalCustomers : 0;

		$prevTotalCustomers = ($prevLtvComparison->credentialed->count ?? 0) + ($prevLtvComparison->guest->count ?? 0);
		$prevTotalRevenueLtv = ($prevLtvComparison->credentialed->totalRevenue ?? 0) + ($prevLtvComparison->guest->totalRevenue ?? 0);
		$prevAvgLtv = $prevTotalCustomers > 0 ? $prevTotalRevenueLtv / $prevTotalCustomers : 0;

		$customerCards[] = [
			'label' => Craft::t('best-sellers', 'Avg Customer LTV'),
			'value' => $avgLtv,
			'change' => $this->percentChange($avgLtv, $prevAvgLtv),
			'format' => 'currency',
		];

		// Sparkline data for all card keys
		$allKeys = array_merge($healthCheckKeys, $discountKeys, $customerKeys);
		$sparklines = [];
		foreach (KpiCards::sparklineColumns($allKeys) as $id => $column) {
			$sparklines[$id] = $dailyStats->getSparklineData($column, $dateRange->from, $dateRange->to);
		}

		// Customer charts
		$newVsReturning = $customerStats->getNewVsReturningByDay($dateRange->fromDT, $dateRange->toDT);

		// Cart abandonment
		$cartAbandonment = $plugin->cartAbandonment->getAbandonmentStats($dateRange->fromDT, $dateRange->toDT);

		// Commerce cart settings
		$commerceSettings = CommercePlugin::getInstance()?->getSettings();
		$activeCartDuration = $commerceSettings ? DateTimeHelper::humanDuration($commerceSettings->activeCartDuration) : '1 hour';
		$purgeEnabled = $commerceSettings ? $commerceSettings->purgeInactiveCarts : true;
		$purgeDuration = ($commerceSettings && $purgeEnabled) ? DateTimeHelper::humanDuration($commerceSettings->purgeInactiveCartsDuration) : null;

		// Products section
		$productStats = $plugin->productStats;
		$productSummary = $productStats->getSummaryStats($dateRange->fromDT, $dateRange->toDT);
		$prevProductSummary = $productStats->getSummaryStats($dateRange->getPrev()->fromDT, $dateRange->getPrev()->toDT);
		$bestSellers = $productStats->getTopProducts($dateRange->fromDT, $dateRange->toDT, 'units', 10);

		// Batch-load product elements for CP URLs
		$bestSellerProductIds = array_unique(array_column($bestSellers, 'productId'));
		$bestSellerElements = [];
		if ($bestSellerProductIds !== []) {
			$productElements = Product::find()->id($bestSellerProductIds)->status(null)->all();
			foreach ($productElements as $productElement) {
				$bestSellerElements[$productElement->id] = $productElement;
			}
		}

		$productCards = [
			[
				'label' => Craft::t('best-sellers', 'Unique Products Sold'),
				'value' => $productSummary->uniqueProducts,
				'change' => $this->percentChange($productSummary->uniqueProducts, $prevProductSummary->uniqueProducts),
				'format' => 'number',
			],
			[
				'label' => Craft::t('best-sellers', 'Product Revenue'),
				'value' => $productSummary->totalProductRevenue,
				'change' => $this->percentChange($productSummary->totalProductRevenue, $prevProductSummary->totalProductRevenue),
				'format' => 'currency',
			],
		];

		// Summaries
		$summaryResult = $plugin->summaryEngine->generate($dateRange);

		// Daily chart data
		$dailyRows = $dailyStats->getDailyRows($dateRange->from, $dateRange->to);
		$dailyLabels = array_column($dailyRows, 'date');
		/** @var list<string> $rawOrders */
		$rawOrders = array_column($dailyRows, 'totalOrders');
		$dailyOrders = array_map(intval(...), $rawOrders);
		/** @var list<string> $rawRevenue */
		$rawRevenue = array_column($dailyRows, 'totalRevenue');
		$dailyRevenue = array_map(floatval(...), $rawRevenue);
		/** @var list<string> $rawAov */
		$rawAov = array_column($dailyRows, 'averageOrderValue');
		$dailyAov = array_map(floatval(...), $rawAov);

		// Previous period
		$prevDailyRows = $dailyStats->getDailyRows($dateRange->getPrev()->from, $dateRange->getPrev()->to);
		/** @var list<string> $prevRawOrders */
		$prevRawOrders = array_column($prevDailyRows, 'totalOrders');
		$prevDailyOrders = array_map(intval(...), $prevRawOrders);
		/** @var list<string> $prevRawRevenue */
		$prevRawRevenue = array_column($prevDailyRows, 'totalRevenue');
		$prevDailyRevenue = array_map(floatval(...), $prevRawRevenue);
		/** @var list<string> $prevRawAov */
		$prevRawAov = array_column($prevDailyRows, 'averageOrderValue');
		$prevDailyAov = array_map(floatval(...), $prevRawAov);

		return $this->renderTemplate('best-sellers/_overview', [
			'title' => Craft::t('best-sellers', 'Dashboard'),
			'selectedSubnavItem' => 'overview',
			'from' => $dateRange->from,
			'to' => $dateRange->to,
			'preset' => $dateRange->preset,
			// Section cards
			'healthCheckCards' => $healthCheckCards,
			'discountCards' => $discountCards,
			'discountedVsFullPrice' => $discountedVsFullPrice,
			'topDiscounts' => $topDiscounts,
			'itemsPerOrder' => $itemsPerOrder,
			'shippingMethods' => $shippingMethods,
			'customerCards' => $customerCards,
			'productCards' => $productCards,
			// Sparklines
			'sparklines' => $sparklines,
			// Chart data
			'dailyLabels' => $dailyLabels,
			'dailyOrders' => $dailyOrders,
			'dailyRevenue' => $dailyRevenue,
			'dailyAov' => $dailyAov,
			'prevDailyOrders' => $prevDailyOrders,
			'prevDailyRevenue' => $prevDailyRevenue,
			'prevDailyAov' => $prevDailyAov,
			// Widgets
			'bestSellers' => $bestSellers,
			'bestSellerElements' => $bestSellerElements,
			'newVsReturning' => $newVsReturning,
			'ltvComparison' => $ltvComparison,
			'cartAbandonment' => $cartAbandonment,
			'activeCartDuration' => $activeCartDuration,
			'purgeEnabled' => $purgeEnabled,
			'purgeDuration' => $purgeDuration,
			// Summaries
			'summaries' => $summaryResult,
		]);
	}
}
