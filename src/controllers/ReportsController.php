<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use craft\web\Controller;
use craft\web\Request;
use DateTime;
use fostercommerce\bestsellers\Plugin;
use yii\base\Action;
use yii\base\InvalidConfigException;
use yii\web\Response;

class ReportsController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	/**
	 * @param Action<static> $action
	 */
	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requirePermission(Plugin::PERMISSION_VIEW_REPORTS);

		return true;
	}

	/**
	 * @throws InvalidConfigException
	 */
	public function actionIndex(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		$defaultFromDT = new DateTime('-1 month');
		$defaultToDT = new DateTime('now');

		$preset = $request->getQueryParam('preset', '');
		/** @var string $fromInput */
		$fromInput = $request->getQueryParam('from', $defaultFromDT->format('Y-m-d'));
		/** @var string $toInput */
		$toInput = $request->getQueryParam('to', $defaultToDT->format('Y-m-d'));

		$from = trim($fromInput);
		$to = trim($toInput);

		$fromDTObj = new DateTime($from);
		$fromDTObj->setTime(0, 0, 0);

		$fromDT = $fromDTObj->format('Y-m-d H:i:s');

		$toDTObj = new DateTime($to);
		$toDTObj->setTime(23, 59, 59);

		$toDT = $toDTObj->format('Y-m-d H:i:s');

		$currentMetrics = $this->getPeriodMetrics($fromDT, $toDT);
		$dailyChart = $this->getDailyChart($fromDT, $toDT);

		// Previous period: same duration, immediately preceding
		$currentFrom = new DateTime($from);
		$currentTo = new DateTime($to);
		$interval = $currentFrom->diff($currentTo);

		$previousToDTObj = (clone $currentFrom)->modify('-1 second');
		$previousFromDTObj = (clone $previousToDTObj)->sub($interval);

		$prevMetrics = $this->getPeriodMetrics(
			$previousFromDTObj->format('Y-m-d H:i:s'),
			$previousToDTObj->format('Y-m-d H:i:s')
		);

		return $this->renderTemplate('best-sellers/_reports', [
			'dailyLabels' => $dailyChart['labels'],
			'dailyData' => $dailyChart['orders'],
			'dailyRevenueData' => $dailyChart['revenue'],
			'totalOrders' => $currentMetrics['totalOrders'],
			'totalItemsSold' => $currentMetrics['totalItemsSold'],
			'avgItemsPerOrder' => $currentMetrics['avgItemsPerOrder'],
			'totalRevenue' => $currentMetrics['totalRevenue'],
			'averageOrderValue' => $currentMetrics['averageOrderValue'],
			'totalCustomers' => $currentMetrics['totalCustomers'],
			'prevTotalOrders' => $prevMetrics['totalOrders'],
			'prevTotalItemsSold' => $prevMetrics['totalItemsSold'],
			'prevAvgItemsPerOrder' => $prevMetrics['avgItemsPerOrder'],
			'prevTotalRevenue' => $prevMetrics['totalRevenue'],
			'prevAverageOrderValue' => $prevMetrics['averageOrderValue'],
			'prevTotalCustomers' => $prevMetrics['totalCustomers'],
			'from' => $from,
			'to' => $to,
			'preset' => $preset,
		]);
	}

	/**
	 * @return array{totalOrders: int, totalRevenue: float, averageOrderValue: float, totalCustomers: int, totalItemsSold: int, avgItemsPerOrder: float}
	 */
	private function getPeriodMetrics(string $fromDT, string $toDT): array
	{
		$orderStats = (new Query())
			->select([
				'totalOrders' => 'COUNT(*)',
				'totalRevenue' => 'COALESCE(SUM([[totalPrice]]), 0)',
				'totalCustomers' => 'COUNT(DISTINCT [[customerId]])',
			])
			->from(CommerceTable::ORDERS)
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				['>=', '[[dateOrdered]]', $fromDT],
				['<=', '[[dateOrdered]]', $toDT],
			])
			->one();

		/** @var array{totalOrders: string, totalRevenue: string, totalCustomers: string}|false $orderStats */

		$totalOrders = (int) ($orderStats['totalOrders'] ?? 0);
		$totalRevenue = (float) ($orderStats['totalRevenue'] ?? 0);
		$totalCustomers = (int) ($orderStats['totalCustomers'] ?? 0);
		$averageOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

		$totalItemsSold = (int) (new Query())
			->select([
				'totalItems' => 'COALESCE(SUM([[lineItems.qty]]), 0)',
			])
			->from([
				'lineItems' => CommerceTable::LINEITEMS,
			])
			->innerJoin([
				'orders' => CommerceTable::ORDERS,
			], '[[lineItems.orderId]] = [[orders.id]]')
			->where([
				'and',
				['=', '[[orders.isCompleted]]', true],
				['>=', '[[orders.dateOrdered]]', $fromDT],
				['<=', '[[orders.dateOrdered]]', $toDT],
			])
			->scalar();

		$avgItemsPerOrder = $totalOrders > 0 ? round($totalItemsSold / $totalOrders, 2) : 0;

		return [
			'totalOrders' => $totalOrders,
			'totalRevenue' => $totalRevenue,
			'averageOrderValue' => $averageOrderValue,
			'totalCustomers' => $totalCustomers,
			'totalItemsSold' => $totalItemsSold,
			'avgItemsPerOrder' => $avgItemsPerOrder,
		];
	}

	/**
	 * @return array{labels: list<string>, orders: list<int>, revenue: list<float>}
	 */
	private function getDailyChart(string $fromDT, string $toDT): array
	{
		$db = Craft::$app->getDb();
		$isMysql = $db->getIsMysql();

		$dayExpression = $isMysql
			? 'DATE([[dateOrdered]])'
			: 'CAST([[dateOrdered]] AS DATE)';

		$rows = (new Query())
			->select([
				'day' => $dayExpression,
				'orderCount' => 'COUNT(*)',
				'revenue' => 'COALESCE(SUM([[totalPrice]]), 0)',
			])
			->from(CommerceTable::ORDERS)
			->where([
				'and',
				['=', '[[isCompleted]]', true],
				['>=', '[[dateOrdered]]', $fromDT],
				['<=', '[[dateOrdered]]', $toDT],
			])
			->groupBy($dayExpression)
			->orderBy([
				'day' => SORT_ASC,
			])
			->all();

		$labels = [];
		$orders = [];
		$revenue = [];

		foreach ($rows as $row) {
			/** @var array{day: string, orderCount: string, revenue: string} $row */
			$labels[] = $row['day'];
			$orders[] = (int) $row['orderCount'];
			$revenue[] = (float) $row['revenue'];
		}

		return [
			'labels' => $labels,
			'orders' => $orders,
			'revenue' => $revenue,
		];
	}
}
