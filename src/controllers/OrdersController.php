<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\MoneyHelper;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\Plugin;
use Money\Currency;
use Money\Money;
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

		/** @var int|string $page */
		$page = $request->getQueryParam('page', 1);
		$page = max(1, (int) $page);

		$offset = ($page - 1) * self::PER_PAGE;

		$ordersQuery = $this->buildFilteredOrdersQuery($dateRange);

		$totalOrders = (int) $ordersQuery->count();
		$totalPages = max(1, (int) ceil($totalOrders / self::PER_PAGE));

		$orders = $ordersQuery
			->offset($offset)
			->limit(self::PER_PAGE)
			->all();

		$rows = $this->buildOrderRows($orders);

		$currency = $this->getStoreCurrency();
		$mCurrency = new Currency($currency);
		$totalItemSubtotal = new Money(0, $mCurrency);
		$totalTax = new Money(0, $mCurrency);
		$totalDiscount = new Money(0, $mCurrency);
		$totalShipping = new Money(0, $mCurrency);
		$totalPaid = new Money(0, $mCurrency);
		$totalItemsSold = 0;

		foreach ($orders as $order) {
			$totalItemSubtotal = $totalItemSubtotal->add($this->toMoney($order->itemSubtotal, $mCurrency));
			$totalTax = $totalTax->add($this->toMoney($order->totalTax, $mCurrency));
			$totalDiscount = $totalDiscount->add($this->toMoney($order->totalDiscount, $mCurrency));
			$totalShipping = $totalShipping->add($this->toMoney($order->totalShippingCost, $mCurrency));
			$totalPaid = $totalPaid->add($this->toMoney($order->totalPaid, $mCurrency));
		}

		foreach ($rows as $row) {
			$totalItemsSold += $row['itemsSold'];
		}

		$totals = [
			'itemSubtotal' => Craft::$app->getFormatter()->asCurrency(MoneyHelper::toDecimal($totalItemSubtotal), $currency),
			'totalTax' => Craft::$app->getFormatter()->asCurrency(MoneyHelper::toDecimal($totalTax), $currency),
			'totalDiscount' => Craft::$app->getFormatter()->asCurrency(MoneyHelper::toDecimal($totalDiscount), $currency),
			'totalShippingCost' => Craft::$app->getFormatter()->asCurrency(MoneyHelper::toDecimal($totalShipping), $currency),
			'totalPaid' => Craft::$app->getFormatter()->asCurrency(MoneyHelper::toDecimal($totalPaid), $currency),
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
		$mCurrency = new Currency($this->getStoreCurrency());
		$totalMerchandise = new Money(0, $mCurrency);
		$totalTax = new Money(0, $mCurrency);
		$totalDiscount = new Money(0, $mCurrency);
		$totalShipping = new Money(0, $mCurrency);
		$totalPaid = new Money(0, $mCurrency);
		$totalItemsSold = 0;

		foreach ($orders as $index => $order) {
			$totalMerchandise = $totalMerchandise->add($this->toMoney($order->itemSubtotal, $mCurrency));
			$totalTax = $totalTax->add($this->toMoney($order->totalTax, $mCurrency));
			$totalDiscount = $totalDiscount->add($this->toMoney($order->totalDiscount, $mCurrency));
			$totalShipping = $totalShipping->add($this->toMoney($order->totalShippingCost, $mCurrency));
			$totalPaid = $totalPaid->add($this->toMoney($order->totalPaid, $mCurrency));
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
			'merchandiseTotal' => MoneyHelper::toDecimal($totalMerchandise),
			'tax' => MoneyHelper::toDecimal($totalTax),
			'discount' => MoneyHelper::toDecimal($totalDiscount),
			'shipping' => MoneyHelper::toDecimal($totalShipping),
			'totalPaid' => MoneyHelper::toDecimal($totalPaid),
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
	 * @param array{fromDT: string, toDT: string} $dateRange
	 */
	private function buildFilteredOrdersQuery(array $dateRange): \craft\commerce\elements\db\OrderQuery
	{
		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();

		/** @var string $rawSearch */
		$rawSearch = $request->getQueryParam('search', '');
		$search = trim($rawSearch);
		/** @var string $rawSort */
		$rawSort = $request->getQueryParam('sort', 'dateOrdered');
		$sort = trim($rawSort);
		/** @var string $rawSortDir */
		$rawSortDir = $request->getQueryParam('sortDir', 'desc');
		$sortDir = trim($rawSortDir);

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
			$currency = $order->currency;

			$rows[] = [
				'reference' => $order->reference,
				'cpEditUrl' => $order->cpEditUrl,
				'dateOrdered' => $order->dateOrdered ? $order->dateOrdered->format('m/d/Y g:ia') : '',
				'statusColor' => $order->orderStatus->color,
				'statusName' => $order->orderStatus->name,
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

	private function toMoney(float $amount, Currency $currency): Money
	{
		/** @var Money $money */
		$money = MoneyHelper::toMoney([
			'value' => (string) $amount,
			'currency' => $currency->getCode(),
		]);

		return $money;
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
