<?php

namespace fostercommerce\bestsellers\helpers;

use craft\commerce\base\PurchasableInterface;
use craft\commerce\enums\LineItemType;
use craft\commerce\models\LineItem;

abstract class LineItemHelper
{
	public static function getPurchasable(LineItem $lineItem): ?PurchasableInterface
	{
		if ($lineItem->type === LineItemType::Custom) {
			return null;
		}

		return $lineItem->getPurchasable();
	}
}
