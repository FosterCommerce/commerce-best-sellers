<?php

namespace fostercommerce\bestsellers\migrations;

use craft\db\Migration;

/**
 * m250224_101741_create_sales_table migration.
 */
class m250224_101741_install extends Install
{
	public function safeUp(): bool
	{
		return parent::safeUp();
	}

	public function safeDown(): bool
	{
		echo "m250224_101741_install cannot be reverted.\n";
		return false;
	}
}
