<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Query;
use craft\db\Table as CraftTable;
use fostercommerce\bestsellers\helpers\NotTrashed;
use fostercommerce\bestsellers\models\ReportScope;
use fostercommerce\bestsellers\traits\OrderQueryConditions;
use yii\base\Component;

class LocationStats extends Component
{
	use OrderQueryConditions;

	/**
	 * Top countries by revenue, joined via shipping address.
	 *
	 * @return list<array{countryCode: string, orders: int, revenue: float, customers: int, aov: float}>
	 */
	public function getCountryBreakdown(ReportScope $scope, ?int $limit = null): array
	{
		/** @var list<array{countryCode: string, orders: string, revenue: string, customers: string}> $rows */
		$rows = $this->buildBaseQuery($scope)
			->select([
				'countryCode' => '[[addresses.countryCode]]',
				'orders' => 'COUNT(*)',
				'revenue' => 'COALESCE(SUM([[orders.totalPrice]]), 0)',
				'customers' => 'COUNT(DISTINCT [[orders.customerId]])',
			])
			->groupBy('[[addresses.countryCode]]')
			->orderBy([
				'revenue' => SORT_DESC,
			])
			->limit($limit)
			->all();

		$results = [];
		foreach ($rows as $row) {
			$orders = (int) $row['orders'];
			$revenue = (float) $row['revenue'];

			$results[] = [
				'countryCode' => (string) $row['countryCode'],
				'orders' => $orders,
				'revenue' => $revenue,
				'customers' => (int) $row['customers'],
				'aov' => $orders > 0 ? $revenue / $orders : 0.0,
			];
		}

		return $results;
	}

	/**
	 * Top administrative areas (states/provinces) for a country.
	 *
	 * @return list<array{administrativeArea: string, orders: int, revenue: float, customers: int, aov: float}>
	 */
	public function getAdminAreaBreakdown(ReportScope $scope, string $countryCode, ?int $limit = null): array
	{
		/** @var list<array{administrativeArea: string, orders: string, revenue: string, customers: string}> $rows */
		$rows = $this->buildBaseQuery($scope)
			->select([
				'administrativeArea' => '[[addresses.administrativeArea]]',
				'orders' => 'COUNT(*)',
				'revenue' => 'COALESCE(SUM([[orders.totalPrice]]), 0)',
				'customers' => 'COUNT(DISTINCT [[orders.customerId]])',
			])
			->andWhere([
				'[[addresses.countryCode]]' => $countryCode,
			])
			->andWhere([
				'not', [
					'[[addresses.administrativeArea]]' => null,
				],
			])
			->groupBy('[[addresses.administrativeArea]]')
			->orderBy([
				'revenue' => SORT_DESC,
			])
			->limit($limit)
			->all();

		$results = [];
		foreach ($rows as $row) {
			$orders = (int) $row['orders'];
			$revenue = (float) $row['revenue'];

			$results[] = [
				'administrativeArea' => (string) $row['administrativeArea'],
				'orders' => $orders,
				'revenue' => $revenue,
				'customers' => (int) $row['customers'],
				'aov' => $orders > 0 ? $revenue / $orders : 0.0,
			];
		}

		return $results;
	}

