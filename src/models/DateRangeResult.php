<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;
use craft\helpers\Db;
use RuntimeException;

class DateRangeResult extends Model
{
	/**
	 * @var string Start date (Y-m-d)
	 */
	public string $from = '';

	/**
	 * @var string End date (Y-m-d)
	 */
	public string $to = '';

	/**
	 * @var string Start datetime for SQL (Y-m-d H:i:s)
	 */
	public string $fromDT = '';

	/**
	 * @var string End datetime for SQL (Y-m-d H:i:s)
	 */
	public string $toDT = '';

	/**
	 * @var string Preset handle
	 */
	public string $preset = '';

	/**
	 * @var self|null Previous period for comparison
	 */
	public ?self $prev = null;

	/**
	 * Get the previous period, asserting it exists.
	 */
	public function getPrev(): self
	{
		assert($this->prev instanceof self, 'Previous period not set');
		return $this->prev;
	}

	/**
	 * Build a TZ-correct date range condition for a column.
	 *
	 * fromDT and toDT are app-timezone wall-clock strings. Db::parseDateParam
	 * interprets them in the system timezone and emits UTC literals matching
	 * how Craft stores DATETIME columns. Use this in any WHERE clause that
	 * compares a stored datetime column against this date range, both element
	 * queries (which already route through parseDateParam internally) and raw
	 * Query objects (which do not).
	 *
	 * @return array<mixed>
	 */
	public function dateCondition(string $column): array
	{
		if ($this->fromDT === '' || $this->toDT === '') {
			throw new RuntimeException('DateRangeResult fromDT/toDT must be set before calling dateCondition()');
		}

		$condition = Db::parseDateParam($column, ['and', '>= ' . $this->fromDT, '<= ' . $this->toDT]);
		if ($condition === null) {
			throw new RuntimeException('Db::parseDateParam returned null despite non-empty fromDT/toDT');
		}

		return $condition;
	}

	/**
	 * @return array<array-key, mixed>
	 */
	protected function defineRules(): array
	{
		$rules = parent::defineRules();
		$rules[] = [['from', 'to'], 'required'];
		$rules[] = [['from', 'to'],
			'date',
			'format' => 'php:Y-m-d'];
		$rules[] = [['preset'], 'string'];

		return $rules;
	}
}
