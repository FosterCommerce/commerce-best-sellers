<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\elements\Product;
use craft\commerce\elements\Variant;
use craft\commerce\enums\LineItemType;
use craft\commerce\models\LineItem;
use craft\helpers\Db;
use fostercommerce\bestsellers\records\VariantSale;
use yii\base\Component;

/**
 * Sales service
 */
class Sales extends Component
{
	public function logOrderSales(Order $order): void
	{
		// Check if the order has already been processed.
		$alreadyProcessed = VariantSale::find()
			->where(['orderId' => $order->id])
			->exists();

		if ($alreadyProcessed) {
			return;
		}

		$lineItems = $order->getLineItems();

		$rows = collect($lineItems)
			->filter(static fn (LineItem $lineItem): bool => $lineItem->type !== LineItemType::Custom)
			->map(function (LineItem $lineItem) use ($order): array {
				/** @var Variant $purchasable */
				$purchasable = $lineItem->getPurchasable();
				/** @var Product $product */
				$product = $purchasable->getOwner();

				return [
					'productId'     => $product->id,
					'productTitle'  => $product->title,
					'variantId'     => $purchasable->id,
					'variantTitle'  => $purchasable->title,
					'variantSku'    => $purchasable->sku,
					'qty'           => $lineItem->qty,
					'orderId'       => $order->id,
					'dateOrdered'   => Db::prepareDateForDb($order->dateOrdered),
					'dateCreated'   => Db::prepareDateForDb(new \DateTime()),
				];
			})
			->toArray();

		if ($rows !== []) {
			Craft::$app->db
				->createCommand()
				->batchInsert(
					VariantSale::tableName(),
					[
						'productId',
						'productTitle',
						'variantId',
						'variantTitle',
						'variantSku',
						'qty',
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
