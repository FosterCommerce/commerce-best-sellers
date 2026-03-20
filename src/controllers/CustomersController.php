<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use fostercommerce\bestsellers\assetbundles\ReportsAsset;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class CustomersController extends BaseReportController
{
	private const PER_PAGE = 100;

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(ReportsAsset::class);

		$dateRange = $this->resolveDateRange();
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);
		$customerStats = $plugin->customerStats;

		// Written summary
		$kpis = $customerStats->getCustomerKpis($dateRange['fromDT'], $dateRange['toDT']);
		$prevKpis = $customerStats->getCustomerKpis($dateRange['prev']['fromDT'], $dateRange['prev']['toDT']);
		$newCustomersChange = $this->percentChange($kpis['new'], $prevKpis['new']);

		return $this->renderTemplate('best-sellers/_customers', [
			'title' => 'Customers',
			'selectedSubnavItem' => 'customers',
			'from' => $dateRange['from'],
			'to' => $dateRange['to'],
			'preset' => $dateRange['preset'],
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

		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$rawPage = $request->getQueryParam('page', 1);
		$page = max(1, is_numeric($rawPage) ? (int) $rawPage : 1);

		$rawSearch = $request->getQueryParam('search', '');
		$search = trim(is_string($rawSearch) ? $rawSearch : '');

		$rawSort = $request->getQueryParam('sort', 'totalSpent');
		$sort = trim(is_string($rawSort) ? $rawSort : 'totalSpent');

		$rawSortDir = $request->getQueryParam('sortDir', 'desc');
		$sortDir = trim(is_string($rawSortDir) ? $rawSortDir : 'desc');

		$customerTypes = $request->getQueryParam('customerType', []);
		if (is_string($customerTypes) && $customerTypes !== '') {
			$customerTypes = [$customerTypes];
		} elseif (! is_array($customerTypes)) {
			$customerTypes = [];
		}

		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);
		$customerStats = $plugin->customerStats;

		/** @var array<int, array{email: string, customerId: int|null, isGuest: bool, orderCount: int, totalSpent: float, aov: float, lastOrder: string|null}> $allCustomers */
		$allCustomers = $customerStats->getTopCustomers($dateRange['fromDT'], $dateRange['toDT'], 10000);

		// Server-side customer type filter (multi-select)
		if ($customerTypes !== [] && count($customerTypes) < 2) {
			$wantGuest = in_array('guest', $customerTypes, true);
			$wantRegistered = in_array('registered', $customerTypes, true);
			if ($wantGuest && ! $wantRegistered) {
				$allCustomers = array_values(array_filter($allCustomers, fn (array $customer): bool => $customer['isGuest']));
			} elseif ($wantRegistered && ! $wantGuest) {
				$allCustomers = array_values(array_filter($allCustomers, fn (array $customer): bool => ! $customer['isGuest']));
			}
		}

		// Server-side search
		if ($search !== '') {
			$searchLower = strtolower($search);
			$allCustomers = array_values(array_filter($allCustomers, fn (array $customer): bool => str_contains(strtolower($customer['email']), $searchLower)));
		}

		// Server-side sort
		$allowedSortColumns = ['email', 'orderCount', 'totalSpent', 'aov', 'lastOrder'];
		if (in_array($sort, $allowedSortColumns, true)) {
			usort($allCustomers, function (array $customerA, array $customerB) use ($sort, $sortDir): int {
				$valueA = $customerA[$sort] ?? 0;
				$valueB = $customerB[$sort] ?? 0;
				if (is_numeric($valueA) && is_numeric($valueB)) {
					$comparison = (float) $valueA <=> (float) $valueB;
				} else {
					$comparison = strcasecmp(is_string($valueA) ? $valueA : '', is_string($valueB) ? $valueB : '');
				}

				return $sortDir === 'asc' ? $comparison : -$comparison;
			});
		}

		$totalItems = count($allCustomers);
		$totalPages = max(1, (int) ceil($totalItems / self::PER_PAGE));
		$offset = ($page - 1) * self::PER_PAGE;
		$pageItems = array_slice($allCustomers, $offset, self::PER_PAGE);

		$rows = [];
		foreach ($pageItems as $customer) {
			$rows[] = [
				'email' => $customer['email'],
				'customerId' => $customer['customerId'],
				'isGuest' => $customer['isGuest'],
				'orderCount' => $customer['orderCount'],
				'totalSpent' => $this->formatCurrency($customer['totalSpent']),
				'aov' => $this->formatCurrency($customer['aov']),
				'lastOrder' => $customer['lastOrder'] !== null ? substr($customer['lastOrder'], 0, 10) : '',
				'cpUrl' => $customer['customerId'] !== null ? Craft::$app->getUrlManager()->createUrl('users/' . $customer['customerId']) : null,
			];
		}

		// Page totals
		$totalOrderCount = 0;
		$totalSpentSum = 0.0;
		foreach ($pageItems as $pageItem) {
			$totalOrderCount += $pageItem['orderCount'];
			$totalSpentSum += $pageItem['totalSpent'];
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
		/** @var \craft\web\Request $request */
		$request = Craft::$app->getRequest();
		$dateRange = $this->resolveDateRange();

		$rawSearch = $request->getQueryParam('search', '');
		$search = trim(is_string($rawSearch) ? $rawSearch : '');

		$customerTypes = $request->getQueryParam('customerType', []);
		if (is_string($customerTypes) && $customerTypes !== '') {
			$customerTypes = [$customerTypes];
		} elseif (! is_array($customerTypes)) {
			$customerTypes = [];
		}

		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);
		$customerStats = $plugin->customerStats;

		/** @var array<int, array{email: string, customerId: int|null, isGuest: bool, orderCount: int, totalSpent: float, aov: float, lastOrder: string|null}> $allCustomers */
		$allCustomers = $customerStats->getTopCustomers($dateRange['fromDT'], $dateRange['toDT'], 10000);

		if ($customerTypes !== [] && count($customerTypes) < 2) {
			$wantGuest = in_array('guest', $customerTypes, true);
			$wantRegistered = in_array('registered', $customerTypes, true);
			if ($wantGuest && ! $wantRegistered) {
				$allCustomers = array_values(array_filter($allCustomers, fn (array $customer): bool => $customer['isGuest']));
			} elseif ($wantRegistered && ! $wantGuest) {
				$allCustomers = array_values(array_filter($allCustomers, fn (array $customer): bool => ! $customer['isGuest']));
			}
		}

		if ($search !== '') {
			$searchLower = strtolower($search);
			$allCustomers = array_values(array_filter($allCustomers, fn (array $customer): bool => str_contains(strtolower($customer['email']), $searchLower)));
		}

		$csvRows = [];
		$totalOrderCount = 0;
		$totalSpentSum = 0.0;

		foreach ($allCustomers as $allCustomer) {
			$totalOrderCount += $allCustomer['orderCount'];
			$totalSpentSum += $allCustomer['totalSpent'];

			$csvRows[] = [
				'email' => $allCustomer['email'],
				'type' => $allCustomer['isGuest'] ? 'Guest' : 'Registered',
				'orders' => $allCustomer['orderCount'],
				'totalSpent' => round($allCustomer['totalSpent'], 2),
				'aov' => round($allCustomer['aov'], 2),
				'lastOrder' => $allCustomer['lastOrder'] !== null ? substr($allCustomer['lastOrder'], 0, 10) : '',
			];
		}

		$csvRows[] = [
			'email' => 'TOTAL',
			'type' => '',
			'orders' => $totalOrderCount,
			'totalSpent' => round($totalSpentSum, 2),
			'aov' => '',
			'lastOrder' => '',
		];

		return $this->asCsv($csvRows, [
			'Email', 'Type', '# Orders', 'Total Spent', 'AOV', 'Last Purchase',
		], 'customers');
	}
}
