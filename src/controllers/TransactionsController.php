<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\MoneyHelper;
use craft\helpers\UrlHelper;
use craft\web\Request;
use DateTime;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\models\DateRangeResult;
use Money\Money;
use yii\db\Expression;
use yii\web\Response;

/**
 * @phpstan-type RawTransactionRow array{
 *     id: int|string|null,
 *     dateCreated: string|null,
 *     orderId: int|string|null,
 *     orderReference: string|null,
 *     orderEmail: string|null,
 *     orderDateOrdered: string|null,
 *     type: string,
 *     status: string,
 *     gatewayId: int|string|null,
 *     gatewayName: string|null,
 *     amount: string,
 *     currency: string|null,
 *     reference: string|null,
 *     parentId: int|string|null
 * }
 */
class TransactionsController extends BaseReportController
{
	private const PER_PAGE = 100;

	/**
	 * Money-moving rows only. Authorize, failed, pending, redirect and processing
	 * rows are hidden on first visit so the totals bar matches the "in vs. out"
	 * framing. The browser tab's sessionStorage (via reports.js) keeps user
	 * overrides alive across reloads; a fresh tab falls back to these defaults.
	 *
	 * @var list<string>
	 */
	private const DEFAULT_TYPES = [
		TransactionRecord::TYPE_CAPTURE,
		TransactionRecord::TYPE_PURCHASE,
		TransactionRecord::TYPE_REFUND,
	];

	/**
	 * @var list<string>
	 */
	private const DEFAULT_STATUSES = [
		TransactionRecord::STATUS_SUCCESS,
	];

	/**
	 * @var list<string>
	 */
	private const ALLOWED_TYPES = [
		TransactionRecord::TYPE_AUTHORIZE,
		TransactionRecord::TYPE_CAPTURE,
		TransactionRecord::TYPE_PURCHASE,
		TransactionRecord::TYPE_REFUND,
	];

	/**
	 * @var list<string>
	 */
	private const ALLOWED_STATUSES = [
		TransactionRecord::STATUS_PENDING,
		TransactionRecord::STATUS_REDIRECT,
		TransactionRecord::STATUS_PROCESSING,
		TransactionRecord::STATUS_SUCCESS,
		TransactionRecord::STATUS_FAILED,
	];

	/**
	 * @var list<string>|null
	 */
	private ?array $resolvedTypes = null;

	/**
	 * @var list<string>|null
	 */
	private ?array $resolvedStatuses = null;

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$scope = $this->resolveScope();

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		/** @var mixed $rawOrderFrom */
		$rawOrderFrom = $request->getQueryParam('orderFrom', '');
		/** @var mixed $rawOrderTo */
		$rawOrderTo = $request->getQueryParam('orderTo', '');
		$orderFrom = is_string($rawOrderFrom) ? $rawOrderFrom : '';
		$orderTo = is_string($rawOrderTo) ? $rawOrderTo : '';

