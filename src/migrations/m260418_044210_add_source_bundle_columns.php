<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use fostercommerce\bestsellers\records\VariantSale;

/**
 * m260418_044210_add_source_bundle_columns migration.
 */
class m260418_044210_add_source_bundle_columns extends Migration
{
	public function safeUp(): bool
	{
		$table = VariantSale::tableName();

		if (! $this->db->columnExists($table, 'sourceBundleId')) {
			$this->addColumn($table, 'sourceBundleId', $this->integer()->null()->after('discount'));
		}

		if (! $this->db->columnExists($table, 'sourceBundleTitle')) {
			$this->addColumn($table, 'sourceBundleTitle', $this->string()->null()->after('sourceBundleId'));
		}

		$this->createIndex(null, $table, ['sourceBundleId']);

		return true;
	}

	public function safeDown(): bool
	{
		echo "m260418_044210_add_source_bundle_columns cannot be reverted.\n";
		return false;
	}
}
