<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\web\Request;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\models\CustomerRow;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class CustomersController extends BaseReportController
{
	private const PER_PAGE = 100;

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$scope = $this->resolveScope();
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);
		$customerStats = $plugin->customerStats;

		$kpis = $customerStats->getCustomerKpis($scope);
		$prevScope = $scope->forDates($scope->getPrev()->from, $scope->getPrev()->to);
		$prevKpis = $customerStats->getCustomerKpis($prevScope);
		$newCustomersChange = $this->percentChange($kpis->new, $prevKpis->new);

		return $this->renderTemplate('best-sellers/_customers', [
			'title' => Craft::t('best-sellers', 'Customers'),
			'selectedSubnavItem' => 'customers',
			'from' => $scope->from,
			'to' => $scope->to,
			'preset' => $scope->preset,
			'scope' => $scope,
			'kpis' => $kpis,
			'newCustomersChange' => $newCustomersChange,
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
		assert($plugin instanceof Plugin);
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
				'cpUrl' => $pageItem->customerId !== null ? Craft::$app->getUrlManager()->createUrl('users/' . $pageItem->customerId) : null,
			];
		}

		$totalOrderCount = 0;
		$totalSpentSum = 0.0;
		foreach ($allCustomers as $allCustomer) {
			$totalOrderCount += $allCustomer->orderCount;
			$totalSpentSum += $allCustomer->totalSpent;
		}

		$totals = [
			'orderCount' => number_format($totalOrderCount),
			'totalSpent' => $this->formatCurrency($totalSpentSum),
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
		assert($plugin instanceof Plugin);
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
		$totalSpentSum = 0.0;

		foreach ($allCustomers as $allCustomer) {
			$totalOrderCount += $allCustomer->orderCount;
			$totalSpentSum += $allCustomer->totalSpent;

			$csvRows[] = [
				'email' => $allCustomer->email,
				'status' => ucfirst($allCustomer->status),
				'orders' => $allCustomer->orderCount,
				'totalSpent' => round($allCustomer->totalSpent, 2),
				'aov' => round($allCustomer->aov, 2),
				'lastOrder' => $allCustomer->lastOrder !== null ? substr($allCustomer->lastOrder, 0, 10) : '',
			];
		}

		$csvRows[] = [
			'email' => 'TOTAL',
			'status' => '',
			'orders' => $totalOrderCount,
			'totalSpent' => round($totalSpentSum, 2),
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
