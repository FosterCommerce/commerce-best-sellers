<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use craft\db\Query;
use fostercommerce\bestsellers\records\VariantSale;

class m260320_000001_backfill_revenue_columns extends Migration
{
	public function safeUp(): bool
	{
		// Backfill lineItemPrice, lineItemTotal, and discount from commerce_lineitems.
		// lineItemPrice = unit price
		// lineItemTotal = subtotal (price * qty, before tax)
		// discount = promotionalAmount (line-level promotional discount)
		$rows = (new Query())
			->select([
				'[[variantSales.id]]',
				'[[lineItems.price]]',
				'[[lineItems.subtotal]]',
				'[[lineItems.promotionalAmount]]',
			])
			->from(['variantSales' => VariantSale::tableName()])
			->innerJoin(
				['lineItems' => '{{%commerce_lineitems}}'],
				'[[lineItems.orderId]] = [[variantSales.orderId]] AND [[lineItems.purchasableId]] = [[variantSales.variantId]]'
			)
			->where(['[[variantSales.lineItemTotal]]' => null])
			->all();

		foreach ($rows as $row) {
			$this->update(
				VariantSale::tableName(),
				[
					'lineItemPrice' => (float) ($row['price'] ?? 0),
					'lineItemTotal' => (float) ($row['subtotal'] ?? 0),
					'discount' => abs((float) ($row['promotionalAmount'] ?? 0)),
				],
				['id' => $row['id']]
			);
		}

		return true;
	}

	public function safeDown(): bool
	{
		$this->update(
			VariantSale::tableName(),
			[
				'lineItemPrice' => null,
				'lineItemTotal' => null,
				'discount' => 0,
			]
		);

		return true;
	}
}
