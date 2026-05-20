<?php

namespace fostercommerce\bestsellers\migrations;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Migration;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\records\VariantSale;

/**
 * Adds a `lineDiscount` column to best_sellers_variant_sales to store the
 * Discount-adjuster output attributed to each line. Lets the Products report
 * surface "Item Sales (Net)": quantity x sale price minus line-attributed
 * coupon and manual discounts.
 *
 * variant_sales rows are rebuilt order-by-order via batched BackfillOrdersJob
 * with force=true, which deletes existing rows per order before re-inserting.
 * No global truncate, so partially-rebuilt state still serves data: orders
 * not yet processed show lineDiscount=0 (column default) until the queue
 * reaches them.
 */
class m260520_001908_add_line_discount_to_variant_sales extends Migration
{
	private const BATCH_SIZE = 25;

	public function safeUp(): bool
	{
		$table = VariantSale::tableName();

		if (! $this->db->tableExists($table)) {
			return true;
		}

		if (! $this->db->columnExists($table, 'lineDiscount')) {
			$this->addColumn($table, 'lineDiscount', $this->decimal(14, 4)->notNull()->defaultValue(0)->after('discount'));
		}

		$totalOrders = (int) Order::find()->isCompleted(true)->count();
		if ($totalOrders === 0) {
			return true;
		}

		for ($offset = 0; $offset < $totalOrders; $offset += self::BATCH_SIZE) {
			Craft::$app->queue->push(new BackfillOrdersJob([
				'offset' => $offset,
				'limit' => self::BATCH_SIZE,
				'force' => true,
			]));
		}

		return true;
	}
}
