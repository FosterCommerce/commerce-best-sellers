<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use fostercommerce\bestsellers\records\DailyStat;

class m260319_000002_create_daily_stats_table extends Migration
{
	public function safeUp(): bool
	{
		if (! $this->db->tableExists(DailyStat::tableName())) {
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

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(DailyStat::tableName());
		return true;
	}
}
