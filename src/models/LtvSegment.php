<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;
use JsonSerializable;

class LtvSegment extends Model implements JsonSerializable
{
	/**
	 * @var int Number of customers in this segment
	 */
	public int $count = 0;

	/**
	 * @var float Total revenue from this segment
	 */
	public float $totalRevenue = 0;

	/**
	 * @var float Average lifetime value
	 */
	public float $avgLtv = 0;

	/**
	 * @var float Average number of orders per customer
	 */
	public float $avgOrders = 0;

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array
	{
		return [
			'count' => $this->count,
			'totalRevenue' => $this->totalRevenue,
			'avgLtv' => $this->avgLtv,
			'avgOrders' => $this->avgOrders,
		];
	}
}
