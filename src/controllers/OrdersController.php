<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\db\Query;
use craft\helpers\MoneyHelper;
use craft\web\Request;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\VariantTitleHelper;
use fostercommerce\bestsellers\models\DateRangeResult;
use fostercommerce\bestsellers\Plugin;
use Money\Money;
use yii\db\Expression;
use yii\web\Response;

class OrdersController extends BaseReportController
{
	private const PER_PAGE = 100;

	private const SESSION_KEY_PAYMENT_STATUSES = 'bestSellers.scope.paymentStatuses';

	/**
	 * Fallback payment statuses applied on the user's first visit to the
	 * Orders page in a session. Paid + Partial + Overpaid matches the most
	 * common "money in (any amount)" view, and surfaces overpaid anomalies
	 * by default rather than burying them behind an opt-in checkbox.
	 *
	 * @var list<string>
	 */
	private const DEFAULT_PAYMENT_STATUSES = ['paid', 'partial', 'overpaid'];

	/**
	 * Maps the lowercase UI filter values to the camelCase strings Commerce
	 * persists in [[commerce_orders.paidStatus]]. Source of truth for the
	 * column values: craft\commerce\elements\Order::PAID_STATUS_* constants.
	 *
	 * @var array<string, string>
	 */
	private const PAID_STATUS_COLUMN_MAP = [
		'paid' => 'paid',
		'partial' => 'partial',
		'unpaid' => 'unpaid',
		'overpaid' => 'overPaid',
	];

	/**
	 * Cached resolution so resolvePaymentStatusSelection() can be called from
	 * both actionIndex (for server-rendered checkbox state) and applyOrderFilters
	 * (for the WHERE clause) without double-reading the request or session.
	 *
	 * @var list<string>|null
	 */
	private ?array $resolvedPaymentStatuses = null;

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$scope = $this->resolveScope();
		$plugin = Plugin::getInstance();

		$operationsStats = $plugin->operationsStats;
		$shippingMethods = $operationsStats->getShippingMethods($scope);
		$topDiscounts = $operationsStats->getTopDiscounts($scope, 20);

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		/** @var string|int $rawProductId */
		$rawProductId = $request->getQueryParam('productId', 0);
		/** @var string|int $rawVariantId */
		$rawVariantId = $request->getQueryParam('variantId', 0);
		$productId = (int) $rawProductId;
		$variantId = (int) $rawVariantId;
		$purchasableFilterLabel = '';

		if ($variantId > 0) {
			/** @var ?Variant $variant */
			$variant = Variant::find()->id($variantId)->status(null)->one();
			if ($variant !== null) {
				$owner = $variant->getOwner();
				$purchasableFilterLabel = VariantTitleHelper::buildDisplayTitle($owner?->title ?? '', $variant->title);
			}
		} elseif ($productId > 0) {
			/** @var ?Product $product */
			$product = Product::find()->id($productId)->status(null)->one();
			$purchasableFilterLabel = $product?->title ?? '';
		}

