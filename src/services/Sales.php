<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\commerce\base\PurchasableInterface;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\LineItemType;
use craft\commerce\models\LineItem;
use craft\helpers\Db;
use DateTime;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\LineItemHelper;
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
				$rowMoney = $this->floatToMoney(
					$entry['catalogPrice'] * $entry['childQty'] * $lineQty,
					$currency,
					$subunit,
				);
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
			'discount' => abs((float) $lineItem->promotionalAmount),
			'lineDiscount' => $lineItem->getDiscount(),
			'sourceBundleId' => null,
			'sourceBundleTitle' => null,
			'orderId' => $order->id,
			'dateOrdered' => Db::prepareDateForDb($order->dateOrdered),
			'dateCreated' => Db::prepareDateForDb(new DateTime()),
		]];
	}
}
