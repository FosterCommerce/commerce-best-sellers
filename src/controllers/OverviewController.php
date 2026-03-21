<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as CommercePlugin;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\helpers\KpiCards;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class OverviewController extends BaseReportController
{
	/**
	 * @return array<string, array{keys: list<string>, link: string, linkLabel: string}>
	 */
	private static function cardGroups(): array
	{
		return [
			Craft::t('best-sellers', 'Orders') => [
				'keys' => ['revenue', 'orders', 'aov', 'totalDiscount', 'itemsSold', 'avgItemsPerOrder'],
				'link' => 'best-sellers/orders',
				'linkLabel' => Craft::t('best-sellers', 'See all orders'),
			],
			Craft::t('best-sellers', 'Customers') => [
				'keys' => ['customers', 'newCustomers', 'repeatRate'],
				'link' => 'best-sellers/customers',
				'linkLabel' => Craft::t('best-sellers', 'See all customers'),
			],
		];
	}

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$dateRange = $this->resolveDateRange();
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);
		$dailyStats = $plugin->dailyStats;

		$stats = $dailyStats->getStatsForRange($dateRange['from'], $dateRange['to']);
		$prevStats = $dailyStats->getStatsForRange($dateRange['prev']['from'], $dateRange['prev']['to']);

		// Build grouped cards
		$allKeys = [];
		$cardGroups = [];
		foreach (self::cardGroups() as $groupLabel => $group) {
			$keys = $group['keys'];
			$cards = KpiCards::build($stats, $prevStats, $keys, $this->percentChange(...));
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
		$productStats = $plugin->productStats;
		$productSummary = $productStats->getSummaryStats($dateRange['fromDT'], $dateRange['toDT']);
		$prevProductSummary = $productStats->getSummaryStats($dateRange['prev']['fromDT'], $dateRange['prev']['toDT']);
		$bestSellers = $productStats->getTopProducts($dateRange['fromDT'], $dateRange['toDT'], 'units', 10);

		// Batch-load product elements for CP URLs
		$bestSellerProductIds = array_unique(array_column($bestSellers, 'productId'));
		$bestSellerElements = [];
		if ($bestSellerProductIds !== []) {
			$productElements = Product::find()->id($bestSellerProductIds)->status(null)->all();
			foreach ($productElements as $productElement) {
				$bestSellerElements[$productElement->id] = $productElement;
			}
		}

		$cardGroups[] = [
			'label' => Craft::t('best-sellers', 'Products'),
			'link' => 'best-sellers/products',
			'linkLabel' => Craft::t('best-sellers', 'See all products'),
			'cards' => [
				[
					'label' => Craft::t('best-sellers', 'Unique Products Sold'),
					'value' => $productSummary['uniqueProducts'],
					'change' => $this->percentChange($productSummary['uniqueProducts'], $prevProductSummary['uniqueProducts']),
					'format' => 'number',
				],
				[
					'label' => Craft::t('best-sellers', 'Product Revenue'),
					'value' => $productSummary['totalProductRevenue'],
					'change' => $this->percentChange($productSummary['totalProductRevenue'], $prevProductSummary['totalProductRevenue']),
					'format' => 'currency',
				],
			],
		];

		// Customer charts
		$customerStats = $plugin->customerStats;
		$newVsReturning = $customerStats->getNewVsReturningByDay($dateRange['fromDT'], $dateRange['toDT']);
		$ltvComparison = $customerStats->getLtvComparison($dateRange['fromDT'], $dateRange['toDT']);

		// Cart abandonment
		$cartAbandonment = $plugin->cartAbandonment->getAbandonmentStats($dateRange['fromDT'], $dateRange['toDT']);

		// Commerce cart settings
		$commerceSettings = CommercePlugin::getInstance()?->getSettings();
		$activeCartDuration = $commerceSettings ? \craft\helpers\DateTimeHelper::humanDuration($commerceSettings->activeCartDuration) : '1 hour';
		$purgeEnabled = $commerceSettings ? $commerceSettings->purgeInactiveCarts : true;
		$purgeDuration = ($commerceSettings && $purgeEnabled) ? \craft\helpers\DateTimeHelper::humanDuration($commerceSettings->purgeInactiveCartsDuration) : null;

		// Daily chart data
		$dailyRows = $dailyStats->getDailyRows($dateRange['from'], $dateRange['to']);
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
		$prevDailyRows = $dailyStats->getDailyRows($dateRange['prev']['from'], $dateRange['prev']['to']);
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
			'title' => Craft::t('best-sellers', 'Overview'),
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
			'ltvComparison' => $ltvComparison,
			'cartAbandonment' => $cartAbandonment,
			'activeCartDuration' => $activeCartDuration,
			'purgeEnabled' => $purgeEnabled,
			'purgeDuration' => $purgeDuration,
		]);
	}
}
