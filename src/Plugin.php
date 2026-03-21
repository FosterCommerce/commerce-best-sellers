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
use craft\events\RegisterUserPermissionsEvent;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use fostercommerce\bestsellers\behaviors\SaleQueryBehavior;
use fostercommerce\bestsellers\behaviors\SalesBehavior;
use fostercommerce\bestsellers\helpers\Query;
use fostercommerce\bestsellers\models\Settings;
use fostercommerce\bestsellers\services\CartAbandonment;
use fostercommerce\bestsellers\services\CustomerStats;
use fostercommerce\bestsellers\services\DailyStats;
use fostercommerce\bestsellers\services\DateRange;
use fostercommerce\bestsellers\services\OperationsStats;
use fostercommerce\bestsellers\services\ProductStats;
use fostercommerce\bestsellers\services\Sales;
use fostercommerce\bestsellers\utilities\BackfillUtility;
use fostercommerce\bestsellers\variables\BestSellersVariable;
use yii\base\Event;

/**
 * @property-read Settings $settings
 * @property-read Sales $sales
 * @property-read DailyStats $dailyStats
 * @property-read DateRange $dateRange
 * @property-read ProductStats $productStats
 * @property-read CustomerStats $customerStats
 * @property-read OperationsStats $operationsStats
 * @property-read CartAbandonment $cartAbandonment
 */
class Plugin extends BasePlugin
{
	public const PERMISSION_VIEW_REPORTS = 'best-sellers:viewReports';

	public const PERMISSION_BACKFILL = 'best-sellers:backfill';

	public string $schemaVersion = '1.1.0';

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
				'dailyStats' => DailyStats::class,
				'dateRange' => DateRange::class,
				'productStats' => ProductStats::class,
				'customerStats' => CustomerStats::class,
				'operationsStats' => OperationsStats::class,
			],
		];
	}

	public function init(): void
	{
		parent::init();

		$this->attachEventHandlers();

		// Register the backfill utility
		Event::on(
			Utilities::class,
			Utilities::EVENT_REGISTER_UTILITIES,
			function (RegisterComponentTypesEvent $event): void {
				$event->types[] = BackfillUtility::class;
			}
		);

		$this->setComponents([
			'sales' => Sales::class,
			'dailyStats' => DailyStats::class,
			'dateRange' => DateRange::class,
			'productStats' => ProductStats::class,
			'customerStats' => CustomerStats::class,
			'operationsStats' => OperationsStats::class,
			'cartAbandonment' => CartAbandonment::class,
		]);

		Event::on(
			CraftVariable::class,
			CraftVariable::EVENT_INIT,
			static function (Event $event): void {
				/** @var CraftVariable $variable */
				$variable = $event->sender;
				$variable->set('bestsellers', BestSellersVariable::class);
			}
		);

		Event::on(
			UserPermissions::class,
			UserPermissions::EVENT_REGISTER_PERMISSIONS,
			static function (RegisterUserPermissionsEvent $event): void {
				$event->permissions[] = [
					'heading' => Craft::t('best-sellers', 'Best Sellers'),
					'permissions' => [
						self::PERMISSION_VIEW_REPORTS => [
							'label' => Craft::t('best-sellers', 'View reports'),
						],
						self::PERMISSION_BACKFILL => [
							'label' => Craft::t('best-sellers', 'Backfill order data'),
						],
					],
				];
			}
		);
	}

	/**
	 * @return ?array<non-empty-string, mixed>
	 */
	public function getCpNavItem(): ?array
	{
		if (! Craft::$app->getUser()->checkPermission(self::PERMISSION_VIEW_REPORTS)) {
			return null;
		}

		$navItem = parent::getCpNavItem();
		$navItem['label'] = Craft::t('best-sellers', 'Best Sellers');
		$navItem['url'] = 'best-sellers';
		$navItem['subnav'] = [
			'overview' => [
				'label' => Craft::t('best-sellers', 'Overview'),
				'url' => 'best-sellers/',
			],
			'orders' => [
				'label' => Craft::t('best-sellers', 'Orders'),
				'url' => 'best-sellers/orders',
			],
			'products' => [
				'label' => Craft::t('best-sellers', 'Products'),
				'url' => 'best-sellers/products',
			],
			'customers' => [
				'label' => Craft::t('best-sellers', 'Customers'),
				'url' => 'best-sellers/customers',
			],
			'operations' => [
				'label' => Craft::t('best-sellers', 'Operations'),
				'url' => 'best-sellers/operations',
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

				// Aggregate daily stats for the order's date
				if ($order->dateOrdered) {
					$this->dailyStats->aggregateDay($order->dateOrdered->format('Y-m-d'));
				}
			}
		);
	}

	private function registerCpRoutes(): void
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			static function (RegisterUrlRulesEvent $registerUrlRulesEvent): void {
				$registerUrlRulesEvent->rules['best-sellers'] = 'best-sellers/overview';
				$registerUrlRulesEvent->rules['best-sellers/orders'] = 'best-sellers/orders';
				$registerUrlRulesEvent->rules['best-sellers/orders/orders-data'] = 'best-sellers/orders/orders-data';
				$registerUrlRulesEvent->rules['best-sellers/orders/export-csv'] = 'best-sellers/orders/export-csv';
				$registerUrlRulesEvent->rules['best-sellers/products'] = 'best-sellers/products';
				$registerUrlRulesEvent->rules['best-sellers/products/orders'] = 'best-sellers/products/orders';
				$registerUrlRulesEvent->rules['best-sellers/products/products-data'] = 'best-sellers/products/products-data';
				$registerUrlRulesEvent->rules['best-sellers/products/product-orders-data'] = 'best-sellers/products/product-orders-data';
				$registerUrlRulesEvent->rules['best-sellers/products/export-csv'] = 'best-sellers/products/export-csv';
				$registerUrlRulesEvent->rules['best-sellers/customers'] = 'best-sellers/customers';
				$registerUrlRulesEvent->rules['best-sellers/customers/customers-data'] = 'best-sellers/customers/customers-data';
				$registerUrlRulesEvent->rules['best-sellers/customers/export-csv'] = 'best-sellers/customers/export-csv';
				$registerUrlRulesEvent->rules['best-sellers/operations'] = 'best-sellers/operations';

				// Backward compatibility redirects
				$registerUrlRulesEvent->rules['best-sellers/reports'] = 'best-sellers/orders';
				$registerUrlRulesEvent->rules['best-sellers/sales'] = 'best-sellers/orders';
				$registerUrlRulesEvent->rules['best-sellers/dashboard'] = 'best-sellers/products';
			}
		);
	}
}
