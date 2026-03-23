<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class LtvSegment extends Model
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
}
