<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

/**
 * Report scope containing a date range and optional order status filter.
 */
class ReportScope extends Model
{
	/**
	 * @var DateRangeResult The date range for this scope
	 */
	public DateRangeResult $dateRange;

	/**
	 * @var string Start date (Y-m-d), delegated from dateRange
	 */
	public string $from = '';

	/**
	 * @var string End date (Y-m-d), delegated from dateRange
	 */
	public string $to = '';

	/**
	 * @var string Start datetime for SQL (Y-m-d H:i:s), delegated from dateRange
	 */
	public string $fromDT = '';

	/**
	 * @var string End datetime for SQL (Y-m-d H:i:s), delegated from dateRange
	 */
	public string $toDT = '';

	/**
	 * @var string Preset handle, delegated from dateRange
	 */
	public string $preset = '';

	/**
	 * @var list<int> Order status IDs to include. Empty means all statuses.
	 */
	public array $orderStatusIds = [];

	public function init(): void
	{
		parent::init();

		if (isset($this->dateRange)) {
			$this->from = $this->dateRange->from;
			$this->to = $this->dateRange->to;
			$this->fromDT = $this->dateRange->fromDT;
			$this->toDT = $this->dateRange->toDT;
			$this->preset = $this->dateRange->preset;
		}
	}

	/**
	 * Get the previous period date range.
	 */
	public function getPrev(): DateRangeResult
	{
		return $this->dateRange->getPrev();
	}

	/**
	 * Whether a status filter is active.
	 */
	public function hasStatusFilter(): bool
	{
		return $this->orderStatusIds !== [];
	}

	/**
	 * Build a SQL condition array for filtering by order status.
	 *
	 * Returns null if no filter is active.
	 *
	 * @return array<mixed>|null
	 */
	public function statusCondition(string $tableAlias = ''): ?array
	{
		if (! $this->hasStatusFilter()) {
			return null;
		}

		$col = $tableAlias !== '' ? "[[{$tableAlias}.orderStatusId]]" : '[[orderStatusId]]';

		return [
			$col => $this->orderStatusIds,
		];
	}

	/**
	 * Create a new scope with different dates but the same status filter.
	 *
	 * Used by the SummaryEngine for YoY and trailing average comparisons.
	 */
	public function forDates(string $from, string $to): self
	{
		$dateRange = new DateRangeResult([
			'from' => $from,
			'to' => $to,
			'fromDT' => $from . ' 00:00:00',
			'toDT' => $to . ' 23:59:59',
		]);

		return new self([
			'dateRange' => $dateRange,
			'orderStatusIds' => $this->orderStatusIds,
		]);
	}
}