		return $this->renderTemplate('best-sellers/_transactions', [
			'title' => Craft::t('best-sellers', 'Transactions'),
			'selectedSubnavItem' => 'transactions',
			'from' => $scope->from,
			'to' => $scope->to,
			'preset' => $scope->preset,
			'scope' => $scope,
			'gateways' => $this->getGatewayChoices(),
			'selectedTypes' => $this->resolveTypeSelection(),
			'selectedStatuses' => $this->resolveStatusSelection(),
			'orderFrom' => $orderFrom,
			'orderTo' => $orderTo,
		]);
	}

	/**
	 * AJAX endpoint for paginated transactions data.
	 */
	public function actionTransactionsData(): Response
	{
		$this->requireAcceptsJson();

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$scope = $this->resolveScope();

		$rawPage = $request->getQueryParam('page', 1);
		$page = max(1, (int) (is_scalar($rawPage) ? $rawPage : 1));

		$offset = ($page - 1) * self::PER_PAGE;

		$rowsQuery = $this->buildFilteredQuery($scope->dateRange);

		$totalItems = (int) (clone $rowsQuery)->count();
		$totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));

		$totals = $this->buildFilteredTotals(clone $rowsQuery);

		$rawSort = $request->getQueryParam('sort', 'dateCreated');
		$rawSortDir = $request->getQueryParam('sortDir', 'desc');
		[$sortColumn, $sortDirection] = $this->resolveSort(
			is_string($rawSort) ? $rawSort : 'dateCreated',
			is_string($rawSortDir) ? $rawSortDir : 'desc',
		);

		/** @var list<RawTransactionRow> $rawRows */
		$rawRows = $rowsQuery
			->orderBy([
				$sortColumn => $sortDirection,
			])
			->offset($offset)
			->limit(self::PER_PAGE)
			->all();

		return $this->asJson([
			'items' => $this->buildRows($rawRows),
			'currentPage' => $page,
			'totalPages' => $totalPages,
			'totalItems' => $totalItems,
			'perPage' => self::PER_PAGE,
			'totals' => $totals,
		]);
	}

	/**
	 * CSV export of filtered transactions.
	 */
	public function actionExportCsv(): Response
	{
		$scope = $this->resolveScope();
		$rowsQuery = $this->buildFilteredQuery($scope->dateRange);

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$rawSort = $request->getQueryParam('sort', 'dateCreated');
		$rawSortDir = $request->getQueryParam('sortDir', 'desc');
		[$sortColumn, $sortDirection] = $this->resolveSort(
			is_string($rawSort) ? $rawSort : 'dateCreated',
			is_string($rawSortDir) ? $rawSortDir : 'desc',
		);

		/** @var list<RawTransactionRow> $rawRows */
		$rawRows = $rowsQuery
			->orderBy([
				$sortColumn => $sortDirection,
			])
			->all();

		$rows = $this->buildRows($rawRows);

		$currency = $this->getStoreCurrency();
		$captured = new Money(0, $currency);
		$refunded = new Money(0, $currency);

		$csvRows = [];
		foreach ($rawRows as $index => $rawRow) {
			$money = $this->parseMoney($rawRow['amount']);
			$type = $rawRow['type'];
			$signed = $type === TransactionRecord::TYPE_REFUND ? $money->negative() : $money;

			if ($type === TransactionRecord::TYPE_REFUND) {
				$refunded = $refunded->add($money);
			} else {
				$captured = $captured->add($money);
			}

			$csvRows[] = [
				'dateCreated' => $rows[$index]['dateCreated'] ?? '',
				'type' => $type,
				'status' => $rawRow['status'],
				'gateway' => $rows[$index]['gateway'] ?? '',
				'amount' => MoneyHelper::toDecimal($signed),
				'orderReference' => $rows[$index]['orderReference'] ?? '',
				'orderDateOrdered' => $rows[$index]['orderDateOrdered'] ?? '',
				'email' => $rows[$index]['email'] ?? '',
				'reference' => $rows[$index]['reference'] ?? '',
			];
		}

		$net = $captured->subtract($refunded);

		foreach ([
			[
				'label' => Craft::t('best-sellers', 'Captured'),
				'money' => $captured,
			],
			[
				'label' => Craft::t('best-sellers', 'Refunded'),
				'money' => $refunded,
			],
			[
				'label' => Craft::t('best-sellers', 'Net'),
				'money' => $net,
			],
		] as $summaryRow) {
			$csvRows[] = [
				'dateCreated' => $summaryRow['label'],
				'type' => '',
				'status' => '',
				'gateway' => '',
				'amount' => MoneyHelper::toDecimal($summaryRow['money']),
				'orderReference' => '',
				'orderDateOrdered' => '',
				'email' => '',
				'reference' => '',
			];
		}

		return $this->asCsv($csvRows, [
			Craft::t('best-sellers', 'Date'),
			Craft::t('best-sellers', 'Type'),
			Craft::t('best-sellers', 'Status'),
			Craft::t('best-sellers', 'Gateway'),
			Craft::t('best-sellers', 'Amount'),
			Craft::t('best-sellers', 'Order #'),
			Craft::t('best-sellers', 'Order Date'),
			Craft::t('best-sellers', 'Email'),
			Craft::t('best-sellers', 'Reference'),
		], 'transactions');
	}

	/**
	 * Resolve which transaction-type checkboxes should be applied.
	 *
	 * Query param wins; absent param falls through to DEFAULT_TYPES so a fresh
	 * tab opens on the money-moving subset. Sending `type=` (empty scalar) or
	 * `type[]=` with no values is treated as an explicit clear (returns []).
	 *
	 * @return list<string>
	 */
	private function resolveTypeSelection(): array
	{
		if ($this->resolvedTypes !== null) {
			return $this->resolvedTypes;
		}

		$this->resolvedTypes = $this->resolveCheckboxSelection(
			'type',
			self::DEFAULT_TYPES,
			self::ALLOWED_TYPES
		);

		return $this->resolvedTypes;
	}

	/**
	 * @return list<string>
	 */
	private function resolveStatusSelection(): array
	{
		if ($this->resolvedStatuses !== null) {
			return $this->resolvedStatuses;
		}

		$this->resolvedStatuses = $this->resolveCheckboxSelection(
			'status',
			self::DEFAULT_STATUSES,
			self::ALLOWED_STATUSES
		);

		return $this->resolvedStatuses;
	}

	/**
	 * @param list<string> $defaults
	 * @param list<string> $allowed
	 * @return list<string>
	 */
	private function resolveCheckboxSelection(string $queryKey, array $defaults, array $allowed): array
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		$param = $request->getQueryParam($queryKey, null);

		if ($param === null) {
			return $defaults;
		}

		if (is_string($param)) {
			$selection = $param !== '' ? [$param] : [];
		} elseif (is_array($param)) {
			$selection = array_values(array_filter($param, 'is_string'));
		} else {
			$selection = [];
		}

		return array_values(array_filter(
			$selection,
			static fn (string $value): bool => in_array($value, $allowed, true)
		));
	}

	/**
	 * Build the filtered transactions query, joined to commerce_orders and
	 * commerce_gateways for the order ref / gateway name / email / soft-delete
	 * exclusion. Raw Query because commerce_transactions rows are not elements.
	 *
	 * @return Query<array-key, mixed>
	 */
	private function buildFilteredQuery(DateRangeResult $dateRange): Query
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		$query = (new Query())
			->select([
				'id' => '[[t.id]]',
				'dateCreated' => '[[t.dateCreated]]',
				'orderId' => '[[t.orderId]]',
				'orderReference' => '[[o.reference]]',
				'orderEmail' => '[[o.email]]',
				'orderDateOrdered' => '[[o.dateOrdered]]',
				'type' => '[[t.type]]',
				'status' => '[[t.status]]',
				'gatewayId' => '[[t.gatewayId]]',
				'gatewayName' => '[[g.name]]',
				'amount' => '[[t.amount]]',
				'currency' => '[[t.currency]]',
				'reference' => '[[t.reference]]',
				'parentId' => '[[t.parentId]]',
			])
			->from([
				't' => CommerceTable::TRANSACTIONS,
			])
			->leftJoin([
				'o' => CommerceTable::ORDERS,
			], '[[o.id]] = [[t.orderId]]')
			->leftJoin([
				'e' => CraftTable::ELEMENTS,
			], '[[e.id]] = [[o.id]]')
			->leftJoin([
				'g' => CommerceTable::GATEWAYS,
			], '[[g.id]] = [[t.gatewayId]]')
			->where([
				'[[e.dateDeleted]]' => null,
			])
			->andWhere([
				'[[t.currency]]' => $this->getStoreCurrencyCode(),
			])
			->andWhere($dateRange->dateCondition('[[t.dateCreated]]'));

		$scope = $this->resolveScope();
		if ($scope->orderStatusIds !== []) {
			$query->andWhere([
				'[[o.orderStatusId]]' => $scope->orderStatusIds,
			]);
		}

		$selectedTypes = $this->resolveTypeSelection();
		if ($selectedTypes !== []) {
			$query->andWhere([
				'[[t.type]]' => $selectedTypes,
			]);
		}

		$selectedStatuses = $this->resolveStatusSelection();
		if ($selectedStatuses !== []) {
			$query->andWhere([
				'[[t.status]]' => $selectedStatuses,
			]);
		}

		/** @var mixed $rawGatewayParam */
		$rawGatewayParam = $request->getQueryParam('gatewayId', []);
		$gatewayIds = [];
		if (is_string($rawGatewayParam) && $rawGatewayParam !== '') {
			$gatewayIds = [(int) $rawGatewayParam];
		} elseif (is_array($rawGatewayParam)) {
			foreach ($rawGatewayParam as $value) {
				if (is_string($value) || is_int($value)) {
					$gatewayIds[] = (int) $value;
				}
			}
		}

		$gatewayIds = array_values(array_filter($gatewayIds, static fn (int $value): bool => $value > 0));
		if ($gatewayIds !== []) {
			$query->andWhere([
				'[[t.gatewayId]]' => $gatewayIds,
			]);
		}

		$rawSearch = $request->getQueryParam('search', '');
		$search = is_string($rawSearch) ? trim($rawSearch) : '';
		if ($search !== '') {
			$query->andWhere([
				'or',
				['like', '[[t.reference]]', $search],
				['like', '[[t.code]]', $search],
				['like', '[[o.reference]]', $search],
				['like', '[[o.email]]', $search],
			]);
		}

		$this->applyOrderDateFilter($query);

		return $query;
	}

	/**
	 * Apply the secondary "order date" range filter. Independent of the global
	 * scope date picker (which bounds `t.dateCreated`). Lets users intersect
	 * "transactions that moved in window A" with "for orders placed in window B".
	 *
	 * @param Query<array-key, mixed> $query
	 */
	private function applyOrderDateFilter(Query $query): void
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();

		/** @var mixed $rawFrom */
		$rawFrom = $request->getQueryParam('orderFrom');
		/** @var mixed $rawTo */
		$rawTo = $request->getQueryParam('orderTo');

		$fromDt = is_string($rawFrom) && $rawFrom !== ''
			? DateTimeHelper::toDateTime($rawFrom)
			: false;
		$toDt = is_string($rawTo) && $rawTo !== ''
			? DateTimeHelper::toDateTime($rawTo)
			: false;

		if (! $fromDt instanceof DateTime && ! $toDt instanceof DateTime) {
			return;
		}

		$conditions = ['and'];
		if ($fromDt instanceof DateTime) {
			$conditions[] = '>= ' . $fromDt->format('Y-m-d') . ' 00:00:00';
		}

		if ($toDt instanceof DateTime) {
			$conditions[] = '<= ' . $toDt->format('Y-m-d') . ' 23:59:59';
		}

		$parsed = Db::parseDateParam('[[o.dateOrdered]]', $conditions);
		if ($parsed !== null) {
			$query->andWhere($parsed);
		}
	}

	/**
	 * Translate row JSON into the wire format consumed by the AJAX table.
	 *
	 * Refund rows render with a negative signed Money amount so the table reads
	 * like a bank statement. The raw amount stays positive in the DB; signing
	 * happens here at the display boundary.
	 *
	 * @param list<RawTransactionRow> $rawRows
	 * @return list<array<string, mixed>>
	 */
	private function buildRows(array $rawRows): array
	{
		$rows = [];
		foreach ($rawRows as $rawRow) {
			$type = $rawRow['type'];
			$money = $this->parseMoney($rawRow['amount']);
			$signed = $type === TransactionRecord::TYPE_REFUND ? $money->negative() : $money;

			$formatter = Craft::$app->getFormatter();

			$dateCreated = $rawRow['dateCreated'] ?? null;
			$formattedDate = '';
			if (is_string($dateCreated) && $dateCreated !== '') {
				$dateCreatedDt = DateTimeHelper::toDateTime($dateCreated);
				if ($dateCreatedDt instanceof DateTime) {
					$formattedDate = $formatter->asDatetime($dateCreatedDt, 'short');
				}
			}

			$orderId = (int) ($rawRow['orderId'] ?? 0);

			$orderDateOrdered = $rawRow['orderDateOrdered'] ?? null;
			$formattedOrderDate = '';
			if (is_string($orderDateOrdered) && $orderDateOrdered !== '') {
				$orderDateOrderedDt = DateTimeHelper::toDateTime($orderDateOrdered);
				if ($orderDateOrderedDt instanceof DateTime) {
					$formattedOrderDate = $formatter->asDatetime($orderDateOrderedDt, 'short');
				}
			}

			$rows[] = [
				'id' => (int) ($rawRow['id'] ?? 0),
				'dateCreated' => $formattedDate,
				'orderId' => $orderId,
				'orderReference' => $rawRow['orderReference'] ?? '',
				'orderDateOrdered' => $formattedOrderDate,
				'cpEditUrl' => $orderId > 0 ? UrlHelper::cpUrl('commerce/orders/' . $orderId) : '',
				'type' => $type,
				'status' => $rawRow['status'],
				'gateway' => $rawRow['gatewayName'] ?? '',
				'reference' => $rawRow['reference'] ?? '',
				'email' => $rawRow['orderEmail'] ?? '',
				'amount' => $this->formatMoney($signed),
				'isRefund' => $type === TransactionRecord::TYPE_REFUND,
				'parentId' => (int) ($rawRow['parentId'] ?? 0),
			];
		}

		return $rows;
	}

	/**
	 * Aggregate Captured / Refunded / Net across all filtered rows (not just
	 * the current page). Pushes the sum into SQL with CASE expressions so we
	 * neither stream nor materialize the row set in PHP. Money is built once
	 * from the DB-returned decimal strings, avoiding float accumulation.
	 *
	 * Takes the rows query (cloned) rather than rebuilding it so the joins,
	 * filters, and search clauses are constructed exactly once per request.
	 *
	 * @param Query<array-key, mixed> $baseQuery
	 * @return array<string, string>
	 */
	private function buildFilteredTotals(Query $baseQuery): array
	{
		$sumsQuery = $baseQuery
			->select([
				'captured' => new Expression("COALESCE(SUM(CASE WHEN [[t.type]] = 'refund' THEN 0 ELSE [[t.amount]] END), 0)"),
				'refunded' => new Expression("COALESCE(SUM(CASE WHEN [[t.type]] = 'refund' THEN [[t.amount]] ELSE 0 END), 0)"),
			]);

		/** @var array{captured: string, refunded: string}|false $sums */
		$sums = $sumsQuery->one();

		$captured = $this->parseMoney($sums === false ? '0' : $sums['captured']);
		$refunded = $this->parseMoney($sums === false ? '0' : $sums['refunded']);
		$net = $captured->subtract($refunded);

		return [
			'captured' => $this->formatMoney($captured),
			'refunded' => $this->formatMoney($refunded),
			'net' => $this->formatMoney($net),
		];
	}

	/**
	 * @return array{0: string, 1: int}
	 */
	private function resolveSort(string $sort, string $sortDir): array
	{
		$allowed = [
			'dateCreated' => '[[t.dateCreated]]',
			'amount' => '[[t.amount]]',
			'type' => '[[t.type]]',
			'status' => '[[t.status]]',
			'gateway' => '[[g.name]]',
			'orderReference' => '[[o.reference]]',
			'orderDateOrdered' => '[[o.dateOrdered]]',
		];

		$column = $allowed[$sort] ?? $allowed['dateCreated'];
		$direction = strtolower($sortDir) === 'asc' ? SORT_ASC : SORT_DESC;

		return [$column, $direction];
	}

	/**
	 * @return list<array{id: int, name: string}>
	 */
	private function getGatewayChoices(): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		$choices = [];
		foreach ($commerce->getGateways()->getAllGateways() as $allGateway) {
			/** @var \craft\commerce\base\Gateway $allGateway */
			$choices[] = [
				'id' => (int) $allGateway->id,
				'name' => (string) $allGateway->name,
			];
		}

		usort(
			$choices,
			static fn (array $left, array $right): int => strcasecmp($left['name'], $right['name'])
		);

		return $choices;
	}
}
