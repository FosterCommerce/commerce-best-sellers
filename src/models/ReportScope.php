<?php

namespace fostercommerce\bestsellers\models;

/**
 * Extends DateRangeResult with order status filtering for report-wide scoping.
 */
class ReportScope extends DateRangeResult
{
	/**
	 * @var list<int> Order status IDs to include. Empty means all statuses.
	 */
	public array $orderStatusIds = [];

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
		return new self([
			'from' => $from,
			'to' => $to,
			'fromDT' => $from . ' 00:00:00',
			'toDT' => $to . ' 23:59:59',
			'orderStatusIds' => $this->orderStatusIds,
		]);
	}
}
