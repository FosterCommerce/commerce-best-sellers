<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use craft\helpers\Db;
use fostercommerce\bestsellers\records\VariantSale;

/**
 * m260418_061514_drop_purchasable_foreign_keys migration.
 *
 * Drops the CASCADE foreign keys on productId and variantId so variant_sales
 * rows survive product/variant deletion. This lets the plugin retain
 * historical revenue when a purchasable is removed from the catalog and
 * allows the deleted-purchasable snapshot fallback to insert rows with
 * dead-FK identifiers.
 */
class m260418_061514_drop_purchasable_foreign_keys extends Migration
{
	public function safeUp(): bool
	{
		$table = VariantSale::tableName();

		Db::dropForeignKeyIfExists($table, ['productId'], $this->db);
		Db::dropForeignKeyIfExists($table, ['variantId'], $this->db);

		return true;
	}

	public function safeDown(): bool
	{
		echo "m260418_061514_drop_purchasable_foreign_keys cannot be reverted.\n";
		return false;
	}
}
