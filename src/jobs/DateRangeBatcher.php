<?php

namespace fostercommerce\bestsellers\jobs;

use craft\base\Batchable;
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
		$start = new DateTime($this->startDate);
		$end = new DateTime($this->endDate);
		$interval = $start->diff($end);

		return max(0, $interval->days + 1);
	}

	/**
	 * @return iterable<string>
	 */
	public function getSlice(int $offset, int $limit): iterable
	{
		$current = new DateTime($this->startDate);
		$current->modify("+{$offset} days");

		$end = new DateTime($this->endDate);
		$dates = [];

		for ($i = 0; $i < $limit && $current <= $end; $i++) {
			$dates[] = $current->format('Y-m-d');
			$current->modify('+1 day');
		}

		return $dates;
	}
}
