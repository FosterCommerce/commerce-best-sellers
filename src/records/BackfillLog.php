<?php

namespace fostercommerce\bestsellers\records;

use craft\db\ActiveRecord;
use fostercommerce\bestsellers\db\Table;

/**
 * @property int $id
 * @property string $level
 * @property string $type
 * @property string $reference
 * @property string $message
 * @property string $dateCreated
 * @property string $dateUpdated
 * @property string $uid
 */
class BackfillLog extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::BACKFILL_LOGS;
	}
}
