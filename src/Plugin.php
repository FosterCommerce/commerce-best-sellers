<?php

namespace fostercommerce\bestsellers;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\db\ProductQuery;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\elements\db\ElementQuery;
use craft\events\CancelableEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use fostercommerce\bestsellers\behaviors\SaleQueryBehavior;
use fostercommerce\bestsellers\behaviors\SalesBehavior;
use fostercommerce\bestsellers\helpers\Query;
use fostercommerce\bestsellers\models\Settings;
use fostercommerce\bestsellers\services\Sales;
use fostercommerce\bestsellers\utilities\BackfillUtility;
use fostercommerce\bestsellers\variables\BestSellersVariable;
use yii\base\Event;

/**
 * @property-read Settings $settings
 * @property-read Sales $sales
 */
class Plugin extends BasePlugin
{
	public string $schemaVersion = '1.0.0';

	public bool $hasCpSettings = false;

	public bool $hasCpSection = true;

	/**
	 * @return array<array-key, mixed>
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'sales' => Sales::class,
			],
		];
	}

	public function init(): void
	{
		parent::init();

		$this->attachEventHandlers();

		// Any code that creates an element query or loads Twig should be deferred until
		// after Craft is fully initialized, to avoid conflicts with other plugins/modules
		Craft::$app->onInit(function (): void {
			// ...
		});

		// Register the backfill utility
		Event::on(
			Utilities::class,
			Utilities::EVENT_REGISTER_UTILITIES,
			function (RegisterComponentTypesEvent $event): void {
				$event->types[] = BackfillUtility::class;
			}
		);

		// Register services if not already done
		$this->setComponents([
			'sales' => Sales::class,
		]);

		// Register the variable so it becomes available as craft.bestsellers
		Event::on(
			CraftVariable::class,
			CraftVariable::EVENT_INIT,
			static function (Event $event): void {
				/** @var CraftVariable $variable */
				$variable = $event->sender;
				$variable->set('bestsellers', BestSellersVariable::class);
			}
		);
	}

	/**
	 * @return ?array<non-empty-string, mixed>
	 */
	public function getCpNavItem(): ?array
	{
		$navItem = parent::getCpNavItem();
		$navItem['label'] = 'Best Sellers';
		$navItem['url'] = 'best-sellers';
		$navItem['subnav'] = [
			'dashboard' => [
				'label' => 'Best Sellers',
				'url' => 'best-sellers/',
			],
			'reports' => [
				'label' => 'Reports',
				'url' => 'best-sellers/reports',
			],
		];
		return $navItem;
	}

	protected function createSettingsModel(): ?Model
	{
		return Craft::createObject(Settings::class);
	}

	protected function settingsHtml(): ?string
	{
		return Craft::$app->view->renderTemplate('best-sellers/_settings.twig', [
			'plugin' => $this,
			'settings' => $this->getSettings(),
		]);
	}

	private function attachEventHandlers(): void
	{
		if (! Craft::$app->getRequest()->getIsConsoleRequest() && Craft::$app->getRequest()->getIsCpRequest()) {
			$this->registerCpRoutes();
		}

		Event::on(
			Variant::class,
			Model::EVENT_DEFINE_BEHAVIORS,
			static function (DefineBehaviorsEvent $event): void {
				$event->behaviors['bestSellers'] = SalesBehavior::class;
			}
		);

		Event::on(
			Product::class,
			Model::EVENT_DEFINE_BEHAVIORS,
			static function (DefineBehaviorsEvent $event): void {
				$event->behaviors['bestSellers'] = SalesBehavior::class;
			}
		);

		Event::on(
			VariantQuery::class,
			Model::EVENT_DEFINE_BEHAVIORS,
			static function (DefineBehaviorsEvent $event): void {
				$event->behaviors['bestSellers'] = SaleQueryBehavior::class;
			}
		);

		Event::on(
			ProductQuery::class,
			Model::EVENT_DEFINE_BEHAVIORS,
			static function (DefineBehaviorsEvent $event): void {
				$event->behaviors['bestSellers'] = SaleQueryBehavior::class;
			}
		);

		Event::on(
			VariantQuery::class,
			ElementQuery::EVENT_BEFORE_PREPARE,
			static function (CancelableEvent $event): void {
				/** @var ElementQuery<array-key, Variant> $variantQuery */
				$variantQuery = $event->sender;
				Query::attachQuery($variantQuery, 'variantId', '[[variant_sales_cte.variantId]] = [[commerce_variants.id]]');
			}
		);

		Event::on(
			ProductQuery::class,
			ElementQuery::EVENT_BEFORE_PREPARE,
			static function (CancelableEvent $event): void {
				/** @var ElementQuery<array-key, Product> $productQuery */
				$productQuery = $event->sender;
				Query::attachQuery($productQuery, 'productId', '[[variant_sales_cte.productId]] = [[commerce_products.id]]');
			}
		);

		Event::on(
			Order::class,
			Order::EVENT_AFTER_COMPLETE_ORDER,
			function (Event $event): void {
				/** @var Order $order */
				$order = $event->sender;
				$this->sales->logOrderSales($order);
			}
		);
	}

	private function registerCpRoutes(): void
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			static function (RegisterUrlRulesEvent $registerUrlRulesEvent): void {
				$registerUrlRulesEvent->rules['best-sellers'] = 'best-sellers/dashboard';
				$registerUrlRulesEvent->rules['best-sellers/reports'] = 'best-sellers/reports';
			}
		);
	}
}
