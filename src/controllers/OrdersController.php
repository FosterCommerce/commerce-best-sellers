<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\base\FieldInterface;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\actions\DownloadOrderPdfAction;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\fields\BaseRelationField;
use craft\helpers\App;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Html;
use craft\helpers\MoneyHelper;
use craft\web\Request;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\VariantTitleHelper;
use fostercommerce\bestsellers\models\DateRangeResult;
use fostercommerce\bestsellers\Plugin;
use Generator;
use Illuminate\Support\Collection;
use Money\Money;
use Throwable;
use yii\db\Expression;
use yii\web\BadRequestHttpException;
use yii\web\Response;
use yii\web\ServerErrorHttpException;

class OrdersController extends BaseReportController
{
	private const PER_PAGE = 100;

	private const EXPORT_BATCH_SIZE = 500;

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

	/**
	 * @var list<int>|null
	 */
	private ?array $shippedStatusIds = null;

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

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$primaryStore = $commerce->getStores()->getPrimaryStore();
		// Load PDFs only for users who can run the bulk download action
		$enabledPdfs = $primaryStore !== null && Craft::$app->getUser()->checkPermission('commerce-manageOrders')
			? $commerce->getPdfs()->getAllEnabledPdfs($primaryStore->id)
			: new Collection();

		return $this->renderTemplate('best-sellers/_orders', [
			'title' => Craft::t('commerce', 'Orders'),
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
			'enabledPdfs' => $enabledPdfs,
			'orderFieldFilters' => $this->getOrderFieldFilters(),
			'selectedOrderFieldValues' => $this->resolveFieldFilterValues('orderField'),
			'showDateShipped' => $this->getShippedStatusIds() !== [],
		]);
	}

	/**
	 * Bulk-download selected orders as PDFs. Gated behind Commerce's own
	 * commerce-manageOrders permission since the action produces order
	 * documents (invoices, packing slips with PII).
	 *
	 * @throws Throwable
	 */
	public function actionDownloadPdfs(): Response
	{
		$this->requirePostRequest();
		$this->requirePermission('commerce-manageOrders');

		/** @var Request $request */
		$request = Craft::$app->getRequest();

		$rawPdfId = $request->getRequiredBodyParam('pdfId');
		$rawDownloadType = $request->getRequiredBodyParam('downloadType');
		$rawOrderIds = $request->getRequiredBodyParam('orderIds');

		if (! is_numeric($rawPdfId) || ! is_string($rawDownloadType) || ! is_array($rawOrderIds)) {
			throw new BadRequestHttpException(Craft::t('best-sellers', 'orders.bulk.error.invalidRequest'));
		}

		$validTypes = [
			DownloadOrderPdfAction::TYPE_ZIP_ARCHIVE,
			DownloadOrderPdfAction::TYPE_PDF_COLLATED,
		];
		if (! in_array($rawDownloadType, $validTypes, true)) {
			throw new BadRequestHttpException(Craft::t('best-sellers', 'orders.bulk.error.invalidDownloadType'));
		}

		$orderIds = array_values(array_unique(array_map('intval', array_filter($rawOrderIds, 'is_scalar'))));
		if ($orderIds === []) {
			throw new BadRequestHttpException(Craft::t('best-sellers', 'orders.bulk.error.noOrdersSelected'));
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$primaryStore = $commerce->getStores()->getPrimaryStore();
		if ($primaryStore === null) {
			throw new ServerErrorHttpException(Craft::t('best-sellers', 'orders.bulk.error.primaryStoreMissing'));
		}

		$action = new DownloadOrderPdfAction([
			'storeId' => $primaryStore->id,
			'pdfId' => (int) $rawPdfId,
			'downloadType' => $rawDownloadType,
		]);

		$ordersQuery = Order::find()
			->id($orderIds)
			->isCompleted(true);

		if (! $action->performAction($ordersQuery)) {
			throw new BadRequestHttpException(Craft::t('best-sellers', 'orders.bulk.error.noMatchingOrders'));
		}

		/** @var \craft\web\Response $response */
		$response = Craft::$app->getResponse();
		return $response;
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

		$orderFields = Plugin::getInstance()->reportFields->getOrderFields();
		$orders = $ordersQuery
			->offset($offset)
			->limit(self::PER_PAGE)
			->all();

		$rows = $this->buildOrderRows($orders, $orderFields);
		foreach ($rows as &$row) {
			/** @var array<string, string> $fieldValues */
			$fieldValues = $row['fields'];
			// Encode field values, since the table builds rows with innerHTML
			$row['fields'] = array_map(Html::encode(...), $fieldValues);
		}

		unset($row);

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
		// Lift the memory and time limits for date ranges with tens of thousands of orders
		App::maxPowerCaptain();

		$dateRange = $this->resolveScope();
		$ordersQuery = $this->buildFilteredOrdersQuery($dateRange->dateRange);
		$orderFields = Plugin::getInstance()->reportFields->getOrderFields();
		$showDateShipped = $this->getShippedStatusIds() !== [];

		$headers = [
			Craft::t('best-sellers', 'sales.col.orderNumber'),
			Craft::t('commerce', 'Date Ordered'),
			Craft::t('app', 'Status'),
			Craft::t('app', 'Email'),
			Craft::t('commerce', 'Item Subtotal'),
			Craft::t('commerce', 'Tax'),
			Craft::t('commerce', 'Discount'),
			Craft::t('commerce', 'Shipping'),
			Craft::t('commerce', 'Total Paid'),
			Craft::t('best-sellers', 'kpi.itemsSold'),
			Craft::t('best-sellers', 'sales.filter.paymentStatus'),
		];
		if ($showDateShipped) {
			$headers[] = Craft::t('best-sellers', 'sales.col.dateShipped');
		}

		foreach ($orderFields as $orderField) {
			$headers[] = Craft::t('site', (string) $orderField->name);
		}

		return $this->asCsv($this->generateCsvRows($ordersQuery, $orderFields, $showDateShipped), $headers, 'orders');
	}

	/**
	 * Yield one CSV row per order, loading orders in batches, then the totals row.
	 *
	 * @param array<string, FieldInterface> $orderFields
	 * @return Generator<int, array<string, mixed>>
	 */
	private function generateCsvRows(OrderQuery $ordersQuery, array $orderFields, bool $showDateShipped): Generator
	{
		$currency = $this->getStoreCurrency();
		$totalMerchandise = new Money(0, $currency);
		$totalTax = new Money(0, $currency);
		$totalDiscount = new Money(0, $currency);
		$totalShipping = new Money(0, $currency);
		$totalPaid = new Money(0, $currency);
		$totalItemsSold = 0;

		foreach (Db::batch($ordersQuery, self::EXPORT_BATCH_SIZE) as $batchQueryResult) {
			/** @var list<Order> $batchQueryResult */
			$rows = $this->buildOrderRows($batchQueryResult, $orderFields);

			foreach ($batchQueryResult as $index => $order) {
				$totalMerchandise = $totalMerchandise->add($this->toMoney($order->itemSubtotal));
				$totalTax = $totalTax->add($this->toMoney($order->totalTax));
				$totalDiscount = $totalDiscount->add($this->toMoney($order->totalDiscount));
				$totalShipping = $totalShipping->add($this->toMoney($order->totalShippingCost));
				$totalPaid = $totalPaid->add($this->toMoney($order->totalPaid));
				/** @var array{itemsSold: int, dateOrdered: string, statusName: string|null, fields: array<string, string>, dateShipped: string} $row */
				$row = $rows[$index];
				$itemsSold = $row['itemsSold'];
				$totalItemsSold += $itemsSold;

				$csvRow = [
					'reference' => $order->reference,
					'dateOrdered' => $row['dateOrdered'],
					'status' => $row['statusName'],
					'email' => $order->email ?? '',
					'merchandiseTotal' => MoneyHelper::toDecimal($this->toMoney($order->itemSubtotal)),
					'tax' => MoneyHelper::toDecimal($this->toMoney($order->totalTax)),
					'discount' => MoneyHelper::toDecimal($this->toMoney($order->totalDiscount)),
					'shipping' => MoneyHelper::toDecimal($this->toMoney($order->totalShippingCost)),
					'totalPaid' => MoneyHelper::toDecimal($this->toMoney($order->totalPaid)),
					'itemsSold' => $itemsSold,
					'paymentStatus' => $order->paidStatus,
				];
				if ($showDateShipped) {
					$csvRow['dateShipped'] = $row['dateShipped'];
				}

				foreach ($row['fields'] as $instanceUid => $fieldValue) {
					$csvRow['field:' . $instanceUid] = $fieldValue;
				}

				yield $csvRow;
			}
		}

		$totalsRow = [
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
		if ($showDateShipped) {
			$totalsRow['dateShipped'] = '';
		}

		foreach (array_keys($orderFields) as $instanceUid) {
			$totalsRow['field:' . $instanceUid] = '';
		}

		yield $totalsRow;
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
		$this->applyOrderFieldFilters($ordersQuery);

		// Eager-load each field the way Craft's element indexes do, so disabled or other-site relations still show
		foreach (Plugin::getInstance()->reportFields->getOrderFields() as $orderField) {
			$orderField->modifyElementIndexQuery($ordersQuery);
		}

		return $ordersQuery;
	}

	private function applyOrderFieldFilters(OrderQuery $ordersQuery): void
	{
		$reportFields = Plugin::getInstance()->reportFields;
		$filterValues = $this->resolveFieldFilterValues('orderField');
		foreach ($reportFields->getOrderFields() as $instanceUid => $orderField) {
			if (($filterValues[$instanceUid] ?? []) === []) {
				continue;
			}

			$fieldValue = $reportFields->getElementQueryParam($orderField, $filterValues[$instanceUid]);
			if ($fieldValue !== null) {
				$fieldHandle = $orderField->handle;
				$ordersQuery->{$fieldHandle}($fieldValue);
			}
		}
	}

	/**
	 * @return list<array{instanceUid: string, label: string, options: array<int|string, string>}>
	 */
	private function getOrderFieldFilters(): array
	{
		$reportFields = Plugin::getInstance()->reportFields;
		$orderFieldFilters = [];
		foreach ($reportFields->getOrderFields() as $instanceUid => $orderField) {
			$orderFieldFilters[] = [
				'instanceUid' => $instanceUid,
				'label' => Craft::t('site', (string) $orderField->name),
				'options' => $reportFields->getOptions($orderField, Order::find()->isCompleted(true)),
			];
		}

		return $orderFieldFilters;
	}

	/**
	 * Get the IDs of the configured shipped status, one per store that has it.
	 *
	 * @return list<int>
	 */
	private function getShippedStatusIds(): array
	{
		if ($this->shippedStatusIds !== null) {
			return $this->shippedStatusIds;
		}

		$shippedOrderStatusHandle = Plugin::getInstance()->getSettings()->shippedOrderStatusHandle;
		$this->shippedStatusIds = [];
		if ($shippedOrderStatusHandle === null) {
			return $this->shippedStatusIds;
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		foreach ($commerce->getStores()->getAllStores() as $allStore) {
			$shippedStatus = $commerce->getOrderStatuses()->getOrderStatusByHandle($shippedOrderStatusHandle, $allStore->id);
			if ($shippedStatus !== null) {
				$this->shippedStatusIds[] = (int) $shippedStatus->id;
			}
		}

		return $this->shippedStatusIds;
	}

	/**
	 * Get when each order last entered the shipped status, formatted like the order date.
	 *
	 * @param list<int> $orderIds
	 * @return array<int, string>
	 */
	private function getShippedDates(array $orderIds): array
	{
		$shippedStatusIds = $this->getShippedStatusIds();
		if ($orderIds === [] || $shippedStatusIds === []) {
			return [];
		}

		/** @var list<array{orderId: int|string, dateShipped: string}> $rows */
		$rows = (new Query())
			->select([
				'orderId' => '[[orderId]]',
				'dateShipped' => 'MAX([[dateCreated]])',
			])
			->from(CommerceTable::ORDERHISTORIES)
			->where([
				'[[orderId]]' => $orderIds,
				'[[newStatusId]]' => $shippedStatusIds,
			])
			->groupBy('[[orderId]]')
			->all();

		$shippedDates = [];
		foreach ($rows as $row) {
			$dateShipped = DateTimeHelper::toDateTime($row['dateShipped']);
			$shippedDates[(int) $row['orderId']] = $dateShipped ? $dateShipped->format('n/j/Y g:ia') : '';
		}

		return $shippedDates;
	}

	/**
	 * @param array<Order> $orders
	 * @param array<string, FieldInterface> $orderFields
	 * @return list<array<string, mixed>>
	 */
	private function buildOrderRows(array $orders, array $orderFields): array
	{
		$reportFields = Plugin::getInstance()->reportFields;
		$orderIds = array_values(array_map(static fn (Order $order): int => (int) $order->id, $orders));
		$shippedDates = $this->getShippedDates($orderIds);

		$orderItemCounts = [];
		if ($orders !== []) {
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
				'id' => $order->id,
				'reference' => $order->reference,
				'cpEditUrl' => $order->cpEditUrl,
				'dateOrdered' => $order->dateOrdered ? $order->dateOrdered->format('n/j/Y g:ia') : '',
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
				'fields' => array_map(static fn (FieldInterface $orderField): string => $reportFields->getDisplayValue($order, $orderField), $orderFields),
				'dateShipped' => $shippedDates[$order->id] ?? '',
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

		// Global shipping locations filter — joins addresses on shippingAddressId
		if ($scope->hasShippingLocationsFilter()) {
			$shippingAddressIdCol = $query instanceof OrderQuery
				? '[[commerce_orders.shippingAddressId]]'
				: '[[shippingAddressId]]';

			$query->innerJoin(
				[
					'shippingAddresses' => CraftTable::ADDRESSES,
				],
				$shippingAddressIdCol . ' = [[shippingAddresses.id]]'
			);

			$locationCondition = $scope->shippingLocationsCondition('shippingAddresses');
			if ($locationCondition !== null) {
				$query->andWhere($locationCondition);
			}
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
	 * @return array<string, int>|list<Expression>
	 */
	private function resolveOrderSort(string $sort, string $sortDir): array
	{
		$computedSort = $this->getComputedSortSql($sort);
		if ($computedSort !== null) {
			$sqlDirection = strtolower($sortDir) === 'asc' ? 'ASC' : 'DESC';

			return [
				new Expression("({$computedSort}) {$sqlDirection}"),
			];
		}

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

	/**
	 * Get the SQL to sort by a column that is not an order attribute, or null for an attribute column.
	 */
	private function getComputedSortSql(string $sort): ?string
	{
		if ($sort === 'itemsSold') {
			return 'SELECT COALESCE(SUM([[sortLineItems.qty]]), 0) FROM ' . CommerceTable::LINEITEMS . ' [[sortLineItems]] WHERE [[sortLineItems.orderId]] = [[commerce_orders.id]]';
		}

		if ($sort === 'dateShipped') {
			$shippedStatusIds = $this->getShippedStatusIds();
			if ($shippedStatusIds === []) {
				return null;
			}

			return 'SELECT MAX([[sortHistories.dateCreated]]) FROM ' . CommerceTable::ORDERHISTORIES . ' [[sortHistories]] WHERE [[sortHistories.orderId]] = [[commerce_orders.id]] AND [[sortHistories.newStatusId]] IN (' . implode(', ', $shippedStatusIds) . ')';
		}

		if (! str_starts_with($sort, 'field:')) {
			return null;
		}

		$orderField = Plugin::getInstance()->reportFields->getOrderFields()[substr($sort, 6)] ?? null;
		if ($orderField === null) {
			return null;
		}

		// Relation values are stored in the relations table, so sort by the first related title instead
		if ($orderField instanceof BaseRelationField) {
			return 'SELECT MIN([[sortTargets.title]]) FROM ' . CraftTable::RELATIONS . ' [[sortRelations]]'
				. ' INNER JOIN ' . CraftTable::ELEMENTS_SITES . ' [[sortTargets]] ON [[sortTargets.elementId]] = [[sortRelations.targetId]]'
				. ' WHERE [[sortRelations.fieldId]] = ' . (int) $orderField->id
				. ' AND [[sortRelations.sourceId]] = [[commerce_orders.id]]';
		}

		$orderBy = $orderField->getSortOption()['orderBy'];

		return is_string($orderBy) ? $orderBy : null;
	}
}
