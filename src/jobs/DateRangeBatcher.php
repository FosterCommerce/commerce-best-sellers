<?php

namespace fostercommerce\bestsellers\jobs;

use craft\base\Batchable;
use craft\helpers\DateTimeHelper;
use DateTime;

class DateRangeBatcher implements Batchable
{
	public function __construct(
		private readonly string $startDate,
		private readonly string $endDate
	) {
	}

	public function count(): int
	{
		$start = DateTimeHelper::toDateTime($this->startDate);
		$end = DateTimeHelper::toDateTime($this->endDate);
		if (! $start instanceof DateTime || ! $end instanceof DateTime) {
			return 0;
		}

		$interval = $start->diff($end);

		return max(0, $interval->days + 1);
	}

	/**
	 * @return iterable<string>
	 */
	public function getSlice(int $offset, int $limit): iterable
	{
		$current = DateTimeHelper::toDateTime($this->startDate);
		$end = DateTimeHelper::toDateTime($this->endDate);
		if (! $current instanceof DateTime || ! $end instanceof DateTime) {
			return [];
		}

		$current->modify("+{$offset} days");

		$dates = [];
		for ($index = 0; $index < $limit && $current <= $end; $index++) {
			$dates[] = $current->format('Y-m-d');
			$current->modify('+1 day');
		}

		return $dates;
	}
}
