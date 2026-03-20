<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class OrdersController extends BaseReportController
{
	private const PER_PAGE = 100;

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

		/** @var array{totalRevenue: float, totalOrders: int} $stats */
		/** @var array{totalRevenue: float, totalOrders: int} $prevStats */
		$revenueChange = $this->percentChange($stats['totalRevenue'], $prevStats['totalRevenue']);
		$ordersChange = $this->percentChange($stats['totalOrders'], $prevStats['totalOrders']);

		return $this->renderTemplate('best-sellers/_sales', [
			'title' => 'Orders',
			'selectedSubnavItem' => 'orders',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
			'stats' => $stats,
			'revenueChange' => $revenueChange,
			'ordersChange' => $ordersChange,
		]);
	}

	/**
	 * AJAX endpoint for paginated orders data.
	 */
	public function actionOrdersData(): Response
	{
		$this->requireAcceptsJson();

		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$pageParam = $request->getQueryParam('page', 1);
		$page = max(1, is_numeric($pageParam) ? (int) $pageParam : 1);
		$offset = ($page - 1) * self::PER_PAGE;

		$ordersQuery = $this->buildFilteredOrdersQuery($dateRange);

		$totalOrders = (int) $ordersQuery->count();
		$totalPages = max(1, (int) ceil($totalOrders / self::PER_PAGE));

		$orders = $ordersQuery
			->offset($offset)
			->limit(self::PER_PAGE)
			->all();

		$rows = $this->buildOrderRows($orders);

		// Page totals from order elements
		$currency = $this->getStoreCurrency();
		$totalItemSubtotal = 0;
		$totalTax = 0;
		$totalDiscount = 0;
		$totalShipping = 0;
		$totalPaid = 0;
		$totalItemsSold = 0;

		foreach ($orders as $order) {
			$totalItemSubtotal += $order->itemSubtotal;
			$totalTax += $order->totalTax;
			$totalDiscount += $order->totalDiscount;
			$totalShipping += $order->totalShippingCost;
			$totalPaid += $order->totalPaid;
		}

		foreach ($rows as $row) {
			$totalItemsSold += $row['itemsSold'];
		}

		$totals = [
			'itemSubtotal' => Craft::$app->getFormatter()->asCurrency($totalItemSubtotal, $currency),
			'totalTax' => Craft::$app->getFormatter()->asCurrency($totalTax, $currency),
			'totalDiscount' => Craft::$app->getFormatter()->asCurrency($totalDiscount, $currency),
			'totalShippingCost' => Craft::$app->getFormatter()->asCurrency($totalShipping, $currency),
			'totalPaid' => Craft::$app->getFormatter()->asCurrency($totalPaid, $currency),
			'itemsSold' => number_format($totalItemsSold),
		];

		return $this->asJson([
			'orders' => $rows,
			'currentPage' => $page,
			'totalPages' => $totalPages,
			'totalOrders' => $totalOrders,
			'perPage' => self::PER_PAGE,
			'totals' => $totals,
		]);
	}

	/**
	 * CSV export of filtered orders.
	 */
	public function actionExportCsv(): Response
	{
		$dateRange = $this->resolveDateRange();
		$ordersQuery = $this->buildFilteredOrdersQuery($dateRange);

		$orders = $ordersQuery->all();
		$rows = $this->buildOrderRows($orders);

		$csvRows = [];
		$totalMerchandise = 0;
		$totalTax = 0;
		$totalDiscount = 0;
		$totalShipping = 0;
		$totalPaid = 0;
		$totalItemsSold = 0;

		foreach ($orders as $index => $order) {
			$totalMerchandise += $order->itemSubtotal;
			$totalTax += $order->totalTax;
			$totalDiscount += $order->totalDiscount;
			$totalShipping += $order->totalShippingCost;
			$totalPaid += $order->totalPaid;
			$itemsSold = $rows[$index]['itemsSold'] ?? 0;
			$totalItemsSold += $itemsSold;

			$csvRows[] = [
				'reference' => $order->reference,
				'dateOrdered' => $rows[$index]['dateOrdered'] ?? '',
				'status' => $rows[$index]['statusName'] ?? '',
				'email' => $order->email ?? '',
				'merchandiseTotal' => round($order->itemSubtotal, 2),
				'tax' => round($order->totalTax, 2),
				'discount' => round($order->totalDiscount, 2),
				'shipping' => round($order->totalShippingCost, 2),
				'totalPaid' => round($order->totalPaid, 2),
				'itemsSold' => $itemsSold,
				'paymentStatus' => $order->paidStatus,
			];
		}

		$csvRows[] = [
			'reference' => 'TOTAL',
			'dateOrdered' => '',
			'status' => '',
			'email' => '',
			'merchandiseTotal' => round($totalMerchandise, 2),
			'tax' => round($totalTax, 2),
			'discount' => round($totalDiscount, 2),
			'shipping' => round($totalShipping, 2),
			'totalPaid' => round($totalPaid, 2),
			'itemsSold' => $totalItemsSold,
			'paymentStatus' => '',
		];

		return $this->asCsv($csvRows, [
			'Order #', 'Date Ordered', 'Status', 'Email',
			'Merchandise Total', 'Tax', 'Discount', 'Shipping',
			'Total Paid', 'Items Sold', 'Payment Status',
		], 'orders');
	}

	/**
	 * Build the filtered orders query from request params.
	 *
	 * @param array{fromDT: string, toDT: string} $dateRange
	 */
	private function buildFilteredOrdersQuery(array $dateRange): \craft\commerce\elements\db\OrderQuery
	{
		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();
		$searchParam = $request->getQueryParam('search', '');
		$search = is_string($searchParam) ? trim($searchParam) : '';
		$sortParam = $request->getQueryParam('sort', 'dateOrdered');
		$sort = is_string($sortParam) ? trim($sortParam) : 'dateOrdered';
		$sortDirParam = $request->getQueryParam('sortDir', 'desc');
		$sortDir = is_string($sortDirParam) ? trim($sortDirParam) : 'desc';
		$orderStatuses = $request->getQueryParam('orderStatus', []);
		$paidFilters = $request->getQueryParam('paymentStatus', []);

		if (is_string($orderStatuses) && $orderStatuses !== '') {
			$orderStatuses = [$orderStatuses];
		} elseif (! is_array($orderStatuses)) {
			$orderStatuses = [];
		}

		if (is_string($paidFilters) && $paidFilters !== '') {
			$paidFilters = [$paidFilters];
		} elseif (! is_array($paidFilters)) {
			$paidFilters = [];
		}

		$ordersQuery = Order::find()
			->isCompleted(true)
			->dateOrdered(['and', '>= ' . $dateRange['fromDT'], '<= ' . $dateRange['toDT']])
			->orderBy($this->resolveOrderSort($sort, $sortDir));

		if ($orderStatuses !== []) {
			$ordersQuery->andWhere([
				'[[orderStatusId]]' => (new Query())
					->select('[[id]]')
					->from('{{%commerce_orderstatuses}}')
					->where([
						'[[handle]]' => $orderStatuses,
					]),
			]);
		}

		if ($paidFilters !== []) {
			$paymentConditions = ['or'];
			foreach ($paidFilters as $paidFilter) {
				if ($paidFilter === 'paid') {
					$paymentConditions[] = '[[totalPaid]] >= [[totalPrice]]';
				} elseif ($paidFilter === 'partial') {
					$paymentConditions[] = ['and', '[[totalPaid]] > 0', '[[totalPaid]] < [[totalPrice]]'];
				} elseif ($paidFilter === 'unpaid') {
					$paymentConditions[] = [
						'[[totalPaid]]' => 0,
					];
				}
			}

			if (count($paymentConditions) > 1) {
				$ordersQuery->andWhere($paymentConditions);
			}
		}

		if ($search !== '') {
			$ordersQuery->andWhere(['or',
				['like', '[[reference]]', $search],
				['like', '[[email]]', $search],
				['like', '[[number]]', $search],
			]);
		}

		return $ordersQuery;
	}

	/**
	 * Build formatted row data from Order elements.
	 *
	 * @param array<Order> $orders
	 * @return list<array<string, mixed>>
	 */
	private function buildOrderRows(array $orders): array
	{
		$orderItemCounts = [];
		if ($orders !== []) {
			$orderIds = array_map(fn ($order): ?int => $order->id, $orders);
			$itemCounts = (new Query())
				->select([
					'orderId',
					'totalItems' => 'COALESCE(SUM([[qty]]), 0)',
				])
				->from('{{%commerce_lineitems}}')
				->where(['in', 'orderId', $orderIds])
				->groupBy('orderId')
				->all();
			foreach ($itemCounts as $itemCount) {
				/** @var array{orderId: int, totalItems: string} $itemCount */
				$orderItemCounts[$itemCount['orderId']] = (int) $itemCount['totalItems'];
			}
		}

		$rows = [];
		foreach ($orders as $order) {
			$statusColor = $order->orderStatus->color;
			$statusName = $order->orderStatus->name;

			// Use per-order currency for accuracy
			$currency = $order->currency;

			$rows[] = [
				'reference' => $order->reference,
				'cpEditUrl' => $order->cpEditUrl,
				'dateOrdered' => $order->dateOrdered ? $order->dateOrdered->format('m/d/Y g:ia') : '',
				'statusColor' => $statusColor,
				'statusName' => $statusName,
				'statusHandle' => $order->orderStatus->handle,
				'itemSubtotal' => Craft::$app->getFormatter()->asCurrency($order->itemSubtotal, $currency),
				'totalTax' => Craft::$app->getFormatter()->asCurrency($order->totalTax, $currency),
				'totalDiscount' => Craft::$app->getFormatter()->asCurrency($order->totalDiscount, $currency),
				'totalShippingCost' => Craft::$app->getFormatter()->asCurrency($order->totalShippingCost, $currency),
				'totalPaid' => Craft::$app->getFormatter()->asCurrency($order->totalPaid, $currency),
				'itemsSold' => $orderItemCounts[$order->id] ?? 0,
				'paidStatus' => $order->paidStatus,
				'paidStatusHtml' => $order->paidStatusHtml,
				'email' => $order->email ?? '',
				'billingName' => $order->billingAddress ? $order->billingAddress->fullName : '',
				'shippingName' => $order->shippingAddress ? $order->shippingAddress->fullName : '',
			];
		}

		return $rows;
	}

	/**
	 * @return array<string, int>
	 */
	private function resolveOrderSort(string $sort, string $sortDir): array
	{
		$allowedColumns = [
			'reference', 'dateOrdered', 'orderStatusId', 'itemSubtotal',
			'totalTax', 'totalDiscount', 'totalShippingCost', 'totalPaid', 'totalPrice',
		];

		$direction = strtolower($sortDir) === 'asc' ? SORT_ASC : SORT_DESC;

		if ($sort === 'paidStatus') {
			return [
				'totalPaid' => $direction,
			];
		}

		if (in_array($sort, $allowedColumns, true)) {
			return [
				$sort => $direction,
			];
		}

		return [
			'dateOrdered' => SORT_DESC,
		];
	}
}
