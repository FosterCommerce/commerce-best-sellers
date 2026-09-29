<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\elements\Product;
use craft\commerce\Plugin as Commerce;
use craft\web\Request;
use fostercommerce\bestsellers\assetbundles\LocationsAsset;
use fostercommerce\bestsellers\models\ProductRow;
use fostercommerce\bestsellers\models\ReportScope;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

class LocationsController extends BaseReportController
{
	/**
	 * Countries with a region-level TopoJSON/GeoJSON file in dist/data/regions/.
	 *
	 * @var array<string, string> countryCode => filename
	 */
	private const REGION_MAP_FILES = [
		'US' => 'US.json',
		'CA' => 'CA.geojson',
	];

	public function actionIndex(): Response
	{
		$view = Craft::$app->getView();
		$view->registerAssetBundle(LocationsAsset::class);

		$assetManager = Craft::$app->getAssetManager();
		$worldTopoUrl = $assetManager->getPublishedUrl(
			'@fostercommerce/bestsellers/assetbundles/dist/data/countries-110m.json',
			true
		);

		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$countryParam = $request->getQueryParam('country');
		$regionParam = $request->getQueryParam('region');
		$activeCountry = is_string($countryParam) && $countryParam !== '' ? strtoupper($countryParam) : null;
		$activeRegion = is_string($regionParam) && $regionParam !== '' ? $regionParam : null;

		$scope = $this->resolveScope();
		$locationStats = Plugin::getInstance()->locationStats;

		$countries = $locationStats->getCountryBreakdown($scope);
		$enrichedCountries = $this->enrichCountries($countries);

		$activeCountryRow = null;
		if ($activeCountry !== null) {
			foreach ($enrichedCountries as $enrichedCountry) {
				if ($enrichedCountry['countryCode'] === $activeCountry) {
					$activeCountryRow = $enrichedCountry;
					break;
				}
			}
		}

		$topLocalities = $locationStats->getTopLocalitiesGlobal($scope, 100);

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$primaryStore = $commerce->getStores()->getPrimaryStore();
		$currency = $primaryStore !== null
			? $commerce->getPaymentCurrencies()->getPrimaryPaymentCurrencyIso($primaryStore->id)
			: 'USD';

		if ($activeCountryRow !== null) {
			$adminAreas = $locationStats->getAdminAreaBreakdown($scope, $activeCountryRow['countryCode']);
			$enrichedAdminAreas = $this->enrichAdminAreas($adminAreas, $activeCountryRow['countryCode']);
			$regionTopoUrl = $this->getRegionTopoUrl($activeCountryRow['countryCode']);
			$hasStateMap = $regionTopoUrl !== null;

			$activeAdminArea = null;
			if ($activeRegion !== null) {
				foreach ($enrichedAdminAreas as $enrichedAdminArea) {
					if (strcasecmp($enrichedAdminArea['code'], $activeRegion) === 0) {
						$activeAdminArea = $enrichedAdminArea;
						break;
					}
				}
			}

			$topProducts = $this->getLocationTopProducts($scope, $activeCountryRow['countryCode'], $activeAdminArea['code'] ?? null);
			$topProductElements = Product::find()
				->id(array_map(static fn (ProductRow $productRow): int => $productRow->productId, $topProducts))
				->status(null)
				->indexBy('id')
				->all();

			if ($activeAdminArea !== null) {
				$localities = $locationStats->getLocalityBreakdown(
					$scope,
					$activeCountryRow['countryCode'],
					$activeAdminArea['code']
				);

				return $this->renderTemplate('best-sellers/_locations', [
					'title' => Craft::t('best-sellers', 'nav.locations'),
					'selectedSubnavItem' => 'locations',
					'from' => $scope->from,
					'to' => $scope->to,
					'preset' => $scope->preset,
					'scope' => $scope,
					'mode' => 'region',
					'activeCountry' => $activeCountryRow,
					'activeAdminArea' => $activeAdminArea,
					'adminAreas' => $enrichedAdminAreas,
					'localities' => $localities,
					'topProducts' => $topProducts,
					'topProductElements' => $topProductElements,
					'mapData' => $hasStateMap ? $this->buildAdminAreaMapData($enrichedAdminAreas, $activeCountryRow['countryCode']) : [],
					'mapTopoUrl' => $regionTopoUrl,
					'baseTopoUrl' => $worldTopoUrl,
					'mapMatchKey' => 'name',
					'hasMap' => $hasStateMap,
					'currency' => $currency,
				]);
			}

			return $this->renderTemplate('best-sellers/_locations', [
				'title' => Craft::t('best-sellers', 'nav.locations'),
				'selectedSubnavItem' => 'locations',
				'from' => $scope->from,
				'to' => $scope->to,
				'preset' => $scope->preset,
				'scope' => $scope,
				'mode' => 'country',
				'activeCountry' => $activeCountryRow,
				'adminAreas' => $enrichedAdminAreas,
				'topProducts' => $topProducts,
				'topProductElements' => $topProductElements,
				'mapData' => $hasStateMap ? $this->buildAdminAreaMapData($enrichedAdminAreas, $activeCountryRow['countryCode']) : [],
				'mapTopoUrl' => $regionTopoUrl,
				'baseTopoUrl' => $worldTopoUrl,
				'mapMatchKey' => 'name',
				'hasMap' => $hasStateMap,
				'currency' => $currency,
			]);
		}

		return $this->renderTemplate('best-sellers/_locations', [
			'title' => Craft::t('best-sellers', 'nav.locations'),
			'selectedSubnavItem' => 'locations',
			'from' => $scope->from,
			'to' => $scope->to,
			'preset' => $scope->preset,
			'scope' => $scope,
			'mode' => 'world',
			'activeCountry' => null,
			'countries' => $enrichedCountries,
			'topLocalities' => $topLocalities,
			'mapData' => $this->buildCountryMapData($enrichedCountries),
			'mapTopoUrl' => $worldTopoUrl,
			'baseTopoUrl' => null,
			'mapMatchKey' => 'numericCode',
			'hasMap' => true,
			'currency' => $currency,
		]);
	}

