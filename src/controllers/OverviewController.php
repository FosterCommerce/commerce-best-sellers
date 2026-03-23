<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\helpers\DateTimeHelper;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\helpers\KpiCards;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;
use yii\web\Response;

class OverviewController extends BaseReportController
{
	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$scope = $this->resolveScope();
		$plugin = Plugin::getInstance();
		$dailyStats = $plugin->dailyStats;

		// DailyStats uses pre-aggregated data (does not filter by status)
		$stats = $dailyStats->getStatsForRange($scope->from, $scope->to);
		$prevStats = $dailyStats->getStatsForRange($scope->getPrev()->from, $scope->getPrev()->to);

		// Health Check hero row
		$healthCheckKeys = ['revenue', 'orders', 'aov', 'repeatRate'];
		$healthCheckCards = KpiCards::build($stats, $prevStats, $healthCheckKeys, $this->percentChange(...));

		// Discounts section
		$discountKeys = ['totalDiscount', 'itemsSold', 'avgItemsPerOrder'];
		$discountCards = KpiCards::build($stats, $prevStats, $discountKeys, $this->percentChange(...));

		// Discount & order composition widgets
		$operationsStats = $plugin->operationsStats;
		$discountedVsFullPrice = $operationsStats->getDiscountedVsFullPrice($scope);
		$topDiscounts = $operationsStats->getTopDiscounts($scope);
		$itemsPerOrder = $operationsStats->getItemsPerOrderDistribution($scope);
		$shippingMethods = $operationsStats->getShippingMethods($scope);

		// Customers & Retention section
		$customerKeys = ['customers', 'newCustomers'];
		$customerCards = KpiCards::build($stats, $prevStats, $customerKeys, $this->percentChange(...));

		// LTV card (built manually since it comes from LtvComparison, not PeriodStats)
		$customerStats = $plugin->customerStats;
		$ltvComparison = $customerStats->getLtvComparison($scope);
		$prevScope = $scope->forDates($scope->getPrev()->from, $scope->getPrev()->to);
		$prevLtvComparison = $customerStats->getLtvComparison($prevScope);

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
			$sparklines[$id] = $dailyStats->getSparklineData($column, $scope->from, $scope->to);
		}

		// Top customers
		$topCustomers = $customerStats->getTopCustomers($scope, 5);

		// Customer charts
		$newVsReturning = $customerStats->getNewVsReturningByDay($scope);

		// Cart abandonment
		$cartAbandonment = $plugin->cartAbandonment->getAbandonmentStats($scope);
		$topAbandonedCarts = $plugin->cartAbandonment->getTopAbandonedCarts($scope, 100);

		// Commerce cart settings
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$commerceSettings = $commerce->getSettings();
		$activeCartDuration = DateTimeHelper::humanDuration($commerceSettings->activeCartDuration);
		$purgeEnabled = $commerceSettings->purgeInactiveCarts;
		$purgeDuration = $purgeEnabled ? DateTimeHelper::humanDuration($commerceSettings->purgeInactiveCartsDuration) : null;

		// Products section
		$productStats = $plugin->productStats;
		$productSummary = $productStats->getSummaryStats($scope);
		$prevProductSummary = $productStats->getSummaryStats($prevScope);
		$bestSellers = $productStats->getTopProducts($scope, 'units', 10);

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
		$summaryResult = $plugin->summaryEngine->generate($scope);

		// Daily chart data
		$dailyRows = $dailyStats->getDailyRows($scope->from, $scope->to);
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
		$prevDailyRows = $dailyStats->getDailyRows($scope->getPrev()->from, $scope->getPrev()->to);
		/** @var list<string> $prevRawOrders */
		$prevRawOrders = array_column($prevDailyRows, 'totalOrders');
		$prevDailyOrders = array_map(intval(...), $prevRawOrders);
		/** @var list<string> $prevRawRevenue */
		$prevRawRevenue = array_column($prevDailyRows, 'totalRevenue');
		$prevDailyRevenue = array_map(floatval(...), $prevRawRevenue);
		/** @var list<string> $prevRawAov */
		$prevRawAov = array_column($prevDailyRows, 'averageOrderValue');
		$prevDailyAov = array_map(floatval(...), $prevRawAov);

		$hasData = VariantSale::find()->exists();

		return $this->renderTemplate('best-sellers/_overview', [
			'title' => Craft::t('best-sellers', 'Dashboard'),
			'selectedSubnavItem' => 'overview',
			'hasData' => $hasData,
			'from' => $scope->from,
			'to' => $scope->to,
			'preset' => $scope->preset,
			'scope' => $scope,
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
			'topCustomers' => $topCustomers,
			'newVsReturning' => $newVsReturning,
			'ltvComparison' => $ltvComparison,
			'cartAbandonment' => $cartAbandonment,
			'topAbandonedCarts' => $topAbandonedCarts,
			'activeCartDuration' => $activeCartDuration,
			'purgeEnabled' => $purgeEnabled,
			'purgeDuration' => $purgeDuration,
			// Summaries
			'summaries' => $summaryResult,
		]);
	}
}
