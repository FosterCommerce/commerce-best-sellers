<?php
namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\records\Order as OrderRecord;
use craft\commerce\elements\Order;
use craft\web\Controller;
use craft\web\Request;
use yii\base\InvalidConfigException;
use yii\web\Response;

class ReportsController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	/**
	 * @throws InvalidConfigException
	 */
	public function actionIndex(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		$defaultFromDT = new \DateTime('-1 month');
		$defaultToDT   = new \DateTime('now');

		$preset    = $request->getQueryParam('preset', '');
		$fromInput = $request->getQueryParam('from', $defaultFromDT->format('Y-m-d'));
		$toInput   = $request->getQueryParam('to', $defaultToDT->format('Y-m-d'));

		$from = is_array($fromInput) ? trim(reset($fromInput)) : trim($fromInput);
		$to   = is_array($toInput) ? trim(reset($toInput)) : trim($toInput);

		// Convert to valid datetime strings.
		$fromDTObj = new \DateTime($from);
		$fromDTObj->setTime(00, 00, 00);
		$fromDT = $fromDTObj->format('Y-m-d H:i:s');
		$toDTObj = new \DateTime($to);
		$toDTObj->setTime(23, 59, 59);
		$toDT = $toDTObj->format('Y-m-d H:i:s');

		// ---------- Additional Dashboard Stats Using Orders ----------
		$orders = Order::find()
			->isCompleted(true)
			->andWhere(['>=', 'dateOrdered', $fromDT])
			->andWhere(['<=', 'dateOrdered', $toDT])
			->all();

		$totalOrders = count($orders);

		$totalItemsSold = 0;
		$totalUniqueItemsSold = 0;
		foreach ($orders as $order) {
			foreach ($order->getLineItems() as $lineItem) {
				$totalItemsSold += $lineItem->qty;
				// Use the purchasable's id as the unique key.
				$purchasable = $lineItem->getPurchasable();
				if ($purchasable && !isset($uniqueItemIds[$purchasable->id])) {
					$uniqueItemIds[$purchasable->id] = true;
					$totalUniqueItemsSold++;
				}
			}
		}

		$avgItemsPerOrder = $totalOrders > 0 ? $totalItemsSold / $totalOrders : 0;

		$totalRevenue = array_reduce($orders, function($carry, $order) {
			return $carry + $order->totalPrice;
		}, 0);

		$averageOrderValue = $totalOrders > 0 ? round($totalRevenue / $totalOrders, 2) : 0;

		// ---------- Total Customers ----------
		// Build an array of customer IDs (filtering out nulls if necessary)
		$customerIds = [];
		foreach ($orders as $order) {
			if ($order->customerId) {
				$customerIds[] = $order->customerId;
			}
		}
		$totalCustomers = count(array_unique($customerIds));

		// ---------- Build Daily Chart Data from Orders ----------
		$dailyOrders = [];
		$dailyRevenue = [];
		foreach ($orders as $order) {
			$day = $order->dateOrdered->format('Y-m-d');
			if (!isset($dailyOrders[$day])) {
				$dailyOrders[$day] = 0;
				$dailyRevenue[$day] = 0;
			}
			$dailyOrders[$day]++;
			$dailyRevenue[$day] += $order->totalPrice;
		}
		ksort($dailyOrders);
		ksort($dailyRevenue);
		$dailyLabels = array_keys($dailyOrders);
		$dailyData = array_values($dailyOrders);
		$dailyRevenueData = array_values($dailyRevenue);

		// ---------- Compute Previous Period Metrics ----------
		// Calculate the interval of the current period.
		$currentFrom = new \DateTime($from);
		$currentTo = new \DateTime($to);
		$interval = $currentFrom->diff($currentTo);
		// Set previous period: immediately preceding current period.
		$previousToDTObj = clone $currentFrom;
		$previousToDTObj->modify('-1 second');
		$previousFromDTObj = clone $previousToDTObj;
		$previousFromDTObj->sub($interval);
		$prevFromDT = $previousFromDTObj->format('Y-m-d H:i:s');
		$prevToDT = $previousToDTObj->format('Y-m-d H:i:s');

		$prevOrders = Order::find()
			->isCompleted(true)
			->andWhere(['>=', 'dateOrdered', $prevFromDT])
			->andWhere(['<=', 'dateOrdered', $prevToDT])
			->all();
		$prevTotalOrders = count($prevOrders);
		$prevTotalItemsSold = 0;
		foreach ($prevOrders as $order) {
			foreach ($order->getLineItems() as $lineItem) {
				$prevTotalItemsSold += $lineItem->qty;
			}
		}
		$prevAvgItemsPerOrder = $prevTotalOrders > 0 ? $prevTotalItemsSold / $prevTotalOrders : 0;
		$prevTotalRevenue = array_reduce($prevOrders, function($carry, $order) {
			return $carry + $order->totalPrice;
		}, 0);
		$prevAverageOrderValue = $prevTotalOrders > 0 ? round($prevTotalRevenue / $prevTotalOrders, 2) : 0;
		$prevCustomerIds = [];
		foreach ($prevOrders as $order) {
			if ($order->customerId) {
				$prevCustomerIds[] = $order->customerId;
			}
		}
		$prevTotalCustomers = count(array_unique($prevCustomerIds));

		return $this->renderTemplate('best-sellers/_reports', [
			// Chart data
			'dailyLabels'      => $dailyLabels,
			'dailyData'        => $dailyData,
			'dailyRevenueData' => $dailyRevenueData,
			// Current period metrics
			'totalOrders'      => $totalOrders,
			'totalItemsSold'    => $totalItemsSold,
			'avgItemsPerOrder' => $avgItemsPerOrder,
			'totalRevenue'     => $totalRevenue,
			'averageOrderValue'=> $averageOrderValue,
			'totalCustomers'   => $totalCustomers,
			// Previous period metrics for comparison
			'prevTotalOrders'      => $prevTotalOrders,
			'prevTotalItemsSold'    => $prevTotalItemsSold,
			'prevAvgItemsPerOrder' => $prevAvgItemsPerOrder,
			'prevTotalRevenue'     => $prevTotalRevenue,
			'prevAverageOrderValue'=> $prevAverageOrderValue,
			'prevTotalCustomers'   => $prevTotalCustomers,
			// Date range & preset
			'from'             => $from,
			'to'               => $to,
			'preset'           => $preset,
		]);
	}
}
