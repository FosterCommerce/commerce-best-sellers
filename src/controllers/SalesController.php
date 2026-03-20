<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;
use yii\web\Response;

class SalesController extends BaseReportController
{
	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$dateRange = $this->resolveDateRange();
		$dailyStats = Plugin::getInstance()->dailyStats;

		$stats = $dailyStats->getStatsForRange($dateRange['from'], $dateRange['to']);
		$prevStats = $dailyStats->getStatsForRange($dateRange['prev']['from'], $dateRange['prev']['to']);

		// Daily chart data
		$dailyRows = $dailyStats->getDailyRows($dateRange['from'], $dateRange['to']);
		$dailyLabels = array_column($dailyRows, 'date');
		$dailyOrders = array_map('intval', array_column($dailyRows, 'totalOrders'));
		$dailyRevenue = array_map('floatval', array_column($dailyRows, 'totalRevenue'));
		$dailyAov = array_map('floatval', array_column($dailyRows, 'averageOrderValue'));

		// Previous period daily data for overlay
		$prevDailyRows = $dailyStats->getDailyRows($dateRange['prev']['from'], $dateRange['prev']['to']);
		$prevDailyOrders = array_map('intval', array_column($prevDailyRows, 'totalOrders'));
		$prevDailyRevenue = array_map('floatval', array_column($prevDailyRows, 'totalRevenue'));
		$prevDailyAov = array_map('floatval', array_column($prevDailyRows, 'averageOrderValue'));

		// Revenue by product type
		$revenueByType = (new Query())
			->select([
				'productType' => 'pt.[[name]]',
				'revenue' => 'COALESCE(SUM(vs.[[lineItemTotal]]), 0)',
			])
			->from(['vs' => VariantSale::tableName()])
			->innerJoin(['p' => '{{%commerce_products}}'], 'vs.[[productId]] = p.[[id]]')
			->innerJoin(['pt' => '{{%commerce_producttypes}}'], 'p.[[typeId]] = pt.[[id]]')
			->where(['>=', 'vs.[[dateOrdered]]', $dateRange['fromDT']])
			->andWhere(['<=', 'vs.[[dateOrdered]]', $dateRange['toDT']])
			->groupBy('pt.[[name]]')
			->orderBy(['revenue' => SORT_DESC])
			->all();

		// Written summary
		$revenueChange = $this->percentChange($stats['totalRevenue'], $prevStats['totalRevenue']);
		$ordersChange = $this->percentChange($stats['totalOrders'], $prevStats['totalOrders']);

		// Orders list
		$orders = Order::find()
			->isCompleted(true)
			->dateOrdered(['and', '>= ' . $dateRange['fromDT'], '<= ' . $dateRange['toDT']])
			->orderBy(['dateOrdered' => SORT_DESC])
			->all();

		// Get items sold per order
		$orderItemCounts = [];
		if (! empty($orders)) {
			$orderIds = array_map(fn ($o) => $o->id, $orders);
			$itemCounts = (new Query())
				->select([
					'orderId',
					'totalItems' => 'SUM([[qty]])',
				])
				->from('{{%commerce_lineitems}}')
				->where(['in', 'orderId', $orderIds])
				->groupBy('orderId')
				->all();
			foreach ($itemCounts as $row) {
				$orderItemCounts[$row['orderId']] = (int) $row['totalItems'];
			}
		}

		return $this->renderTemplate('best-sellers/_sales', [
			'title' => 'Sales',
			'selectedSubnavItem' => 'sales',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
			'stats' => $stats,
			'revenueChange' => $revenueChange,
			'ordersChange' => $ordersChange,
			'dailyLabels' => $dailyLabels,
			'dailyOrders' => $dailyOrders,
			'dailyRevenue' => $dailyRevenue,
			'dailyAov' => $dailyAov,
			'prevDailyOrders' => $prevDailyOrders,
			'prevDailyRevenue' => $prevDailyRevenue,
			'prevDailyAov' => $prevDailyAov,
			'revenueByType' => $revenueByType,
			'orders' => $orders,
			'orderItemCounts' => $orderItemCounts,
		]);
	}
}
