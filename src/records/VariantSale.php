<?php

namespace fostercommerce\bestsellers\records;

use craft\db\ActiveRecord;
use fostercommerce\bestsellers\db\Table;

/**
 * @property int $id
 * @property int $productId
 * @property string $productTitle
 * @property int $variantId
 * @property string $variantTitle
 * @property string $variantSku
 * @property int $qty
 * @property float|null $lineItemPrice
 * @property float|null $lineItemTotal
 * @property float $discount
 * @property int $orderId
 * @property string $dateOrdered
 * @property string $dateCreated
 */
class VariantSale extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::VARIANT_SALES;
	}
}
