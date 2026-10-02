<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\base\FieldInterface;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\LineItemType;
use craft\commerce\models\LineItem;
use craft\helpers\Db;
use craft\helpers\Json;
use craft\helpers\MoneyHelper;
use DateTime;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\LineItemHelper;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;
use Money\Currencies\ISOCurrencies;
use Money\Currency as MoneyCurrency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;
use yii\base\Component;

class Sales extends Component
{
	/**
	 * Written by the EVENT_POPULATE_LINE_ITEM listener in Plugin and read by
	 * expandBundleLineItem so re-syncs and backfills do not drift if catalog
	 * prices change after the order completes.
	 */
	public const OPTIONS_KEY_BUNDLE_CHILD_PRICES = 'bestSellersBundleChildPrices';

	private const SNAPSHOT_KEY_UNIT_COSTS = 'bestSellersUnitCosts';

	private const BUNDLE_CLASS = 'webdna\\commerce\\bundles\\elements\\Bundle';

	public function logOrderSales(Order $order, bool $force = false): void
	{
		if ($force) {
			VariantSale::deleteAll([
				'orderId' => $order->id,
			]);
		} else {
			$alreadyProcessed = VariantSale::find()
				->where([
					'orderId' => $order->id,
				])
				->exists();

			if ($alreadyProcessed) {
				return;
			}
		}

		$lineItems = $order->getLineItems();

		$rows = collect($lineItems)
			->flatMap(function (LineItem $lineItem) use ($order): array {
				$purchasable = LineItemHelper::getPurchasable($lineItem);

				if (! $purchasable instanceof PurchasableInterface) {
					// Custom line items have no purchasable by design; skip.
					// For purchasable line items whose element has been deleted,
					// fall back to snapshot data so revenue still reports.
					if ($lineItem->type === LineItemType::Purchasable) {
						return $this->expandDeletedPurchasableLineItem($lineItem, $order);
					}

					return [];
				}

				if (is_a($purchasable, self::BUNDLE_CLASS)) {
					return $this->expandBundleLineItem($lineItem, $purchasable, $order);
				}

				if (! $purchasable instanceof Variant) {
					return [];
				}

				/** @var Product $product */
				$product = $purchasable->getOwner();

				return [[
					'productId' => $product->id,
					'productTitle' => $product->title,
					'productTypeId' => $product->typeId,
					'variantId' => $purchasable->id,
					'variantTitle' => $purchasable->title,
					'variantSku' => $purchasable->sku,
					'qty' => $lineItem->qty,
					'lineItemPrice' => $lineItem->price,
					'lineItemTotal' => $lineItem->subtotal,
					// Commerce freezes LineItem::price at line-item creation,
					// so no separate snapshot is needed for non-bundle rows.
					'catalogPrice' => $lineItem->price,
					'unitCost' => $this->getSnapshotUnitCost($lineItem, $purchasable->id),
					'discount' => abs((float) $lineItem->promotionalAmount),
					// LineItem::getDiscount() sums Discount-type adjustments on this
					// line (negative). Stored as-is so SUM(lineItemTotal + lineDiscount)
					// gives item sales net of coupons / manual discounts.
					'lineDiscount' => $lineItem->getDiscount(),
					'sourceBundleId' => null,
					'sourceBundleTitle' => null,
					'orderId' => $order->id,
					'dateOrdered' => Db::prepareDateForDb($order->dateOrdered),
					'dateCreated' => Db::prepareDateForDb(new DateTime()),
				]];
			})
			->toArray();

		if ($rows !== []) {
			Craft::$app->db
				->createCommand()
				->batchInsert(
					Table::VARIANT_SALES,
					[
						'productId',
						'productTitle',
						'productTypeId',
						'variantId',
						'variantTitle',
						'variantSku',
						'qty',
						'lineItemPrice',
						'lineItemTotal',
						'catalogPrice',
						'unitCost',
						'discount',
						'lineDiscount',
						'sourceBundleId',
						'sourceBundleTitle',
						'orderId',
						'dateOrdered',
						'dateCreated',
					],
					$rows
				)
				->execute();
		}
	}