		return $this->renderTemplate('best-sellers/_sales', [
			'title' => Craft::t('best-sellers', 'Orders'),
			'selectedSubnavItem' => 'orders',
			'from' => $scope->from,
			'to' => $scope->to,
			'preset' => $scope->preset,
			'scope' => $scope,
			'shippingMethods' => $shippingMethods,
			'topDiscounts' => $topDiscounts,
			'productId' => $productId,
			'variantId' => $variantId,
			'purchasableFilterLabel' => $purchasableFilterLabel,
			'selectedPaymentStatuses' => $this->resolvePaymentStatusSelection(),
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

		$ordersQuery = $this->buildFilteredOrdersQuery($dateRange->dateRange);

		$totalOrders = (int) $ordersQuery->count();
		$totalPages = max(1, (int) ceil($totalOrders / self::PER_PAGE));

		// Aggregate totals across all filtered results (before pagination)
		$totals = $this->buildFilteredTotals(clone $ordersQuery);

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
		$ordersQuery = $this->buildFilteredOrdersQuery($dateRange->dateRange);

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
				'merchandiseTotal' => MoneyHelper::toDecimal($this->toMoney($order->itemSubtotal)),
				'tax' => MoneyHelper::toDecimal($this->toMoney($order->totalTax)),
				'discount' => MoneyHelper::toDecimal($this->toMoney($order->totalDiscount)),
				'shipping' => MoneyHelper::toDecimal($this->toMoney($order->totalShippingCost)),
				'totalPaid' => MoneyHelper::toDecimal($this->toMoney($order->totalPaid)),
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
			Craft::t('best-sellers', 'Item Subtotal'),
			Craft::t('best-sellers', 'Tax'),
			Craft::t('best-sellers', 'Discount'),
			Craft::t('best-sellers', 'Shipping'),
			Craft::t('best-sellers', 'Total Paid'),
			Craft::t('best-sellers', 'Items Sold'),
			Craft::t('best-sellers', 'Payment Status'),
		], 'orders');
	}

	/**
	 * Resolve which payment-status checkboxes should be applied.
	 *
	 * Mirrors the order-status pattern in DateRange::resolveScope():
	 * query param wins (and persists to session), otherwise session value
	 * is used, otherwise the hard-coded DEFAULT_PAYMENT_STATUSES.
	 *
	 * Sending `paymentStatus=` (empty string scalar) is treated as an
	 * explicit clear and stores [] in the session, distinct from "never
	 * touched" which falls through to defaults.
	 *
	 * @return list<string>
	 */
	private function resolvePaymentStatusSelection(): array
	{
		if ($this->resolvedPaymentStatuses !== null) {
			return $this->resolvedPaymentStatuses;
		}

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$session = Craft::$app->getSession();

		$param = $request->getQueryParam('paymentStatus', null);

		if ($param !== null) {
			if (is_string($param)) {
				$selection = $param !== '' ? [$param] : [];
			} elseif (is_array($param)) {
				$selection = array_values(array_filter($param, 'is_string'));
			} else {
				$selection = [];
			}

			$session->set(self::SESSION_KEY_PAYMENT_STATUSES, $selection);
			$this->resolvedPaymentStatuses = $selection;
			return $this->resolvedPaymentStatuses;
		}

		if ($session->has(self::SESSION_KEY_PAYMENT_STATUSES)) {
			$stored = $session->get(self::SESSION_KEY_PAYMENT_STATUSES, []);
			$this->resolvedPaymentStatuses = is_array($stored)
				? array_values(array_filter($stored, 'is_string'))
				: [];
			return $this->resolvedPaymentStatuses;
		}

		$this->resolvedPaymentStatuses = self::DEFAULT_PAYMENT_STATUSES;
		return $this->resolvedPaymentStatuses;
	}

	/**
	 * OrderQuery (element query) because we need full Order elements for rendering
	 * rows with cpEditUrl, status colors, Money objects, etc.
	 */
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
			// Raw Query because we need a simple aggregate from commerce_lineitems,
			// not Order elements. Faster than loading line item elements.
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
	 * Materializes filtered order IDs from the same OrderQuery used for rows,
	 * then runs raw SUM aggregates against that ID list. Guarantees totals
	 * match the rendered rows (same TZ handling, soft-delete exclusion, and
	 * request filters). The IDs list scales with the filter result count:
	 * for very large unfiltered windows the IN(...) literal can get big, but
	 * a raw aggregate Query cannot reuse the OrderQuery as a subquery because
	 * ElementQuery::prepare merges in 10+ default SELECT columns, which an
	 * `IN (subquery)` clause cannot consume.
	 *
	 * Takes the prebuilt orders query (cloned) rather than rebuilding it so
	 * the filter wiring happens exactly once per request. `ids()` mutates the
	 * passed query, so callers must clone before passing.
	 *
	 * @return array<string, string>
	 */
	private function buildFilteredTotals(OrderQuery $ordersQuery): array
	{
		$orderIds = $ordersQuery->ids();

		if ($orderIds === []) {
			return [
				'itemSubtotal' => $this->formatCurrency(0),
				'totalTax' => $this->formatCurrency(0),
				'totalDiscount' => $this->formatCurrency(0),
				'totalShippingCost' => $this->formatCurrency(0),
				'totalPaid' => $this->formatCurrency(0),
				'itemsSold' => '0',
			];
		}

		/** @var array{itemSubtotal: string, totalTax: string, totalDiscount: string, totalShippingCost: string, totalPaid: string}|false $sums */
		$sums = (new Query())
			->select([
				'itemSubtotal' => 'COALESCE(SUM([[itemSubtotal]]), 0)',
				'totalTax' => 'COALESCE(SUM([[totalTax]]), 0)',
				'totalDiscount' => 'COALESCE(SUM([[totalDiscount]]), 0)',
				'totalShippingCost' => 'COALESCE(SUM([[totalShippingCost]]), 0)',
				'totalPaid' => 'COALESCE(SUM([[totalPaid]]), 0)',
			])
			->from(CommerceTable::ORDERS)
			->where([
				'[[id]]' => $orderIds,
			])
			->one();

		$totalItemsSold = (int) (new Query())
			->select(new Expression('COALESCE(SUM([[qty]]), 0)'))
			->from(CommerceTable::LINEITEMS)
			->where([
				'[[orderId]]' => $orderIds,
			])
			->scalar();

		return [
			'itemSubtotal' => $this->formatCurrency((float) ($sums['itemSubtotal'] ?? 0)),
			'totalTax' => $this->formatCurrency((float) ($sums['totalTax'] ?? 0)),
			'totalDiscount' => $this->formatCurrency((float) ($sums['totalDiscount'] ?? 0)),
			'totalShippingCost' => $this->formatCurrency((float) ($sums['totalShippingCost'] ?? 0)),
			'totalPaid' => $this->formatCurrency((float) ($sums['totalPaid'] ?? 0)),
			'itemsSold' => number_format($totalItemsSold),
		];
	}

	/**
	 * Apply shared order filters (status, payment, search, shipping, discounts) to a query.
	 *
	 * Accepts both OrderQuery (element query for paginated rows) and raw Query
	 * (for aggregate totals). Column references must be qualified for OrderQuery
	 * because it joins craft_elements, craft_addresses, etc., making bare column
	 * names ambiguous.
	 *
	 * @template TQuery of OrderQuery|Query<array-key, mixed>
	 * @param TQuery $query
	 */
	private function applyOrderFilters(OrderQuery|Query $query): void
	{
		$idCol = $query instanceof OrderQuery ? '[[commerce_orders.id]]' : '[[id]]';
		$statusIdCol = $query instanceof OrderQuery ? '[[commerce_orders.orderStatusId]]' : '[[orderStatusId]]';

		/** @var Request $request */
		$request = Craft::$app->getRequest();

		/** @var string $rawSearch */
		$rawSearch = $request->getQueryParam('search', '');
		$search = trim($rawSearch);

		$paidFilters = $this->resolvePaymentStatusSelection();

		// Order status comes from the global scope (date-picker header), not a
		// per-page filter. Session-persisted through DateRange::resolveScope().
		$scope = $this->resolveScope();
		if ($scope->orderStatusIds !== []) {
			$query->andWhere([
				$statusIdCol => $scope->orderStatusIds,
			]);
		}

		if ($paidFilters !== []) {
			$paidStatusCol = $query instanceof OrderQuery ? '[[commerce_orders.paidStatus]]' : '[[paidStatus]]';
			$columnValues = [];
			foreach ($paidFilters as $paidFilter) {
				if (isset(self::PAID_STATUS_COLUMN_MAP[$paidFilter])) {
					$columnValues[] = self::PAID_STATUS_COLUMN_MAP[$paidFilter];
				}
			}

			if ($columnValues !== []) {
				$query->andWhere([
					$paidStatusCol => $columnValues,
				]);
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

		// Product / variant filter: limit to orders that include this purchasable
		// (directly or via a bundle constituent). Queries best_sellers_variant_sales
		// so bundle children, which appear in variant_sales but not as line item
		// purchasables, are matched. Matches the source the Products report's
		// "in N orders" count was computed from.
		/** @var string|int $rawProductId */
		$rawProductId = $request->getQueryParam('productId', 0);
		/** @var string|int $rawVariantId */
		$rawVariantId = $request->getQueryParam('variantId', 0);
		$productId = (int) $rawProductId;
		$variantId = (int) $rawVariantId;

		if ($variantId > 0) {
			$query->andWhere([
				$idCol => (new Query())
					->select('DISTINCT [[orderId]]')
					->from(Table::VARIANT_SALES)
					->where([
						'[[variantId]]' => $variantId,
					]),
			]);
			// Mirror the Products report exclusion: drop orders with a full
			// balance owed (totalPaid <= 0 AND totalPrice > 0) so the count on
			// the Products page link matches the Orders page result.
			$query->andWhere([
				'not', [
					'and',
					['<=', '[[totalPaid]]', 0],
					['>', '[[totalPrice]]', 0],
				],
			]);
		} elseif ($productId > 0) {
			$query->andWhere([
				$idCol => (new Query())
					->select('DISTINCT [[orderId]]')
					->from(Table::VARIANT_SALES)
					->where([
						'[[productId]]' => $productId,
					]),
			]);
			$query->andWhere([
				'not', [
					'and',
					['<=', '[[totalPaid]]', 0],
					['>', '[[totalPrice]]', 0],
				],
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