	/**
	 * Cities within an administrative area. Postal codes ignored — collapses
	 * case variants (e.g. "FRIENDSWOOD" / "Friendswood") into one row, preferring
	 * the non-all-caps display. Customer counts may slightly overstate when a
	 * single customer used multiple variants; acceptable trade-off.
	 *
	 * @return list<array{locality: string, orders: int, revenue: float, customers: int, aov: float}>
	 */
	public function getLocalityBreakdown(ReportScope $scope, string $countryCode, string $administrativeArea, ?int $limit = null): array
	{
		/** @var list<array{locality: ?string, orders: string, revenue: string, customers: string}> $rows */
		$rows = $this->buildBaseQuery($scope)
			->select([
				'locality' => '[[addresses.locality]]',
				'orders' => 'COUNT(*)',
				'revenue' => 'COALESCE(SUM([[orders.totalPrice]]), 0)',
				'customers' => 'COUNT(DISTINCT [[orders.customerId]])',
			])
			->andWhere([
				'[[addresses.countryCode]]' => $countryCode,
				'[[addresses.administrativeArea]]' => $administrativeArea,
			])
			->groupBy(['[[addresses.locality]]'])
			->all();

		$grouped = [];
		foreach ($rows as $row) {
			$locality = (string) ($row['locality'] ?? '');
			$key = strtolower($locality);

			if (! isset($grouped[$key])) {
				$grouped[$key] = [
					'locality' => $locality,
					'orders' => 0,
					'revenue' => 0.0,
					'customers' => 0,
				];
			} elseif ($this->isAllCaps($grouped[$key]['locality']) && ! $this->isAllCaps($locality) && $locality !== '') {
				$grouped[$key]['locality'] = $locality;
			}

			$grouped[$key]['orders'] += (int) $row['orders'];
			$grouped[$key]['revenue'] += (float) $row['revenue'];
			$grouped[$key]['customers'] += (int) $row['customers'];
		}

		usort($grouped, static fn (array $left, array $right): int => $right['revenue'] <=> $left['revenue']);

		if ($limit !== null) {
			$grouped = array_slice($grouped, 0, $limit);
		}

		$results = [];
		foreach ($grouped as $row) {
			$orders = $row['orders'];
			$revenue = $row['revenue'];

			$results[] = [
				'locality' => $row['locality'],
				'orders' => $orders,
				'revenue' => $revenue,
				'customers' => $row['customers'],
				'aov' => $orders > 0 ? $revenue / $orders : 0.0,
			];
		}

		return $results;
	}

	/**
	 * Top cities globally (across all countries/regions). Used on the world view
	 * of the Locations page. Postal codes ignored. Case variants collapsed
	 * (prefer non-all-caps display).
	 *
	 * @return list<array{locality: string, administrativeArea: string, adminAreaName: string, countryCode: string, countryName: string, orders: int, revenue: float, customers: int, aov: float}>
	 */
	public function getTopLocalitiesGlobal(ReportScope $scope, ?int $limit = null): array
	{
		/** @var list<array{locality: ?string, administrativeArea: ?string, countryCode: string, orders: string, revenue: string, customers: string}> $rows */
		$rows = $this->buildBaseQuery($scope)
			->select([
				'locality' => '[[addresses.locality]]',
				'administrativeArea' => '[[addresses.administrativeArea]]',
				'countryCode' => '[[addresses.countryCode]]',
				'orders' => 'COUNT(*)',
				'revenue' => 'COALESCE(SUM([[orders.totalPrice]]), 0)',
				'customers' => 'COUNT(DISTINCT [[orders.customerId]])',
			])
			->andWhere([
				'not', [
					'[[addresses.locality]]' => null,
				],
			])
			->andWhere(['!=', '[[addresses.locality]]', ''])
			->groupBy(['[[addresses.countryCode]]', '[[addresses.administrativeArea]]', '[[addresses.locality]]'])
			->all();

		$countryRepository = Craft::$app->getAddresses()->getCountryRepository();
		$subdivisionRepository = Craft::$app->getAddresses()->getSubdivisionRepository();
		$language = Craft::$app->language;

		$countryNames = [];
		$subdivisionNames = [];

		$grouped = [];
		foreach ($rows as $row) {
			$locality = (string) ($row['locality'] ?? '');
			$administrativeArea = (string) ($row['administrativeArea'] ?? '');
			$countryCode = (string) $row['countryCode'];
			$key = $countryCode . '|' . $administrativeArea . '|' . strtolower($locality);

			if (! isset($grouped[$key])) {
				$grouped[$key] = [
					'locality' => $locality,
					'administrativeArea' => $administrativeArea,
					'countryCode' => $countryCode,
					'orders' => 0,
					'revenue' => 0.0,
					'customers' => 0,
				];
			} elseif ($this->isAllCaps($grouped[$key]['locality']) && ! $this->isAllCaps($locality) && $locality !== '') {
				$grouped[$key]['locality'] = $locality;
			}

			$grouped[$key]['orders'] += (int) $row['orders'];
			$grouped[$key]['revenue'] += (float) $row['revenue'];
			$grouped[$key]['customers'] += (int) $row['customers'];
		}

		usort($grouped, static fn (array $left, array $right): int => $right['revenue'] <=> $left['revenue']);

		if ($limit !== null) {
			$grouped = array_slice($grouped, 0, $limit);
		}

		$results = [];
		foreach ($grouped as $row) {
			$countryCode = $row['countryCode'];
			if (! isset($countryNames[$countryCode])) {
				$countryNames[$countryCode] = $countryRepository->get($countryCode, $language)->getName();
			}

			if (! isset($subdivisionNames[$countryCode])) {
				/** @var array<string, string> $list */
				$list = $subdivisionRepository->getList([$countryCode], $language);
				$subdivisionNames[$countryCode] = $list;
			}

			$administrativeArea = $row['administrativeArea'];
			$adminAreaName = $administrativeArea === ''
				? ''
				: ($subdivisionNames[$countryCode][$administrativeArea] ?? $administrativeArea);

			$orders = $row['orders'];
			$revenue = $row['revenue'];

			$results[] = [
				'locality' => $row['locality'],
				'administrativeArea' => $administrativeArea,
				'adminAreaName' => $adminAreaName,
				'countryCode' => $countryCode,
				'countryName' => $countryNames[$countryCode],
				'orders' => $orders,
				'revenue' => $revenue,
				'customers' => $row['customers'],
				'aov' => $orders > 0 ? $revenue / $orders : 0.0,
			];
		}

		return $results;
	}

