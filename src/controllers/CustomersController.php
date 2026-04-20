<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\helpers\MoneyHelper;
use craft\helpers\UrlHelper;
use craft\web\Request;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\models\CustomerRow;
use fostercommerce\bestsellers\Plugin;
use Money\Money;
use yii\web\Response;

class CustomersController extends BaseReportController
{
	private const PER_PAGE = 100;

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$scope = $this->resolveScope();

		return $this->renderTemplate('best-sellers/_customers', [
			'title' => Craft::t('best-sellers', 'Customers'),
			'selectedSubnavItem' => 'customers',
			'from' => $scope->from,
			'to' => $scope->to,
			'preset' => $scope->preset,
			'scope' => $scope,
		]);
	}

	/**
	 * AJAX endpoint for paginated customers data.
	 */
	public function actionCustomersData(): Response
	{
		$this->requireAcceptsJson();

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveScope();

		/** @var int|string $page */
		$page = $request->getQueryParam('page', 1);
		$page = max(1, (int) $page);

		/** @var string $rawSearch */
		$rawSearch = $request->getQueryParam('search', '');
		$search = trim($rawSearch);
		/** @var string $rawSort */
		$rawSort = $request->getQueryParam('sort', 'totalSpent');
		$sort = trim($rawSort);
		/** @var string $rawSortDir */
		$rawSortDir = $request->getQueryParam('sortDir', 'desc');
		$sortDir = trim($rawSortDir);

		$customerTypes = $request->getQueryParam('customerType', []);
		if (is_string($customerTypes) && $customerTypes !== '') {
			$customerTypes = [$customerTypes];
		} elseif (! is_array($customerTypes)) {
			$customerTypes = [];
		}

		$plugin = Plugin::getInstance();
		$customerStats = $plugin->customerStats;

		/** @var list<CustomerRow> $allCustomers */
		$allCustomers = $customerStats->getTopCustomers($dateRange, 10000);

		if ($customerTypes !== []) {
			$allCustomers = array_values(array_filter($allCustomers, fn (CustomerRow $customer): bool => in_array($customer->status, $customerTypes, true)));
		}

		if ($search !== '') {
			$searchLower = strtolower($search);
			$allCustomers = array_values(array_filter($allCustomers, fn (CustomerRow $customer): bool => str_contains(strtolower($customer->email), $searchLower)));
		}

		$allowedSortColumns = ['email', 'status', 'orderCount', 'totalSpent', 'aov', 'lastOrder'];
		if (in_array($sort, $allowedSortColumns, true)) {
			usort($allCustomers, function (CustomerRow $customerA, CustomerRow $customerB) use ($sort, $sortDir): int {
				$arrA = $customerA->toArray();
				$arrB = $customerB->toArray();
				/** @var string|int|float|bool|null $valueA */
				$valueA = $arrA[$sort] ?? 0;
				/** @var string|int|float|bool|null $valueB */
				$valueB = $arrB[$sort] ?? 0;
				if (is_numeric($valueA) && is_numeric($valueB)) {
					$comparison = (float) $valueA <=> (float) $valueB;
				} else {
					$comparison = strcasecmp((string) $valueA, (string) $valueB);
				}

				return $sortDir === 'asc' ? $comparison : -$comparison;
			});
		}

		$totalItems = count($allCustomers);
		$totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
		$offset = ($page - 1) * self::PER_PAGE;
		$pageItems = array_slice($allCustomers, $offset, self::PER_PAGE);

		$rows = [];
		foreach ($pageItems as $pageItem) {
			$rows[] = [
				'email' => $pageItem->email,
				'customerId' => $pageItem->customerId,
				'isGuest' => $pageItem->isGuest,
				'status' => $pageItem->status,
				'orderCount' => $pageItem->orderCount,
				'totalSpent' => $this->formatCurrency($pageItem->totalSpent),
				'aov' => $this->formatCurrency($pageItem->aov),
				'lastOrder' => $pageItem->lastOrder !== null ? substr($pageItem->lastOrder, 0, 10) : '',
				'cpUrl' => $pageItem->customerId !== null ? UrlHelper::cpUrl('users/' . $pageItem->customerId) : null,
			];
		}

		$totalOrderCount = 0;
		$totalSpent = new Money(0, $this->getStoreCurrency());
		foreach ($allCustomers as $allCustomer) {
			$totalOrderCount += $allCustomer->orderCount;
			$totalSpent = $totalSpent->add($this->toMoney($allCustomer->totalSpent));
		}

		$totals = [
			'orderCount' => number_format($totalOrderCount),
			'totalSpent' => $this->formatMoney($totalSpent),
		];

		return $this->asJson([
			'items' => $rows,
			'currentPage' => $page,
			'totalPages' => $totalPages,
			'totalItems' => $totalItems,
			'perPage' => self::PER_PAGE,
			'totals' => $totals,
		]);
	}

	/**
	 * CSV export of filtered customers.
	 */
	public function actionExportCsv(): Response
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveScope();

		/** @var string $rawSearch */
		$rawSearch = $request->getQueryParam('search', '');
		$search = trim($rawSearch);

		$customerTypes = $request->getQueryParam('customerType', []);
		if (is_string($customerTypes) && $customerTypes !== '') {
			$customerTypes = [$customerTypes];
		} elseif (! is_array($customerTypes)) {
			$customerTypes = [];
		}

		$plugin = Plugin::getInstance();
		$customerStats = $plugin->customerStats;

		/** @var list<CustomerRow> $allCustomers */
		$allCustomers = $customerStats->getTopCustomers($dateRange, 10000);

		if ($customerTypes !== []) {
			$allCustomers = array_values(array_filter($allCustomers, fn (CustomerRow $customer): bool => in_array($customer->status, $customerTypes, true)));
		}

		if ($search !== '') {
			$searchLower = strtolower($search);
			$allCustomers = array_values(array_filter($allCustomers, fn (CustomerRow $customer): bool => str_contains(strtolower($customer->email), $searchLower)));
		}

		$csvRows = [];
		$totalOrderCount = 0;
		$totalSpent = new Money(0, $this->getStoreCurrency());

		foreach ($allCustomers as $allCustomer) {
			$totalOrderCount += $allCustomer->orderCount;
			$totalSpent = $totalSpent->add($this->toMoney($allCustomer->totalSpent));

			$csvRows[] = [
				'email' => $allCustomer->email,
				'status' => ucfirst($allCustomer->status),
				'orders' => $allCustomer->orderCount,
				'totalSpent' => MoneyHelper::toDecimal($this->toMoney($allCustomer->totalSpent)),
				'aov' => MoneyHelper::toDecimal($this->toMoney($allCustomer->aov)),
				'lastOrder' => $allCustomer->lastOrder !== null ? substr($allCustomer->lastOrder, 0, 10) : '',
			];
		}

		$csvRows[] = [
			'email' => 'TOTAL',
			'status' => '',
			'orders' => $totalOrderCount,
			'totalSpent' => MoneyHelper::toDecimal($totalSpent),
			'aov' => '',
			'lastOrder' => '',
		];

		return $this->asCsv($csvRows, [
			Craft::t('best-sellers', 'Email'),
			Craft::t('best-sellers', 'Status'),
			Craft::t('best-sellers', '# Orders'),
			Craft::t('best-sellers', 'Total Spent'),
			Craft::t('best-sellers', 'AOV'),
			Craft::t('best-sellers', 'Last Purchase'),
		], 'customers');
	}
}
