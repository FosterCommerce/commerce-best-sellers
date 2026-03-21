<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\helpers\MoneyHelper;
use craft\web\Request;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\models\DateRangeResult;
use fostercommerce\bestsellers\Plugin;
use Money\Money;
use yii\db\Expression;
use yii\web\Response;

class OrdersController extends BaseReportController
{
	private const PER_PAGE = 100;

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$scope = $this->resolveScope();
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);

		$operationsStats = $plugin->operationsStats;
		$shippingMethods = $operationsStats->getShippingMethods($scope);
		$topDiscounts = $operationsStats->getTopDiscounts($scope, 20);

		return $this->renderTemplate('best-sellers/_sales', [
			'title' => Craft::t('best-sellers', 'Orders'),
			'selectedSubnavItem' => 'orders',
			'from' => $scope->from,
			'to' => $scope->to,
			'preset' => $scope->preset,
			'scope' => $scope,
			'shippingMethods' => $shippingMethods,
			'topDiscounts' => $topDiscounts,
		]);
	}

	/**
	 * AJAX endpoint for paginated orders data.
	 */
	public function actionOrdersData(): Response
	{
		$this->requireAcceptsJson();

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveScope();

		/** @var int|string $page */
		$page = $request->getQueryParam('page', 1);
		$page = max(1, (int) $page);

		$offset = ($page - 1) * self::PER_PAGE;

		$ordersQuery = $this->buildFilteredOrdersQuery($dateRange);

		$totalOrders = (int) $ordersQuery->count();
		$totalPages = max(1, (int) ceil($totalOrders / self::PER_PAGE));

		// Aggregate totals across all filtered results (before pagination)
		$totals = $this->buildFilteredTotals($dateRange);

		$orders = $ordersQuery
			->offset($offset)
			->limit(self::PER_PAGE)
			->all();

		$rows = $this->buildOrderRows($orders);

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
		$dateRange = $this->resolveScope();
		$ordersQuery = $this->buildFilteredOrdersQuery($dateRange);

		$orders = $ordersQuery->all();
		$rows = $this->buildOrderRows($orders);

		$csvRows = [];
		$currency = $this->getStoreCurrency();
		$totalMerchandise = new Money(0, $currency);
		$totalTax = new Money(0, $currency);
		$totalDiscount = new Money(0, $currency);
		$totalShipping = new Money(0, $currency);
		$totalPaid = new Money(0, $currency);
		$totalItemsSold = 0;

		foreach ($orders as $index => $order) {
			$totalMerchandise = $totalMerchandise->add($this->toMoney($order->itemSubtotal));
			$totalTax = $totalTax->add($this->toMoney($order->totalTax));
			$totalDiscount = $totalDiscount->add($this->toMoney($order->totalDiscount));
			$totalShipping = $totalShipping->add($this->toMoney($order->totalShippingCost));
			$totalPaid = $totalPaid->add($this->toMoney($order->totalPaid));
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
			Craft::t('best-sellers', 'Order #'),
			Craft::t('best-sellers', 'Date Ordered'),
			Craft::t('best-sellers', 'Status'),
			Craft::t('best-sellers', 'Email'),
			Craft::t('best-sellers', 'Merchandise Total'),
			Craft::t('best-sellers', 'Tax'),
			Craft::t('best-sellers', 'Discount'),
			Craft::t('best-sellers', 'Shipping'),
			Craft::t('best-sellers', 'Total Paid'),
			Craft::t('best-sellers', 'Items Sold'),
			Craft::t('best-sellers', 'Payment Status'),
		], 'orders');
	}

	private function buildFilteredOrdersQuery(DateRangeResult $dateRange): OrderQuery
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		/** @var string $rawSort */
		$rawSort = $request->getQueryParam('sort', 'dateOrdered');
		$sort = trim($rawSort);
		/** @var string $rawSortDir */
		$rawSortDir = $request->getQueryParam('sortDir', 'desc');
		$sortDir = trim($rawSortDir);

		$ordersQuery = Order::find()
			->isCompleted(true)
			->dateOrdered(['and', '>= ' . $dateRange->fromDT, '<= ' . $dateRange->toDT])
			->orderBy($this->resolveOrderSort($sort, $sortDir));

		$this->applyOrderFilters($ordersQuery);

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
				->from(CommerceTable::LINEITEMS)
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

	/**
	 * Aggregate totals across all filtered orders (not just the current page).
	 *
	 * @return array<string, string>
	 */
	private function buildFilteredTotals(DateRangeResult $dateRange): array
	{
		/** @var array{itemSubtotal: string, totalTax: string, totalDiscount: string, totalShippingCost: string, totalPaid: string}|false $sums */
		$sums = $this->buildFilteredTotalsQuery($dateRange)
			->select([
				'itemSubtotal' => 'COALESCE(SUM([[itemSubtotal]]), 0)',
				'totalTax' => 'COALESCE(SUM([[totalTax]]), 0)',
				'totalDiscount' => 'COALESCE(SUM([[totalDiscount]]), 0)',
				'totalShippingCost' => 'COALESCE(SUM([[totalShippingCost]]), 0)',
				'totalPaid' => 'COALESCE(SUM([[totalPaid]]), 0)',
			])
			->one();

		if (! $sums) {
			return [
				'itemSubtotal' => $this->formatCurrency(0),
				'totalTax' => $this->formatCurrency(0),
				'totalDiscount' => $this->formatCurrency(0),
				'totalShippingCost' => $this->formatCurrency(0),
				'totalPaid' => $this->formatCurrency(0),
				'itemsSold' => '0',
			];
		}

		$idSubquery = $this->buildFilteredTotalsQuery($dateRange)
			->select(['[[id]]']);

		$totalItemsSold = (int) (new Query())
			->select(new Expression('COALESCE(SUM([[qty]]), 0)'))
			->from(CommerceTable::LINEITEMS)
			->where(['in', '[[orderId]]', $idSubquery])
			->scalar();

		return [
			'itemSubtotal' => $this->formatCurrency((float) $sums['itemSubtotal']),
			'totalTax' => $this->formatCurrency((float) $sums['totalTax']),
			'totalDiscount' => $this->formatCurrency((float) $sums['totalDiscount']),
			'totalShippingCost' => $this->formatCurrency((float) $sums['totalShippingCost']),
			'totalPaid' => $this->formatCurrency((float) $sums['totalPaid']),
			'itemsSold' => number_format($totalItemsSold),
		];
	}

	/**
	 * Build a raw query on the orders table with the same filters as the element query.
	 *
	 * @return Query<array-key, mixed>
	 */
	private function buildFilteredTotalsQuery(DateRangeResult $dateRange): Query
	{
		$query = (new Query())
			->from(CommerceTable::ORDERS)
			->where([
				'and',
				[
					'[[isCompleted]]' => true,
				],
				['>=', '[[dateOrdered]]', $dateRange->fromDT],
				['<=', '[[dateOrdered]]', $dateRange->toDT],
			]);

		$this->applyOrderFilters($query);

		return $query;
	}

	/**
	 * Apply shared order filters (status, payment, search, shipping, discounts) to a query.
	 *
	 * @template TQuery of OrderQuery|Query<array-key, mixed>
	 * @param TQuery $query
	 */
	private function applyOrderFilters(OrderQuery|Query $query): void
	{
		// Element queries join multiple tables; plain queries have a single table
		$idCol = $query instanceof OrderQuery ? '[[commerce_orders.id]]' : '[[id]]';
		$statusIdCol = $query instanceof OrderQuery ? '[[commerce_orders.orderStatusId]]' : '[[orderStatusId]]';

		/** @var Request $request */
		$request = Craft::$app->getRequest();

		/** @var string $rawSearch */
		$rawSearch = $request->getQueryParam('search', '');
		$search = trim($rawSearch);

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

		if ($orderStatuses !== []) {
			$query->andWhere([
				$statusIdCol => (new Query())
					->select('[[id]]')
					->from(CommerceTable::ORDERSTATUSES)
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
				$query->andWhere($paymentConditions);
			}
		}

		if ($search !== '') {
			$query->andWhere([
				'or',
				['like', '[[reference]]', $search],
				['like', '[[email]]', $search],
				['like', '[[number]]', $search],
			]);
		}

		// Shipping method filter
		/** @var string $shippingMethod */
		$shippingMethod = $request->getQueryParam('shippingMethod', '');
		if ($shippingMethod !== '') {
			if ($shippingMethod === 'None') {
				$query->andWhere([
					'or',
					[
						'[[shippingMethodName]]' => null,
					],
					[
						'[[shippingMethodName]]' => '',
					],
				]);
			} else {
				$query->andWhere([
					'[[shippingMethodName]]' => $shippingMethod,
				]);
			}
		}

		// Discount status filter (discounted vs full-price)
		/** @var string $discountStatus */
		$discountStatus = $request->getQueryParam('discountStatus', '');
		if ($discountStatus === 'discounted') {
			$query->andWhere(['<', '[[totalDiscount]]', 0]);
		} elseif ($discountStatus === 'fullPrice') {
			$query->andWhere([
				'or',
				['>=', '[[totalDiscount]]', 0],
				[
					'[[totalDiscount]]' => null,
				],
			]);
		}

		// Discount ID filter (orders using a specific discount)
		/** @var string $rawDiscountId */
		$rawDiscountId = $request->getQueryParam('discountId', '');
		if ($rawDiscountId !== '') {
			$discountId = (int) $rawDiscountId;
			$isMysql = Craft::$app->getDb()->getIsMysql();
			$idExpr = $isMysql
				? new Expression("JSON_EXTRACT([[sourceSnapshot]], '$.id') = :discountId", [
					':discountId' => $discountId,
				])
				: new Expression("(([[sourceSnapshot]])::json->>'id')::int = :discountId", [
					':discountId' => $discountId,
				]);

			$query->andWhere([
				$idCol => (new Query())
					->select('DISTINCT [[orderId]]')
					->from(CommerceTable::ORDERADJUSTMENTS)
					->where([
						'[[type]]' => 'discount',
					])
					->andWhere($idExpr),
			]);
		}

		// Items per order bucket filter
		/** @var string $itemsBucket */
		$itemsBucket = $request->getQueryParam('itemsPerOrder', '');
		if ($itemsBucket !== '') {
			$itemCountSubquery = (new Query())
				->select('[[lineItems.orderId]]')
				->from([
					'lineItems' => CommerceTable::LINEITEMS,
				])
				->groupBy('[[lineItems.orderId]]');

			$bucketRanges = [
				'1' => [1, 1],
				'2' => [2, 2],
				'3' => [3, 3],
				'4-5' => [4, 5],
				'6-10' => [6, 10],
				'11+' => [11, null],
			];

			if (isset($bucketRanges[$itemsBucket])) {
				[$min, $max] = $bucketRanges[$itemsBucket];
				$itemCountSubquery->having(['>=', 'SUM([[lineItems.qty]])', $min]);
				if ($max !== null) {
					$itemCountSubquery->andHaving(['<=', 'SUM([[lineItems.qty]])', $max]);
				}

				$query->andWhere([
					$idCol => $itemCountSubquery,
				]);
			}
		}
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
