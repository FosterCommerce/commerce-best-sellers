<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

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
}
