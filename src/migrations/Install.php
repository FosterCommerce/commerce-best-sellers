<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use fostercommerce\bestsellers\records\BackfillLog;
use fostercommerce\bestsellers\records\DailyStat;
use fostercommerce\bestsellers\records\VariantSale;

class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->createVariantSalesTable();
		$this->createDailyStatsTable();
		$this->createBackfillLogsTable();

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(BackfillLog::tableName());
		$this->dropTableIfExists(DailyStat::tableName());
		$this->dropTableIfExists(VariantSale::tableName());
		return true;
	}

	private function createVariantSalesTable(): void
	{
		if ($this->db->tableExists(VariantSale::tableName())) {
			return;
		}

		$this->createTable(VariantSale::tableName(), [
			'id' => $this->primaryKey(),
			'productId' => $this->integer(),
			'productTitle' => $this->string(),
			'productTypeId' => $this->integer()->null(),
			'variantId' => $this->integer(),
			'variantTitle' => $this->string(),
			'variantSku' => $this->string(),
			'qty' => $this->integer()->notNull(),
			'lineItemPrice' => $this->decimal(14, 4)->null(),
			'lineItemTotal' => $this->decimal(14, 4)->null(),
			'discount' => $this->decimal(14, 4)->defaultValue(0),
			'sourceBundleId' => $this->integer()->null(),
			'sourceBundleTitle' => $this->string()->null(),
			'orderId' => $this->integer(),
			'dateOrdered' => $this->dateTime()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
		]);

		// productId and variantId are not FK-constrained: variant_sales rows
		// must survive purchasable deletion so historical revenue is retained
		// and the deleted-purchasable snapshot fallback can insert dead-FK IDs.
		$this->addForeignKey(null, VariantSale::tableName(), ['orderId'], '{{%commerce_orders}}', ['id'], 'CASCADE', null);

		$this->createIndex(null, VariantSale::tableName(), ['productId', 'dateCreated']);
		$this->createIndex(null, VariantSale::tableName(), ['variantId', 'dateCreated']);
		$this->createIndex(null, VariantSale::tableName(), ['productTypeId']);
		$this->createIndex(null, VariantSale::tableName(), ['sourceBundleId']);
		$this->createIndex(null, VariantSale::tableName(), ['orderId']);
		$this->createIndex(null, VariantSale::tableName(), ['dateOrdered']);
	}

	private function createDailyStatsTable(): void
	{
		if ($this->db->tableExists(DailyStat::tableName())) {
			return;
		}

		$this->createTable(DailyStat::tableName(), [
			'id' => $this->primaryKey(),
			'date' => $this->date()->notNull(),
			'totalOrders' => $this->integer()->notNull()->defaultValue(0),
			'totalRevenue' => $this->decimal(14, 4)->notNull()->defaultValue(0),
			'totalDiscount' => $this->decimal(14, 4)->notNull()->defaultValue(0),
			'totalShipping' => $this->decimal(14, 4)->notNull()->defaultValue(0),
			'totalTax' => $this->decimal(14, 4)->notNull()->defaultValue(0),
			'totalItemsSold' => $this->integer()->notNull()->defaultValue(0),
			'uniqueCustomers' => $this->integer()->notNull()->defaultValue(0),
			'newCustomers' => $this->integer()->notNull()->defaultValue(0),
			'returningCustomers' => $this->integer()->notNull()->defaultValue(0),
			'averageOrderValue' => $this->decimal(14, 4)->notNull()->defaultValue(0),
			'averageItemsPerOrder' => $this->decimal(8, 2)->notNull()->defaultValue(0),
		]);

		$this->createIndex(null, DailyStat::tableName(), ['date'], true);
	}

	private function createBackfillLogsTable(): void
	{
		if ($this->db->tableExists(BackfillLog::tableName())) {
			return;
		}

		$this->createTable(BackfillLog::tableName(), [
			'id' => $this->primaryKey(),
			'level' => $this->string()->notNull()->defaultValue('error'),
			'type' => $this->string()->notNull(),
			'reference' => $this->string()->notNull(),
			'message' => $this->text()->null(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, BackfillLog::tableName(), ['level']);
		$this->createIndex(null, BackfillLog::tableName(), ['type']);
	}
}
