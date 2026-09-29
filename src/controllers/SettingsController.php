<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Controller;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\services\VariantFields;
use yii\base\Action;
use yii\web\Response;

class SettingsController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	/**
	 * @param Action<static> $action
	 */
	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requirePermission(Plugin::PERMISSION_MANAGE_SETTINGS);

		return true;
	}

	public function actionIndex(): Response
	{
		$plugin = Plugin::getInstance();

		return $this->renderTemplate('best-sellers/_settings', [
			'title' => Craft::t('app', 'Settings'),
			'selectedSubnavItem' => 'settings',
			'plugin' => $plugin,
			'settings' => $plugin->getSettings(),
			'productTypeFieldOptions' => $this->getProductTypeFieldOptions(),
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$plugin = Plugin::getInstance();

		$rawHandles = $this->request->getBodyParam('defaultOrderStatusHandles', []) ?: [];
		if (! is_array($rawHandles)) {
			$rawHandles = [];
		}

		$defaultOrderStatusHandles = [];
		foreach ($rawHandles as $rawHandle) {
			if (is_string($rawHandle) && $rawHandle !== '') {
				$defaultOrderStatusHandles[] = $rawHandle;
			}
		}

		$settings = $plugin->getSettings();
		$settings->defaultOrderStatusHandles = $defaultOrderStatusHandles;
		$settings->unitCostFields = $this->resolveUnitCostFields();
		$settings->filterFields = $this->resolveFilterFields();

		if (! $settings->validate()) {
			Craft::$app->getSession()->setError(Craft::t('commerce', 'Couldn’t save settings.'));
			Craft::$app->getUrlManager()->setRouteParams([
				'settings' => $settings,
			]);
			return null;
		}

		Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());

		Craft::$app->getSession()->setNotice(Craft::t('commerce', 'Settings saved.'));

		return $this->redirectToPostedUrl();
	}

	/**
	 * @return list<array{uid: string, name: string, unitCostOptions: array<string, string>, filterOptions: array<string, string>}>
	 */
	private function getProductTypeFieldOptions(): array
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$variantFields = Plugin::getInstance()->variantFields;

		$productTypeFieldOptions = [];
		foreach ($commerce->getProductTypes()->getAllProductTypes() as $productType) {
			$productTypeFieldOptions[] = [
				'uid' => (string) $productType->uid,
				'name' => (string) $productType->name,
				'unitCostOptions' => [
					'' => Craft::t('best-sellers', 'settings.field.none'),
					...$variantFields->getInstanceOptions($productType, VariantFields::UNIT_COST_FIELD_TYPES),
				],
				'filterOptions' => $variantFields->getInstanceOptions($productType, VariantFields::FILTER_FIELD_TYPES),
			];
		}

		return $productTypeFieldOptions;
	}

	/**
	 * @return array<string, string>
	 */
	private function resolveUnitCostFields(): array
	{
		$rawUnitCostFields = $this->request->getBodyParam('unitCostFields', []);
		if (! is_array($rawUnitCostFields)) {
			return [];
		}

		$unitCostFields = [];
		foreach ($rawUnitCostFields as $productTypeUid => $instanceUid) {
			if (is_string($instanceUid) && $instanceUid !== '') {
				$unitCostFields[(string) $productTypeUid] = $instanceUid;
			}
		}

		return $unitCostFields;
	}

	/**
	 * @return array<string, list<string>>
	 */
	private function resolveFilterFields(): array
	{
		$rawFilterFields = $this->request->getBodyParam('filterFields', []);
		if (! is_array($rawFilterFields)) {
			return [];
		}

		$filterFields = [];
		foreach ($rawFilterFields as $productTypeUid => $instanceUids) {
			if (! is_array($instanceUids)) {
				continue;
			}

			$filterFields[(string) $productTypeUid] = array_values(array_filter($instanceUids, static fn (mixed $instanceUid): bool => is_string($instanceUid) && $instanceUid !== ''));
		}

		return array_filter($filterFields);
	}
}
