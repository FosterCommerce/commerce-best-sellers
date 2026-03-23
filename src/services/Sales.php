<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\models\LineItem;
use craft\helpers\Db;
use DateTime;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\LineItemHelper;
use fostercommerce\bestsellers\records\VariantSale;
use yii\base\Component;

class Sales extends Component
{
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
			->map(function (LineItem $lineItem) use ($order): ?array {
				/** @var ?Variant $purchasable */
				$purchasable = LineItemHelper::getPurchasable($lineItem);

				if (! $purchasable instanceof Variant) {
					return null;
				}

				/** @var Product $product */
				$product = $purchasable->getOwner();

				return [
					'productId' => $product->id,
					'productTitle' => $product->title,
					'variantId' => $purchasable->id,
					'variantTitle' => $purchasable->title,
					'variantSku' => $purchasable->sku,
					'qty' => $lineItem->qty,
					'lineItemPrice' => $lineItem->price,
					'lineItemTotal' => $lineItem->subtotal,
					'discount' => abs((float) $lineItem->promotionalAmount),
					'orderId' => $order->id,
					'dateOrdered' => Db::prepareDateForDb($order->dateOrdered),
					'dateCreated' => Db::prepareDateForDb(new DateTime()),
				];
			})
			->filter()
			->toArray();

		if ($rows !== []) {
			Craft::$app->db
				->createCommand()
				->batchInsert(
					Table::VARIANT_SALES,
					[
						'productId',
						'productTitle',
						'variantId',
						'variantTitle',
						'variantSku',
						'qty',
						'lineItemPrice',
						'lineItemTotal',
						'discount',
						'orderId',
						'dateOrdered',
						'dateCreated',
					],
					$rows
				)
				->execute();
		}
	}
}