	/**
	 * Return a flat list of all shipping locations that have completed orders.
	 *
	 * Each option is a path token (country, country+state, country+state+city) plus
	 * a display label suitable for a typeahead. Result is sorted by label.
	 *
	 * @return list<array{value: string, label: string, level: string, countryCode: string, administrativeArea?: string, locality?: string}>
	 */
	public function getShippingLocationOptions(): array
	{
		$baseQuery = (new Query())
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->innerJoin(
				[
					'addresses' => CraftTable::ADDRESSES,
				],
				'[[orders.shippingAddressId]] = [[addresses.id]]'
			)
			->where([
				'and',
				['=', '[[orders.isCompleted]]', true],
				[
					'not', [
						'[[addresses.countryCode]]' => null,
					],
				],
				['!=', '[[addresses.countryCode]]', ''],
			]);
		$baseQuery = NotTrashed::join($baseQuery, 'orders');

		/** @var list<array{countryCode: string}> $countryRows */
		$countryRows = (clone $baseQuery)
			->select([
				'countryCode' => '[[addresses.countryCode]]',
			])
			->groupBy('[[addresses.countryCode]]')
			->all();

		/** @var list<array{countryCode: string, administrativeArea: string}> $stateRows */
		$stateRows = (clone $baseQuery)
			->select([
				'countryCode' => '[[addresses.countryCode]]',
				'administrativeArea' => '[[addresses.administrativeArea]]',
			])
			->andWhere([
				'not', [
					'[[addresses.administrativeArea]]' => null,
				],
			])
			->andWhere(['!=', '[[addresses.administrativeArea]]', ''])
			->groupBy(['[[addresses.countryCode]]', '[[addresses.administrativeArea]]'])
			->all();

		/** @var list<array{countryCode: string, administrativeArea: string, locality: string}> $localityRows */
		$localityRows = (clone $baseQuery)
			->select([
				'countryCode' => '[[addresses.countryCode]]',
				'administrativeArea' => '[[addresses.administrativeArea]]',
				'locality' => '[[addresses.locality]]',
			])
			->andWhere([
				'not', [
					'[[addresses.locality]]' => null,
				],
			])
			->andWhere(['!=', '[[addresses.locality]]', ''])
			->groupBy(['[[addresses.countryCode]]', '[[addresses.administrativeArea]]', '[[addresses.locality]]'])
			->all();

		$countryRepository = Craft::$app->getAddresses()->getCountryRepository();
		$subdivisionRepository = Craft::$app->getAddresses()->getSubdivisionRepository();
		$language = Craft::$app->language;

		$countryNames = [];
		$subdivisionNames = [];

		$options = [];

		foreach ($countryRows as $countryRow) {
			$countryCode = (string) $countryRow['countryCode'];
			$countryName = $countryNames[$countryCode] ?? ($countryNames[$countryCode] = $countryRepository->get($countryCode, $language)->getName());

			$options[] = [
				'value' => $countryCode,
				'label' => $countryName,
				'level' => 'country',
				'countryCode' => $countryCode,
			];
		}

		foreach ($stateRows as $stateRow) {
			$countryCode = (string) $stateRow['countryCode'];
			$administrativeArea = (string) $stateRow['administrativeArea'];

			$countryName = $countryNames[$countryCode] ?? ($countryNames[$countryCode] = $countryRepository->get($countryCode, $language)->getName());

			if (! isset($subdivisionNames[$countryCode])) {
				/** @var array<string, string> $list */
				$list = $subdivisionRepository->getList([$countryCode], $language);
				$subdivisionNames[$countryCode] = $list;
			}

			$adminAreaName = $subdivisionNames[$countryCode][$administrativeArea] ?? $administrativeArea;

			$options[] = [
				'value' => $countryCode . '|' . $administrativeArea,
				'label' => $adminAreaName . ', ' . $countryName,
				'level' => 'administrativeArea',
				'countryCode' => $countryCode,
				'administrativeArea' => $administrativeArea,
			];
		}

		foreach ($localityRows as $localityRow) {
			$countryCode = (string) $localityRow['countryCode'];
			$administrativeArea = (string) $localityRow['administrativeArea'];
			$locality = (string) $localityRow['locality'];

			$countryName = $countryNames[$countryCode] ?? ($countryNames[$countryCode] = $countryRepository->get($countryCode, $language)->getName());

			if (! isset($subdivisionNames[$countryCode])) {
				/** @var array<string, string> $list */
				$list = $subdivisionRepository->getList([$countryCode], $language);
				$subdivisionNames[$countryCode] = $list;
			}

			$adminAreaName = $subdivisionNames[$countryCode][$administrativeArea] ?? $administrativeArea;

			$options[] = [
				'value' => $countryCode . '|' . $administrativeArea . '|' . $locality,
				'label' => $locality . ', ' . $adminAreaName . ', ' . $countryName,
				'level' => 'locality',
				'countryCode' => $countryCode,
				'administrativeArea' => $administrativeArea,
				'locality' => $locality,
			];
		}

		// Drop obviously bad locality data (street addresses leaking into the
		// locality column). Heuristic: starts with a digit or is unusually long.
		$cleaned = [];
		foreach ($options as $option) {
			if ($option['level'] === 'locality' && array_key_exists('locality', $option)) {
				$locality = $option['locality'];
				if (preg_match('/^\d/', $locality) === 1) {
					continue;
				}

				if (strlen($locality) > 40) {
					continue;
				}
			}

			$cleaned[] = $option;
		}

		// Dedupe by display label. When two backing rows produce the same label
		// (e.g. administrativeArea stored as both "TX" and "Texas"), keep the
		// option whose code matches a canonical subdivision entry.
		$canonicalSubdivisionCodes = [];
		foreach ($subdivisionNames as $countryCode => $list) {
			$canonicalSubdivisionCodes[$countryCode] = $list;
		}

		$byLabel = [];
		foreach ($cleaned as $option) {
			$existing = $byLabel[$option['label']] ?? null;
			if ($existing === null) {
				$byLabel[$option['label']] = $option;
				continue;
			}

			if ($option['level'] !== 'administrativeArea') {
				continue;
			}

			if ($existing['level'] !== 'administrativeArea') {
				continue;
			}

			$countryCode = $option['countryCode'];
			$canonical = $canonicalSubdivisionCodes[$countryCode] ?? [];

			$existingIsCanonical = isset($canonical[$existing['administrativeArea']]);
			$candidateIsCanonical = isset($canonical[$option['administrativeArea']]);

			if ($candidateIsCanonical && ! $existingIsCanonical) {
				$byLabel[$option['label']] = $option;
			}
		}

		$options = array_values($byLabel);

		usort($options, static fn (array $left, array $right): int => strcasecmp((string) $left['label'], (string) $right['label']));

		return $options;
	}

	private function isAllCaps(string $value): bool
	{
		if ($value === '') {
			return false;
		}

		return mb_strtoupper($value) === $value && preg_match('/\p{Lu}/u', $value) === 1;
	}

	/**
	 * @return Query<array-key, mixed>
	 */
	private function buildBaseQuery(ReportScope $scope): Query
	{
		$dateCondition = $this->buildDateCondition($scope, 'orders');

		$query = (new Query())
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->innerJoin(
				[
					'addresses' => CraftTable::ADDRESSES,
				],
				'[[orders.shippingAddressId]] = [[addresses.id]]'
			)
			->where($dateCondition)
			->andWhere([
				'not', [
					'[[addresses.countryCode]]' => null,
				],
			])
			->andWhere(['!=', '[[addresses.countryCode]]', '']);

		$locationCondition = $scope->shippingLocationsCondition('addresses');
		if ($locationCondition !== null) {
			$query->andWhere($locationCondition);
		}

		return NotTrashed::join($query, 'orders');
	}
}
