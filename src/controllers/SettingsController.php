<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\web\Controller;
use fostercommerce\bestsellers\Plugin;
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
			'title' => Craft::t('best-sellers', 'Settings'),
			'selectedSubnavItem' => 'settings',
			'plugin' => $plugin,
			'settings' => $plugin->getSettings(),
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

		if (! $settings->validate()) {
			Craft::$app->getSession()->setError(Craft::t('best-sellers', 'Couldn’t save settings.'));
			Craft::$app->getUrlManager()->setRouteParams([
				'settings' => $settings,
			]);
			return null;
		}

		Craft::$app->getPlugins()->savePluginSettings($plugin, $settings->toArray());

		Craft::$app->getSession()->setNotice(Craft::t('best-sellers', 'Settings saved.'));

		return $this->redirectToPostedUrl();
	}
}
