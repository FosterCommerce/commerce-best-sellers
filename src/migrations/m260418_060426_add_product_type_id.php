<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use craft\db\Query;
use fostercommerce\bestsellers\records\VariantSale;

/**
 * m260418_060426_add_product_type_id migration.
 */
class m260418_060426_add_product_type_id extends Migration
{
	public function safeUp(): bool
	{
		$table = VariantSale::tableName();

		if (! $this->db->columnExists($table, 'productTypeId')) {
			$this->addColumn($table, 'productTypeId', $this->integer()->null()->after('productTitle'));
		}

		// Backfill productTypeId for existing rows from live commerce_products.
		$rows = (new Query())
			->select([
				'[[variantSales.id]]',
				'[[products.typeId]]',
			])
			->from([
				'variantSales' => $table,
			])
			->innerJoin(
				[
					'products' => '{{%commerce_products}}',
				],
				'[[products.id]] = [[variantSales.productId]]'
			)
			->where([
				'[[variantSales.productTypeId]]' => null,
			])
			->all();

		foreach ($rows as $row) {
			/** @var array{id: int, typeId: int|string|null} $row */
			$this->update(
				$table,
				[
					'productTypeId' => $row['typeId'] !== null ? (int) $row['typeId'] : null,
				],
				[
					'id' => $row['id'],
				]
			);
		}

		$this->createIndex(null, $table, ['productTypeId']);

		return true;
	}

	public function safeDown(): bool
	{
		echo "m260418_060426_add_product_type_id cannot be reverted.\n";
		return false;
	}
}