	/**
	 * @return list<ProductRow>
	 */
	private function getLocationTopProducts(ReportScope $scope, string $countryCode, ?string $administrativeArea): array
	{
		$viewedLocation = [
			'countryCode' => $countryCode,
		];
		if ($administrativeArea !== null) {
			$viewedLocation['administrativeArea'] = $administrativeArea;
		}

		$locationScope = clone $scope;
		$locationScope->shippingLocations = $scope->hasShippingLocationsFilter()
			? $this->intersectShippingLocations($scope->shippingLocations, $viewedLocation)
			: [$viewedLocation];

		// Skip the query when the filter excludes the viewed location, since an empty filter matches every location
		if ($locationScope->shippingLocations === []) {
			return [];
		}

		return Plugin::getInstance()->productStats->getTopProducts($locationScope, 'revenue', 100);
	}

	/**
	 * Keep the filter paths inside the viewed location, narrowed to its administrative area.
	 *
	 * @param list<array{countryCode: string, administrativeArea?: string, locality?: string}> $shippingLocations
	 * @param array{countryCode: string, administrativeArea?: string} $viewedLocation
	 * @return list<array{countryCode: string, administrativeArea?: string, locality?: string}>
	 */
	private function intersectShippingLocations(array $shippingLocations, array $viewedLocation): array
	{
		$viewedAdministrativeArea = $viewedLocation['administrativeArea'] ?? '';
		$intersectedLocations = [];
		foreach ($shippingLocations as $shippingLocation) {
			$filterAdministrativeArea = $shippingLocation['administrativeArea'] ?? '';
			if ($shippingLocation['countryCode'] !== $viewedLocation['countryCode']) {
				continue;
			}

			if ($filterAdministrativeArea !== '' && $viewedAdministrativeArea !== '' && $filterAdministrativeArea !== $viewedAdministrativeArea) {
				continue;
			}

			if ($filterAdministrativeArea === '' && $viewedAdministrativeArea !== '') {
				$shippingLocation['administrativeArea'] = $viewedAdministrativeArea;
			}

			$intersectedLocations[] = $shippingLocation;
		}

		return $intersectedLocations;
	}

	private function getRegionTopoUrl(string $countryCode): ?string
	{
		$file = self::REGION_MAP_FILES[$countryCode] ?? null;
		if ($file === null) {
			return null;
		}

		$url = Craft::$app->getAssetManager()->getPublishedUrl(
			'@fostercommerce/bestsellers/assetbundles/dist/data/regions/' . $file,
			true
		);

		return $url === false ? null : $url;
	}