	/**
	 * Record each variant's unit cost in the line item snapshot, so later cost field edits leave past orders unchanged.
	 *
	 * @param list<Variant> $variants
	 */
	public function captureUnitCosts(LineItem $lineItem, array $variants): void
	{
		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		if (! $plugin->variantFields->hasUnitCostField()) {
			return;
		}

		/** @var Order $order */
		$order = $lineItem->getOrder();

		$unitCosts = [];
		foreach ($variants as $variant) {
			$unitCosts[$variant->id] = $this->getVariantUnitCost($variant, $order->currency);
		}

		$lineItem->setSnapshot([
			...$lineItem->getSnapshot(),
			self::SNAPSHOT_KEY_UNIT_COSTS => $unitCosts,
		]);
	}

	/**
	 * Fill line items without a recorded unit cost from each variant's current cost, then rebuild the order's rows.
	 *
	 * @param list<int> $productTypeIds
	 */
	public function fillMissingUnitCosts(Order $order, array $productTypeIds): void
	{
		/** @var list<array{LineItem, array<string, mixed>}> $filledSnapshots */
		$filledSnapshots = [];
		foreach ($order->getLineItems() as $lineItem) {
			$snapshot = $lineItem->getSnapshot();
			/** @var array<int, string|null> $unitCosts */
			$unitCosts = $snapshot[self::SNAPSHOT_KEY_UNIT_COSTS] ?? [];

			$purchasable = LineItemHelper::getPurchasable($lineItem);
			if (! $purchasable instanceof PurchasableInterface) {
				$this->logDeletedPurchasableWithoutCost($order, $lineItem, $unitCosts, $productTypeIds);
				continue;
			}

			$this->logDeletedBundleChildrenWithoutCost($order, $lineItem, $purchasable, $unitCosts);

			$hasFilledLineCost = false;
			foreach ($this->getCostedVariants($purchasable) as $variant) {
				if (($unitCosts[$variant->id] ?? null) !== null) {
					continue;
				}

				if (! in_array($variant->getProduct()?->typeId, $productTypeIds, true)) {
					continue;
				}

				$unitCosts[$variant->id] = $this->getVariantUnitCost($variant, $order->currency);
				if ($unitCosts[$variant->id] === null) {
					continue;
				}

				$hasFilledLineCost = true;
			}

			if (! $hasFilledLineCost) {
				continue;
			}

			$snapshot[self::SNAPSHOT_KEY_UNIT_COSTS] = $unitCosts;
			$filledSnapshots[] = [$lineItem, $snapshot];
		}

		if ($filledSnapshots === []) {
			return;
		}

		// Commit the costs only with rebuilt rows, since a re-run skips lines that already have a cost
		Craft::$app->getDb()->transaction(function () use ($order, $filledSnapshots): void {
			foreach ($filledSnapshots as [$lineItem, $snapshot]) {
				// Write the column directly so the completed order is not resaved or recalculated
				Db::update(CommerceTable::LINEITEMS, [
					'snapshot' => Json::encode($snapshot),
				], [
					'id' => $lineItem->id,
				], [], false);
				$lineItem->setSnapshot($snapshot);
			}

			$this->logOrderSales($order, true);
		});
	}

	/**
	 * Expand a bundle line item into one variant_sales row per child. When all
	 * children resolve to a catalog price, Money::allocate distributes the
	 * bundle subtotal by (catalogPrice * childQty) weights. When some children
	 * are deleted, knowns store at pure catalog and unknowns split the
	 * leftover equally per-unit so SUM(lineItemTotal) still equals the bundle
	 * subtotal.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function expandBundleLineItem(LineItem $lineItem, PurchasableInterface $bundle, Order $order): array
	{
		if (! method_exists($bundle, 'getPurchasables')
			|| ! method_exists($bundle, 'getQtys')
			|| ! method_exists($bundle, 'getPurchasableIds')) {
			return [];
		}

		$currencyCode = strtoupper((string) $order->currency);
		if ($currencyCode === '') {
			return [];
		}

		$currencies = new ISOCurrencies();
		$currency = new MoneyCurrency($currencyCode);
		$subunit = $currencies->subunitFor($currency);
		$formatter = new DecimalMoneyFormatter($currencies);

		$bundleSubtotal = $this->floatToMoney((float) $lineItem->subtotal, $currency, $subunit);
		$linePromoDiscount = $this->floatToMoney(abs((float) $lineItem->promotionalAmount), $currency, $subunit);
		$lineAdjustmentDiscount = $this->floatToMoney($lineItem->getDiscount(), $currency, $subunit);

		/** @var array<int, int|string> $qtys */
		$qtys = $bundle->getQtys();
		/** @var array<int, int> $allChildIds */
		$allChildIds = array_map('intval', $bundle->getPurchasableIds());

