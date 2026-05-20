<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\web\Controller;
use craft\web\Request;
use DateTime;
use fostercommerce\bestsellers\helpers\MoneyMath;
use fostercommerce\bestsellers\helpers\NotTrashed;
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
		// $fromDT and $toDT are Craft-app-timezone wall-clock strings. Route them
		// through Db::parseDateParam so the WHERE clause uses UTC literals that
		// match how dateOrdered is stored.
		$dateCondition = [
			'and',
			['=', '[[orders.isCompleted]]', true],
			Db::parseDateParam('[[orders.dateOrdered]]', ['and', ">= {$fromDT}", "<= {$toDT}"]),
		];

		$orderStatsQuery = (new Query())
			->select([
				'totalOrders' => 'COUNT(*)',
				'totalRevenue' => 'COALESCE(SUM([[orders.totalPrice]]), 0)',
				'totalCustomers' => 'COUNT(DISTINCT [[orders.customerId]])',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->where($dateCondition);

		/** @var array{totalOrders: string, totalRevenue: string, totalCustomers: string}|false $orderStats */
		$orderStats = NotTrashed::join($orderStatsQuery, 'orders')->one();

		$totalOrders = (int) ($orderStats['totalOrders'] ?? 0);
		$totalRevenue = (float) ($orderStats['totalRevenue'] ?? 0);
		$totalCustomers = (int) ($orderStats['totalCustomers'] ?? 0);
		$averageOrderValue = MoneyMath::toFloat(MoneyMath::average($totalRevenue, $totalOrders));

		$itemsQuery = (new Query())
			->select([
				'totalItems' => 'COALESCE(SUM([[lineItems.qty]]), 0)',
			])
			->from([
				'lineItems' => CommerceTable::LINEITEMS,
			])
			->innerJoin([
				'orders' => CommerceTable::ORDERS,
			], '[[lineItems.orderId]] = [[orders.id]]')
			->where($dateCondition);

		$totalItemsSold = (int) NotTrashed::join($itemsQuery, 'orders')->scalar();

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
		// Pulls one row per order in the window into PHP and buckets by Craft app
		// TZ day. Doing this in SQL would require DB-specific CONVERT_TZ / AT TIME
		// ZONE support and timezone-info tables loaded. Trade-off: full window
		// materialized in PHP memory; long ranges on busy stores are slower than
		// the old SQL DATE() grouping.
		$rowsQuery = (new Query())
			->select([
				'dateOrdered' => '[[orders.dateOrdered]]',
				'totalPrice' => '[[orders.totalPrice]]',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->where([
				'and',
				['=', '[[orders.isCompleted]]', true],
				Db::parseDateParam('[[orders.dateOrdered]]', ['and', ">= {$fromDT}", "<= {$toDT}"]),
			]);

		$rows = NotTrashed::join($rowsQuery, 'orders')->all();

		$byDay = [];
		foreach ($rows as $row) {
			/** @var array{dateOrdered: string, totalPrice: string|float|null} $row */
			$orderDate = DateTimeHelper::toDateTime((string) $row['dateOrdered']);
			if ($orderDate === false) {
				continue;
			}

			$day = $orderDate->format('Y-m-d');
			if (! isset($byDay[$day])) {
				$byDay[$day] = [
					'count' => 0,
					'revenue' => 0.0,
				];
			}

			$byDay[$day]['count']++;
			$byDay[$day]['revenue'] += (float) $row['totalPrice'];
		}

		ksort($byDay);

		$labels = [];
		$orders = [];
		$revenue = [];

		foreach ($byDay as $day => $totals) {
			$labels[] = $day;
			$orders[] = $totals['count'];
			$revenue[] = $totals['revenue'];
		}

		return [
			'labels' => $labels,
			'orders' => $orders,
			'revenue' => $revenue,
		];
	}
}
