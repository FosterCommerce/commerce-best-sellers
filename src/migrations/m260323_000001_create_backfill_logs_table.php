<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;
use fostercommerce\bestsellers\db\Table;

class m260323_000001_create_backfill_logs_table extends Migration
{
	public function safeUp(): bool
	{
		if ($this->db->tableExists(Table::BACKFILL_LOGS)) {
			return true;
		}

		$this->createTable(Table::BACKFILL_LOGS, [
			'id' => $this->primaryKey(),
			'level' => $this->string()->notNull()->defaultValue('error'),
			'type' => $this->string()->notNull(),
			'reference' => $this->string()->notNull(),
			'message' => $this->text()->null(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createIndex(null, Table::BACKFILL_LOGS, ['level']);
		$this->createIndex(null, Table::BACKFILL_LOGS, ['type']);

		return true;
	}

	public function safeDown(): bool
	{
		echo "m260323_000001_create_backfill_logs_table cannot be reverted.\n";
		return false;
	}
}