	/**
	 * @param list<array{countryCode: string, orders: int, revenue: float, customers: int, aov: float}> $countries
	 * @return list<array{countryCode: string, numericCode: ?string, name: string, orders: int, revenue: float, customers: int, aov: float}>
	 */
	private function enrichCountries(array $countries): array
	{
		$repository = Craft::$app->getAddresses()->getCountryRepository();
		$language = Craft::$app->language;

		$enriched = [];
		foreach ($countries as $row) {
			$country = $repository->get($row['countryCode'], $language);

			$enriched[] = [
				'countryCode' => $row['countryCode'],
				'numericCode' => $country->getNumericCode(),
				'name' => $country->getName(),
				'orders' => $row['orders'],
				'revenue' => $row['revenue'],
				'customers' => $row['customers'],
				'aov' => $row['aov'],
			];
		}

		return $enriched;
	}

	/**
	 * @param list<array{administrativeArea: string, orders: int, revenue: float, customers: int, aov: float}> $adminAreas
	 * @return list<array{code: string, name: string, orders: int, revenue: float, customers: int, aov: float}>
	 */
	private function enrichAdminAreas(array $adminAreas, string $countryCode): array
	{
		$subdivisionRepository = Craft::$app->getAddresses()->getSubdivisionRepository();
		/** @var array<string, string> $subdivisionNames */
		$subdivisionNames = $subdivisionRepository->getList([$countryCode], Craft::$app->language);

		$enriched = [];
		foreach ($adminAreas as $adminArea) {
			$code = $adminArea['administrativeArea'];

			$enriched[] = [
				'code' => $code,
				'name' => $subdivisionNames[$code] ?? $code,
				'orders' => $adminArea['orders'],
				'revenue' => $adminArea['revenue'],
				'customers' => $adminArea['customers'],
				'aov' => $adminArea['aov'],
			];
		}

		return $enriched;
	}

	/**
	 * @param list<array{countryCode: string, numericCode: ?string, name: string, orders: int, revenue: float, customers: int, aov: float}> $enrichedCountries
	 * @return list<array{countryCode: string, numericCode: string, name: string, revenue: float, orders: int, customers: int}>
	 */
	private function buildCountryMapData(array $enrichedCountries): array
	{
		$mapData = [];
		foreach ($enrichedCountries as $enrichedCountry) {
			if ($enrichedCountry['numericCode'] === null) {
				continue;
			}

			$mapData[] = [
				'countryCode' => $enrichedCountry['countryCode'],
				'numericCode' => $enrichedCountry['numericCode'],
				'name' => $enrichedCountry['name'],
				'revenue' => $enrichedCountry['revenue'],
				'orders' => $enrichedCountry['orders'],
				'customers' => $enrichedCountry['customers'],
			];
		}

		return $mapData;
	}

	/**
	 * Build map data including ALL subdivisions for the country, so the map can
	 * navigate to states with no orders. Stateless states get zero metrics.
	 *
	 * @param list<array{code: string, name: string, orders: int, revenue: float, customers: int, aov: float}> $enrichedAdminAreas
	 * @return list<array{code: string, name: string, revenue: float, orders: int, customers: int}>
	 */
	private function buildAdminAreaMapData(array $enrichedAdminAreas, string $countryCode): array
	{
		$indexed = [];
		foreach ($enrichedAdminAreas as $enrichedAdminArea) {
			$indexed[$enrichedAdminArea['code']] = $enrichedAdminArea;
		}

		$subdivisionRepository = Craft::$app->getAddresses()->getSubdivisionRepository();
		/** @var array<string, string> $subdivisionNames */
		$subdivisionNames = $subdivisionRepository->getList([$countryCode], Craft::$app->language);

		$mapData = [];
		foreach ($subdivisionNames as $code => $name) {
			$existing = $indexed[$code] ?? null;
			$mapData[] = [
				'code' => $code,
				'name' => $name,
				'revenue' => $existing['revenue'] ?? 0.0,
				'orders' => $existing['orders'] ?? 0,
				'customers' => $existing['customers'] ?? 0,
			];
		}

		return $mapData;
	}
}
