<?php

namespace fostercommerce\bestsellers\migrations;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Migration;
use DateTime;
use fostercommerce\bestsellers\controllers\BackfillController;
use fostercommerce\bestsellers\jobs\BackfillOrdersJob;
use fostercommerce\bestsellers\records\VariantSale;

/**
 * Adds `catalogPrice` to best_sellers_variant_sales so the Avg Price report
 * can read the true per-unit catalog without bundle allocation drift. Bundle
 * children freeze the child variant's price (from lineItem.options when the
 * EVENT_POPULATE_LINE_ITEM listener captured it, else the live price during
 * backfill). Non-bundle rows mirror LineItem::price, which Commerce already
 * freezes at line item creation.
 *
 * Existing rows stay NULL until the queued BackfillOrdersJob (force=true)
 * deletes + reinserts every order's rows via the updated logOrderSales.
 */
class m260521_120000_add_catalog_price_to_variant_sales extends Migration
{
	public function safeUp(): bool
	{
		$table = VariantSale::tableName();

		if (! $this->db->columnExists($table, 'catalogPrice')) {
			$this->addColumn(
				$table,
				'catalogPrice',
				$this->decimal(14, 4)->null()->after('lineItemTotal'),
			);
		}

		$totalOrders = (int) Order::find()
			->isCompleted(true)
			->andWhere(['<', 'dateOrdered', (new DateTime())->format('Y-m-d H:i:s')])
			->count();

		for ($offset = 0; $offset < $totalOrders; $offset += BackfillController::BATCH_SIZE) {
			Craft::$app->queue->push(new BackfillOrdersJob([
				'offset' => $offset,
				'limit' => BackfillController::BATCH_SIZE,
				'force' => true,
			]));
		}

		return true;
	}
}