		/** @var array<int, Variant> $liveById */
		$liveById = [];
		foreach ($bundle->getPurchasables() as $livePurchasable) {
			if ($livePurchasable instanceof Variant) {
				$liveById[$livePurchasable->id] = $livePurchasable;
			}
		}

		/** @var array<int, float> $frozenChildPrices */
		$frozenChildPrices = [];
		$options = $lineItem->getOptions();
		if (isset($options[self::OPTIONS_KEY_BUNDLE_CHILD_PRICES])
			&& is_array($options[self::OPTIONS_KEY_BUNDLE_CHILD_PRICES])) {
			foreach ($options[self::OPTIONS_KEY_BUNDLE_CHILD_PRICES] as $childId => $price) {
				if (is_numeric($childId) && is_numeric($price)) {
					$frozenChildPrices[(int) $childId] = (float) $price;
				}
			}
		}

		/** @var list<array{variantId: int, variant: ?Variant, childQty: int, catalogPrice: float}> $known */
		$known = [];
		/** @var list<array{variantId: int, childQty: int}> $unknown */
		$unknown = [];

		foreach ($allChildIds as $allChildId) {
			$childQty = (int) ($qtys[$allChildId] ?? 1);
			if ($childQty <= 0) {
				continue;
			}

			$liveVariant = $liveById[$allChildId] ?? null;
			$frozenPrice = $frozenChildPrices[$allChildId] ?? null;

			if ($frozenPrice !== null) {
				$known[] = [
					'variantId' => $allChildId,
					'variant' => $liveVariant,
					'childQty' => $childQty,
					'catalogPrice' => max(0.0, $frozenPrice),
				];
				continue;
			}

			if ($liveVariant !== null) {
				$known[] = [
					'variantId' => $allChildId,
					'variant' => $liveVariant,
					'childQty' => $childQty,
					'catalogPrice' => max(0.0, (float) $liveVariant->price),
				];
				continue;
			}

			$unknown[] = [
				'variantId' => $allChildId,
				'childQty' => $childQty,
			];
		}

		if ($known === [] && $unknown === []) {
			return [];
		}

		$lineQty = $lineItem->qty;
		$dateOrdered = Db::prepareDateForDb($order->dateOrdered);
		$dateCreated = Db::prepareDateForDb(new DateTime());
		$zero = new Money(0, $currency);

		/** @var array<int, Money> $rowSubtotals */
		$rowSubtotals = [];
		/** @var array<int, array{type: string, payload: array<string, mixed>}> $rowMeta */
		$rowMeta = [];

		if ($unknown === []) {
			$weights = array_map(
				static fn (array $entry): float => $entry['catalogPrice'] * $entry['childQty'],
				$known,
			);

			if (array_sum($weights) <= 0.0) {
				// Fall back to qty weights so the bundle subtotal distributes
				// evenly instead of allocating everything to a single row.
				$weights = array_map(
					static fn (array $entry): float => (float) $entry['childQty'],
					$known,
				);
				if (array_sum($weights) <= 0.0) {
					return [];
				}
			}

			$subtotalParts = $bundleSubtotal->allocate($weights);
			foreach ($known as $index => $entry) {
				$rowSubtotals[$index] = $subtotalParts[$index];
				$rowMeta[$index] = [
					'type' => 'known',
					'payload' => $entry,
				];
			}
		} else {
			$knownTotalMoney = new Money(0, $currency);
			$knownTotals = [];
			foreach ($known as $index => $entry) {
				$unitMoney = $this->floatToMoney($entry['catalogPrice'], $currency, $subunit);
				$rowMoney = $unitMoney->multiply((string) ($entry['childQty'] * $lineQty));
				$knownTotals[$index] = $rowMoney;
				$knownTotalMoney = $knownTotalMoney->add($rowMoney);
			}

			$remainder = $bundleSubtotal->subtract($knownTotalMoney);

			foreach ($known as $index => $entry) {
				$rowSubtotals[$index] = $knownTotals[$index];
				$rowMeta[$index] = [
					'type' => 'known',
					'payload' => $entry,
				];
			}

			$unknownUnits = 0;
			foreach ($unknown as $entry) {
				$unknownUnits += $entry['childQty'] * $lineQty;
			}

			if ($unknownUnits > 0) {
				$unknownWeights = array_map(
					static fn (array $entry): float => (float) $entry['childQty'],
					$unknown,
				);
				$unknownParts = $remainder->allocate($unknownWeights);
				foreach ($unknown as $unknownIndex => $entry) {
					$compositeIndex = count($known) + $unknownIndex;
					$rowSubtotals[$compositeIndex] = $unknownParts[$unknownIndex];
					$rowMeta[$compositeIndex] = [
						'type' => 'unknown',
						'payload' => $entry,
					];
				}
			}
		}

