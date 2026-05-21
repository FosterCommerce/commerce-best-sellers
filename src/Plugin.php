<?php

namespace fostercommerce\bestsellers;

use Craft;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\db\ProductQuery;
use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\events\LineItemEvent;
use craft\commerce\services\LineItems;
use craft\elements\db\ElementQuery;
use craft\events\CancelableEvent;
use craft\events\DefineBehaviorsEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\services\Gc;
use craft\services\UserPermissions;
use craft\services\Utilities;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use fostercommerce\bestsellers\behaviors\SaleQueryBehavior;
use fostercommerce\bestsellers\behaviors\SalesBehavior;
use fostercommerce\bestsellers\helpers\Query;
use fostercommerce\bestsellers\models\Settings;
use fostercommerce\bestsellers\services\BackfillLogs;
use fostercommerce\bestsellers\services\CartAbandonment;
use fostercommerce\bestsellers\services\CustomerStats;
use fostercommerce\bestsellers\services\DailyStats;
use fostercommerce\bestsellers\services\DateRange;
use fostercommerce\bestsellers\services\OperationsStats;
use fostercommerce\bestsellers\services\ProductStats;
use fostercommerce\bestsellers\services\Sales;
use fostercommerce\bestsellers\services\SummaryEngine;
use fostercommerce\bestsellers\utilities\BackfillUtility;
use fostercommerce\bestsellers\variables\BestSellersVariable;
use yii\base\Event;

/**
 * @method static Plugin getInstance()
 * @method Settings getSettings()
 *
 * @property-read Settings $settings
 * @property-read Sales $sales
 * @property-read DailyStats $dailyStats
 * @property-read DateRange $dateRange
 * @property-read ProductStats $productStats
 * @property-read CustomerStats $customerStats
 * @property-read OperationsStats $operationsStats
 * @property-read CartAbandonment $cartAbandonment
 * @property-read SummaryEngine $summaryEngine
 * @property-read BackfillLogs $backfillLogs
 */
class Plugin extends BasePlugin
{
	public const PERMISSION_VIEW_REPORTS = 'best-sellers:viewReports';

	public const PERMISSION_BACKFILL = 'best-sellers:backfill';

	public const PERMISSION_MANAGE_SETTINGS = 'best-sellers:manageSettings';

