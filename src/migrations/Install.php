<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use fostercommerce\bestsellers\records\VariantSale;

class Install extends Migration
{
	public function safeUp(): bool
	{
		if (! $this->db->tableExists(VariantSale::tableName())) {
			$this->createTable(VariantSale::tableName(), [
				'id' => $this->primaryKey(),
				'productId' => $this->integer(),
				'productTitle' => $this->string(),
				'variantId' => $this->integer(),
				'variantTitle' => $this->string(),
				'variantSku' => $this->string(),
				'qty' => $this->integer()->notNull(),
				'orderId' => $this->integer(),
				'dateOrdered' => $this->dateTime()->notNull(),
				'dateCreated' => $this->dateTime()->notNull(),
			]);

			$this->addForeignKey(null, VariantSale::tableName(), ['productId'], '{{%commerce_products}}', ['id'], 'CASCADE', null);
			$this->addForeignKey(null, VariantSale::tableName(), ['variantId'], '{{%commerce_variants}}', ['id'], 'CASCADE', null);
			$this->addForeignKey(null, VariantSale::tableName(), ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE', null);

			$this->createIndex(null, VariantSale::tableName(), ['productId', 'dateCreated']);
			$this->createIndex(null, VariantSale::tableName(), ['variantId', 'dateCreated']);
		}

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(VariantSale::tableName());
		return true;
	}
}
