<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use fostercommerce\bestsellers\records\VariantSale;

class m260928_185834_add_unit_cost_to_variant_sales extends Migration
{
	public function safeUp(): bool
	{
		$table = VariantSale::tableName();

		if (! $this->db->columnExists($table, 'unitCost')) {
			$this->addColumn($table, 'unitCost', $this->decimal(14, 4)->null()->after('catalogPrice'));
		}

		return true;
	}
}