	public string $schemaVersion = '1.7.0';

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
				'cartAbandonment' => CartAbandonment::class,
				'summaryEngine' => SummaryEngine::class,
				'backfillLogs' => BackfillLogs::class,
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
						self::PERMISSION_MANAGE_SETTINGS => [
							'label' => Craft::t('best-sellers', 'Manage plugin settings'),
						],
					],
				];
			}
		);

		Event::on(
			Gc::class,
			Gc::EVENT_RUN,
			function (): void {
				$this->backfillLogs->prune();
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

		$subnav = [
			'overview' => [
				'label' => Craft::t('best-sellers', 'Dashboard'),
				'url' => 'best-sellers/',
			],
			'orders' => [
				'label' => Craft::t('best-sellers', 'Orders'),
				'url' => 'best-sellers/orders',
			],
			'transactions' => [
				'label' => Craft::t('best-sellers', 'Transactions'),
				'url' => 'best-sellers/transactions',
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

		if (Craft::$app->getUser()->checkPermission(self::PERMISSION_MANAGE_SETTINGS)) {
			$subnav['settings'] = [
				'label' => Craft::t('best-sellers', 'Settings'),
				'url' => 'best-sellers/settings',
			];
		}

		$navItem['subnav'] = $subnav;
		return $navItem;
	}

	public function getSales(): Sales
	{
		/** @var Sales */
		return $this->get('sales');
	}

	public function getDailyStats(): DailyStats
	{
		/** @var DailyStats */
		return $this->get('dailyStats');
	}

	public function getDateRange(): DateRange
	{
		/** @var DateRange */
		return $this->get('dateRange');
	}

	public function getProductStats(): ProductStats
	{
		/** @var ProductStats */
		return $this->get('productStats');
	}

	public function getCustomerStats(): CustomerStats
	{
		/** @var CustomerStats */
		return $this->get('customerStats');
	}

	public function getOperationsStats(): OperationsStats
	{
		/** @var OperationsStats */
		return $this->get('operationsStats');
	}

	public function getCartAbandonment(): CartAbandonment
	{
		/** @var CartAbandonment */
		return $this->get('cartAbandonment');
	}

	public function getSummaryEngine(): SummaryEngine
	{
		/** @var SummaryEngine */
		return $this->get('summaryEngine');
	}

	public function getBackfillLogs(): BackfillLogs
	{
		/** @var BackfillLogs */
		return $this->get('backfillLogs');
	}

	protected function createSettingsModel(): ?Model
	{
		return Craft::createObject(Settings::class);
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

		// Listen to AFTER_SAVE rather than AFTER_COMPLETE_ORDER so that
		// post-completion edits (line item changes, manual discounts, status
		// updates, etc.) re-sync the order's variant_sales rows and the day's
		// daily_stats. AFTER_COMPLETE_ORDER fires only on the initial markAsComplete()
		// transition; AFTER_SAVE catches both that and every subsequent save.
		// force=true makes logOrderSales delete + re-insert the order's rows,
		// and aggregateDay is an idempotent upsert.
		Event::on(
			Order::class,
			Order::EVENT_AFTER_SAVE,
			function (Event $event): void {
				/** @var Order $order */
				$order = $event->sender;

				if (! $order->isCompleted) {
					return;
				}

				$this->sales->logOrderSales($order, true);

				if ($order->dateOrdered) {
					$this->dailyStats->aggregateDay($order->dateOrdered->format('Y-m-d'));
				}
			}
		);

		// Sales::expandBundleLineItem reads this snapshot on every (re)sync so
		// catalog drift between order completion and a later AFTER_SAVE rebuild
		// cannot shift historical allocations. String instanceof keeps the
		// listener a no-op when webdna/commerce-bundles is not installed.
		Event::on(
			LineItems::class,
			LineItems::EVENT_POPULATE_LINE_ITEM,
			static function (LineItemEvent $event): void {
				$lineItem = $event->lineItem;
				$purchasable = $lineItem->getPurchasable();

				if (! $purchasable instanceof PurchasableInterface || ! is_a($purchasable, 'webdna\\commerce\\bundles\\elements\\Bundle')) {
					return;
				}

				if (! method_exists($purchasable, 'getPurchasables')) {
					return;
				}

				// Dynamic method name keeps phpstan from resolving against the unknown
				// Bundle class. Runtime safety is the is_a + method_exists guards above.
				$getChildrenMethod = 'getPurchasables';
				/** @var iterable<mixed> $childPurchasables */
				$childPurchasables = $purchasable->{$getChildrenMethod}();

				$childPrices = [];
				foreach ($childPurchasables as $childPurchasable) {
					if ($childPurchasable instanceof Variant) {
						$childPrices[$childPurchasable->id] = (float) $childPurchasable->price;
					}
				}

				if ($childPrices === []) {
					return;
				}

				$options = $lineItem->getOptions();
				$options[Sales::OPTIONS_KEY_BUNDLE_CHILD_PRICES] = $childPrices;
				$lineItem->setOptions($options);
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
				$registerUrlRulesEvent->rules['best-sellers/transactions'] = 'best-sellers/transactions';
				$registerUrlRulesEvent->rules['best-sellers/transactions/transactions-data'] = 'best-sellers/transactions/transactions-data';
				$registerUrlRulesEvent->rules['best-sellers/transactions/export-csv'] = 'best-sellers/transactions/export-csv';
				$registerUrlRulesEvent->rules['best-sellers/products'] = 'best-sellers/products';
				$registerUrlRulesEvent->rules['best-sellers/products/orders'] = 'best-sellers/products/orders';
				$registerUrlRulesEvent->rules['best-sellers/products/products-data'] = 'best-sellers/products/products-data';
				$registerUrlRulesEvent->rules['best-sellers/products/product-orders-data'] = 'best-sellers/products/product-orders-data';
				$registerUrlRulesEvent->rules['best-sellers/products/export-csv'] = 'best-sellers/products/export-csv';
				$registerUrlRulesEvent->rules['best-sellers/customers'] = 'best-sellers/customers';
				$registerUrlRulesEvent->rules['best-sellers/customers/customers-data'] = 'best-sellers/customers/customers-data';
				$registerUrlRulesEvent->rules['best-sellers/customers/export-csv'] = 'best-sellers/customers/export-csv';
				$registerUrlRulesEvent->rules['best-sellers/operations'] = 'best-sellers/operations';
				$registerUrlRulesEvent->rules['best-sellers/operations/clear-logs'] = 'best-sellers/operations/clear-logs';
				$registerUrlRulesEvent->rules['best-sellers/settings'] = 'best-sellers/settings/index';
				$registerUrlRulesEvent->rules['best-sellers/settings/save'] = 'best-sellers/settings/save';

				// Backward compatibility redirects
				$registerUrlRulesEvent->rules['best-sellers/reports'] = 'best-sellers/orders';
				$registerUrlRulesEvent->rules['best-sellers/sales'] = 'best-sellers/orders';
				$registerUrlRulesEvent->rules['best-sellers/dashboard'] = 'best-sellers/products';
			}
		);
	}
}
