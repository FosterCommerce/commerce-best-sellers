<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use fostercommerce\bestsellers\records\VariantSale;

class m260319_000001_add_revenue_columns extends Migration
{
	public function safeUp(): bool
	{
		$table = VariantSale::tableName();

		if (! $this->db->columnExists($table, 'lineItemPrice')) {
			$this->addColumn($table, 'lineItemPrice', $this->decimal(14, 4)->null()->after('qty'));
		}

		if (! $this->db->columnExists($table, 'lineItemTotal')) {
			$this->addColumn($table, 'lineItemTotal', $this->decimal(14, 4)->null()->after('lineItemPrice'));
		}

		if (! $this->db->columnExists($table, 'discount')) {
			$this->addColumn($table, 'discount', $this->decimal(14, 4)->defaultValue(0)->after('lineItemTotal'));
		}

		return true;
	}

	public function safeDown(): bool
	{
		echo "m260319_000001_add_revenue_columns cannot be reverted.\n";
		return false;
	}
}
