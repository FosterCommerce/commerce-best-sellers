<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use fostercommerce\bestsellers\records\VariantSale;

/**
 * m250327_180455_add_dateOrdered_column migration.
 */
class m250327_180455_add_dateOrdered_column extends Migration
{
	public function safeUp(): bool
	{
		$table = VariantSale::tableName();

		// Add the dateOrdered column if it doesn't exist.
		if (! $this->db->columnExists($table, 'dateOrdered')) {
			$this->addColumn($table, 'dateOrdered', $this->dateTime()->null()->after('orderId'));

			// Get all orders from commerce_orders with their dateOrdered values.
			$orders = (new \yii\db\Query())
				->select(['id', 'dateOrdered'])
				->from('{{%commerce_orders}}')
				->all();

			// Loop through and update any rows in our sales table that have a matching orderId.
			foreach ($orders as $order) {
				$this->update(
					$table,
					[
						'dateOrdered' => $order['dateOrdered'],
					],
					[
						'orderId' => $order['id'],
					]
				);
			}
		}

		return true;
	}

	public function safeDown(): bool
	{
		$table = '{{%best_sellers_variant_sales}}';
		if ($this->db->columnExists($table, 'dateOrdered')) {
			$this->dropColumn($table, 'dateOrdered');
		}

		return false;
	}
}