		// Distribute promo + adjustment discounts using the same weights as the
		// row subtotals so children see proportional discount slices.
		$discountWeights = [];
		foreach ($rowSubtotals as $index => $money) {
			$discountWeights[$index] = max(0.0, (float) $money->getAmount());
		}

		if (array_sum($discountWeights) <= 0.0) {
			$discountWeights = array_fill_keys(array_keys($rowSubtotals), 1.0);
		}

		// Money::allocate ignores keys; convert to a positional list, allocate,
		// then re-key back to match $rowSubtotals.
		$positionalKeys = array_keys($discountWeights);
		$positionalWeights = array_values($discountWeights);
		if ($positionalWeights === []) {
			return [];
		}

		$promoParts = $linePromoDiscount->allocate($positionalWeights);
		$adjustmentParts = $lineAdjustmentDiscount->allocate($positionalWeights);

		$promoByIndex = [];
		$adjustmentByIndex = [];
		foreach ($positionalKeys as $positionalIndex => $rowIndex) {
			$promoByIndex[$rowIndex] = $promoParts[$positionalIndex] ?? $zero;
			$adjustmentByIndex[$rowIndex] = $adjustmentParts[$positionalIndex] ?? $zero;
		}

		$rows = [];
		ksort($rowSubtotals);
		foreach ($rowSubtotals as $index => $rowTotal) {
			$meta = $rowMeta[$index];

			if ($meta['type'] === 'known') {
				/** @var array{variantId: int, variant: ?Variant, childQty: int, catalogPrice: float} $entry */
				$entry = $meta['payload'];
				$rowQty = $lineQty * $entry['childQty'];
				$rowPrice = $rowQty > 0 ? $rowTotal->divide((string) $rowQty) : $zero;
				$variant = $entry['variant'];

				if ($variant instanceof Variant) {
					/** @var Product $product */
					$product = $variant->getOwner();
					$productId = $product->id;
					$productTitle = $product->title;
					$productTypeId = $product->typeId;
					$variantTitle = $variant->title;
					$variantSku = $variant->sku;
				} else {
					$productId = null;
					$productTitle = null;
					$productTypeId = null;
					$variantTitle = null;
					$variantSku = null;
				}

				$rows[] = [
					'productId' => $productId,
					'productTitle' => $productTitle,
					'productTypeId' => $productTypeId,
					'variantId' => $entry['variantId'],
					'variantTitle' => $variantTitle,
					'variantSku' => $variantSku,
					'qty' => $rowQty,
					'lineItemPrice' => $formatter->format($rowPrice),
					'lineItemTotal' => $formatter->format($rowTotal),
					'catalogPrice' => $formatter->format($this->floatToMoney($entry['catalogPrice'], $currency, $subunit)),
					'unitCost' => $this->getSnapshotUnitCost($lineItem, $entry['variantId']),
					'discount' => $formatter->format($promoByIndex[$index] ?? $zero),
					'lineDiscount' => $formatter->format($adjustmentByIndex[$index] ?? $zero),
					'sourceBundleId' => $bundle->id,
					'sourceBundleTitle' => $bundle->title ?? '',
					'orderId' => $order->id,
					'dateOrdered' => $dateOrdered,
					'dateCreated' => $dateCreated,
				];
				continue;
			}

			/** @var array{variantId: int, childQty: int} $entry */
			$entry = $meta['payload'];
			$rowQty = $lineQty * $entry['childQty'];
			$rowPrice = $rowQty > 0 ? $rowTotal->divide((string) $rowQty) : $zero;
			$rowPriceFormatted = $formatter->format($rowPrice);

			$rows[] = [
				'productId' => null,
				'productTitle' => null,
				'productTypeId' => null,
				'variantId' => $entry['variantId'],
				'variantTitle' => null,
				'variantSku' => null,
				'qty' => $rowQty,
				'lineItemPrice' => $rowPriceFormatted,
				'lineItemTotal' => $formatter->format($rowTotal),
				// catalog cannot be recovered for a deleted child; mirror the
				// allocated per-unit price so SUM(catalogPrice * qty) ties out
				// against the order subtotal exactly when unknowns absorb the
				// full remainder.
				'catalogPrice' => $rowPriceFormatted,
				'unitCost' => $this->getSnapshotUnitCost($lineItem, $entry['variantId']),
				'discount' => $formatter->format($promoByIndex[$index] ?? $zero),
				'lineDiscount' => $formatter->format($adjustmentByIndex[$index] ?? $zero),
				'sourceBundleId' => $bundle->id,
				'sourceBundleTitle' => $bundle->title ?? '',
				'orderId' => $order->id,
				'dateOrdered' => $dateOrdered,
				'dateCreated' => $dateCreated,
			];
		}

