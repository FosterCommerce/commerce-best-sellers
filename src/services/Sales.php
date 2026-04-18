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
use yii\base\Component;

class Sales extends Component
{
	private const BUNDLE_CLASS = 'webdna\\commerce\\bundles\\elements\\Bundle';

	public function logOrderSales(Order $order): void
	{
		// Check if the order has already been processed.
		$alreadyProcessed = VariantSale::find()
			->where([
				'orderId' => $order->id,
			])
			->exists();

		if ($alreadyProcessed) {
			return;
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
					'discount' => abs((float) $lineItem->promotionalAmount),
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
						'discount',
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
	 * @return list<array<string, mixed>>
	 */
	private function expandBundleLineItem(LineItem $lineItem, PurchasableInterface $bundle, Order $order): array
	{
		// getPurchasables() and getQtys() are defined on the webdna Bundle element,
		// not PurchasableInterface. We reach it only after is_a(BUNDLE_CLASS) passes.
		/** @phpstan-ignore-next-line method.notFound */
		$childPurchasables = $bundle->getPurchasables();
		/** @phpstan-ignore-next-line method.notFound */
		$qtys = $bundle->getQtys();

		$components = [];
		foreach ($childPurchasables as $childPurchasable) {
			if (! $childPurchasable instanceof Variant) {
				continue;
			}

			$childQty = (int) ($qtys[$childPurchasable->id] ?? 1);
			if ($childQty <= 0) {
				continue;
			}

			$components[] = [
				'variant' => $childPurchasable,
				'childQty' => $childQty,
				'weight' => max(0.0, (float) $childPurchasable->price) * $childQty,
			];
		}

		if ($components === []) {
			return [];
		}

		$totalWeight = array_sum(array_column($components, 'weight'));
		$totalUnits = array_sum(array_column($components, 'childQty'));
		$lineSubtotal = (float) $lineItem->subtotal;
		$lineDiscount = abs((float) $lineItem->promotionalAmount);
		$lineQty = $lineItem->qty;
		$dateOrdered = Db::prepareDateForDb($order->dateOrdered);
		$dateCreated = Db::prepareDateForDb(new DateTime());

		$rows = [];
		$allocatedSubtotal = 0.0;
		$allocatedDiscount = 0.0;
		$lastIndex = count($components) - 1;

		foreach ($components as $index => $component) {
			/** @var Variant $variant */
			$variant = $component['variant'];
			$rowQty = $lineQty * $component['childQty'];

			$share = $totalWeight > 0.0 ? $component['weight'] / $totalWeight : $component['childQty'] / $totalUnits;

			if ($index === $lastIndex) {
				$rowTotal = round($lineSubtotal - $allocatedSubtotal, 4);
				$rowDiscount = round($lineDiscount - $allocatedDiscount, 4);
			} else {
				$rowTotal = round($lineSubtotal * $share, 4);
				$rowDiscount = round($lineDiscount * $share, 4);
				$allocatedSubtotal += $rowTotal;
				$allocatedDiscount += $rowDiscount;
			}

			$rowPrice = $rowQty > 0 ? round($rowTotal / $rowQty, 4) : 0.0;

			/** @var Product $product */
			$product = $variant->getOwner();

			$rows[] = [
				'productId' => $product->id,
				'productTitle' => $product->title,
				'productTypeId' => $product->typeId,
				'variantId' => $variant->id,
				'variantTitle' => $variant->title,
				'variantSku' => $variant->sku,
				'qty' => $rowQty,
				'lineItemPrice' => $rowPrice,
				'lineItemTotal' => $rowTotal,
				'discount' => $rowDiscount,
				'sourceBundleId' => $bundle->id,
				'sourceBundleTitle' => $bundle->title ?? '',
				'orderId' => $order->id,
				'dateOrdered' => $dateOrdered,
				'dateCreated' => $dateCreated,
			];
		}

		return $rows;
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
			'discount' => abs((float) $lineItem->promotionalAmount),
			'sourceBundleId' => null,
			'sourceBundleTitle' => null,
			'orderId' => $order->id,
			'dateOrdered' => Db::prepareDateForDb($order->dateOrdered),
			'dateCreated' => Db::prepareDateForDb(new DateTime()),
		]];
	}
}
