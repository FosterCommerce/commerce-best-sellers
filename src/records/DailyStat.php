<?php

namespace fostercommerce\bestsellers\records;

use craft\db\ActiveRecord;
use fostercommerce\bestsellers\db\Table;

/**
 * @property int $id
 * @property string $date
 * @property int $totalOrders
 * @property float $totalRevenue
 * @property float $totalDiscount
 * @property float $totalShipping
 * @property float $totalTax
 * @property int $totalItemsSold
 * @property int $uniqueCustomers
 * @property int $newCustomers
 * @property int $returningCustomers
 * @property float $averageOrderValue
 * @property float $averageItemsPerOrder
 */
class DailyStat extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::DAILY_STATS;
	}
}