		return $rows;
	}

	private function floatToMoney(float $amount, MoneyCurrency $currency, int $subunit): Money
	{
		return new Money((int) round($amount * (10 ** $subunit)), $currency);
	}

	/**
	 * Build a variant_sales row from the line item's frozen snapshot when the
	 * purchasable element has been deleted. Preserves revenue and identifiers
	 * that would otherwise be lost.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function expandDeletedPurchasableLineItem(LineItem $lineItem, Order $order): array
	{
		/** @var array<string, mixed> $snapshot */
		$snapshot = $lineItem->snapshot ?? [];

		if ($snapshot === []) {
			return [];
		}

		/** @var array<string, mixed> $productSnapshot */
		$productSnapshot = is_array($snapshot['product'] ?? null) ? $snapshot['product'] : [];

		$productId = is_numeric($snapshot['productId'] ?? null) ? (int) $snapshot['productId'] : null;
		$productTypeId = is_numeric($productSnapshot['typeId'] ?? null) ? (int) $productSnapshot['typeId'] : null;
		$productTitle = is_string($productSnapshot['title'] ?? null) ? $productSnapshot['title'] : null;

		$variantId = is_numeric($snapshot['id'] ?? null) ? (int) $snapshot['id'] : null;
		$variantTitle = is_string($snapshot['description'] ?? null)
			? $snapshot['description']
			: (is_string($snapshot['title'] ?? null) ? $snapshot['title'] : null);
		$variantSku = is_string($snapshot['sku'] ?? null) ? $snapshot['sku'] : null;

		return [[
			'productId' => $productId,
			'productTitle' => $productTitle,
			'productTypeId' => $productTypeId,
			'variantId' => $variantId,
			'variantTitle' => $variantTitle,
			'variantSku' => $variantSku,
			'qty' => $lineItem->qty,
			'lineItemPrice' => $lineItem->price,
			'lineItemTotal' => $lineItem->subtotal,
			'catalogPrice' => $lineItem->price,
			'unitCost' => $this->getSnapshotUnitCost($lineItem, $variantId),
			'discount' => abs((float) $lineItem->promotionalAmount),
			'lineDiscount' => $lineItem->getDiscount(),
			'sourceBundleId' => null,
			'sourceBundleTitle' => null,
			'orderId' => $order->id,
			'dateOrdered' => Db::prepareDateForDb($order->dateOrdered),
			'dateCreated' => Db::prepareDateForDb(new DateTime()),
		]];
	}

	private function getSnapshotUnitCost(LineItem $lineItem, ?int $variantId): ?string
	{
		if ($variantId === null) {
			return null;
		}

		/** @var array<int, string|null> $unitCosts */
		$unitCosts = $lineItem->getSnapshot()[self::SNAPSHOT_KEY_UNIT_COSTS] ?? [];

		return $unitCosts[$variantId] ?? null;
	}

	/**
	 * Get the variant's unit cost as a decimal string, or null without a cost in the order's currency.
	 */
	private function getVariantUnitCost(Variant $variant, ?string $orderCurrencyCode): ?string
	{
		$product = $variant->getProduct();
		if (! $product instanceof Product) {
			return null;
		}

		/** @var Plugin $plugin */
		$plugin = Plugin::getInstance();
		$unitCostField = $plugin->variantFields->getUnitCostField($product->getType());
		if (! $unitCostField instanceof FieldInterface) {
			return null;
		}

		$unitCost = $variant->getFieldValue((string) $unitCostField->handle);
		if (! $unitCost instanceof Money || $unitCost->getCurrency()->getCode() !== $orderCurrencyCode) {
			return null;
		}

		return (string) MoneyHelper::toDecimal($unitCost);
	}

	/**
	 * @return list<Variant>
	 */
	private function getCostedVariants(PurchasableInterface $purchasable): array
	{
		if ($purchasable instanceof Variant) {
			return [$purchasable];
		}

		if (! is_a($purchasable::class, self::BUNDLE_CLASS, true) || ! method_exists($purchasable, 'getPurchasables')) {
			return [];
		}

		$childVariants = [];
		foreach ($purchasable->getPurchasables() as $childPurchasable) {
			if ($childPurchasable instanceof Variant) {
				$childVariants[] = $childPurchasable;
			}
		}

		return $childVariants;
	}

	/**
	 * @param array<int, string|null> $unitCosts
	 * @param list<int> $productTypeIds
	 */
	private function logDeletedPurchasableWithoutCost(Order $order, LineItem $lineItem, array $unitCosts, array $productTypeIds): void
	{
		// Custom line items never have a purchasable, so they never need a cost
		if ($lineItem->type !== LineItemType::Purchasable) {
			return;
		}

		$snapshot = $lineItem->getSnapshot();

		// Only variant snapshots hold the product, so a snapshot without one is a bundle's
		if (! isset($snapshot['product'])) {
			if (in_array(null, $unitCosts, true) || $unitCosts === []) {
				Craft::error("Unit cost fill left order #{$order->id} line item #{$lineItem->id} without a cost: its bundle no longer exists.", 'best-sellers');
			}

			return;
		}

		// The purchasable ID is cleared when the variant is deleted, so read it from the snapshot
		$variantId = is_numeric($snapshot['id'] ?? null) ? (int) $snapshot['id'] : 0;
		if (($unitCosts[$variantId] ?? null) !== null) {
			return;
		}

		/** @var array{typeId?: int|string} $productSnapshot */
		$productSnapshot = $snapshot['product'];
		if (! in_array((int) ($productSnapshot['typeId'] ?? 0), $productTypeIds, true)) {
			return;
		}

		Craft::error("Unit cost fill left order #{$order->id} line item #{$lineItem->id} without a cost: its variant #{$variantId} no longer exists.", 'best-sellers');
	}

	/**
	 * @param array<int, string|null> $unitCosts
	 */
	private function logDeletedBundleChildrenWithoutCost(Order $order, LineItem $lineItem, PurchasableInterface $purchasable, array $unitCosts): void
	{
		if (! is_a($purchasable::class, self::BUNDLE_CLASS, true) || ! method_exists($purchasable, 'getPurchasableIds')) {
			return;
		}

		$liveChildIds = array_map(static fn (Variant $variant): int => (int) $variant->id, $this->getCostedVariants($purchasable));
		foreach (array_map('intval', $purchasable->getPurchasableIds()) as $childId) {
			if (! in_array($childId, $liveChildIds, true) && ($unitCosts[$childId] ?? null) === null) {
				Craft::error("Unit cost fill left order #{$order->id} line item #{$lineItem->id} without a cost for variant #{$childId}: the bundle's variant no longer exists.", 'best-sellers');
			}
		}
	}
}
