<?php

namespace fostercommerce\bestsellers\migrations;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\DateTimeHelper;
use fostercommerce\bestsellers\helpers\NotTrashed;
use fostercommerce\bestsellers\jobs\RebuildDailyStatsJob;
use fostercommerce\bestsellers\records\DailyStat;

/**
 * Rebuilds best_sellers_daily_stats after the timezone-handling fix.
 *
 * Pre-fix, DailyStats::aggregateDay scanned UTC-shifted day boundaries while
 * labelling each row with an app-timezone calendar date. Existing rows
 * therefore contain numbers that do not match their labels.
 *
 * The rebuild is queued as a batched job (one day per batch item). aggregateDay
 * is an idempotent upsert, so old rows stay visible (wrong-TZ but populated)
 * until the queue overwrites them day by day. No zero-state window.
 */
class m260520_001907_rebuild_daily_stats_for_tz_fix extends Migration
{
	public function safeUp(): bool
	{
		$table = DailyStat::tableName();

		if (! $this->db->tableExists($table)) {
			return true;
		}

		$rangeQuery = (new Query())
			->select([
				'minDate' => 'MIN([[orders.dateOrdered]])',
				'maxDate' => 'MAX([[orders.dateOrdered]])',
			])
			->from([
				'orders' => CommerceTable::ORDERS,
			])
			->where(['=', '[[orders.isCompleted]]', true]);

		/** @var array{minDate: ?string, maxDate: ?string}|false $row */
		$row = NotTrashed::join($rangeQuery, 'orders')->one();

		if (! $row || $row['minDate'] === null || $row['maxDate'] === null) {
			return true;
		}

		$minDate = DateTimeHelper::toDateTime($row['minDate']);
		$maxDate = DateTimeHelper::toDateTime($row['maxDate']);

		if ($minDate === false || $maxDate === false) {
			return true;
		}

		Craft::$app->queue->push(new RebuildDailyStatsJob([
			'startDate' => $minDate->format('Y-m-d'),
			'endDate' => $maxDate->format('Y-m-d'),
		]));

		return true;
	}
}
