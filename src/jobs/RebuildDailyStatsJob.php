<?php

namespace fostercommerce\bestsellers\jobs;

use Craft;
use craft\base\Batchable;
use craft\queue\BaseBatchedJob;
use fostercommerce\bestsellers\Plugin;
use Throwable;

class RebuildDailyStatsJob extends BaseBatchedJob
{
	/**
	 * @var string Start date (Y-m-d)
	 */
	public string $startDate = '';

	/**
	 * @var string End date (Y-m-d)
	 */
	public string $endDate = '';

	protected function loadData(): Batchable
	{
		return new DateRangeBatcher($this->startDate, $this->endDate);
	}

	protected function processItem(mixed $item): void
	{
		/** @var string $date */
		$date = $item;

		try {
			$plugin = Plugin::getInstance();
			$plugin->dailyStats->aggregateDay($date);
		} catch (Throwable $throwable) {
			Craft::warning("Failed to aggregate daily stats for {$date}: {$throwable->getMessage()}", 'best-sellers');
			Plugin::getInstance()->backfillLogs->log('daily-stats', $date, $throwable->getMessage());
		}
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t('best-sellers', 'Rebuilding daily stats');
	}
}
