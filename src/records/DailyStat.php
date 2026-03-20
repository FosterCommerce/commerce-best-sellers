<?php

namespace fostercommerce\bestsellers\records;

use craft\db\ActiveRecord;

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
		return '{{%best_sellers_daily_stats}}';
	}
}
