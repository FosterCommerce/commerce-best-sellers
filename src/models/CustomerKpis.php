<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class CustomerKpis extends Model
{
	/**
	 * @var int Total unique customers
	 */
	public int $total = 0;

	/**
	 * @var int New customers in the period
	 */
	public int $new = 0;

	/**
	 * @var int Returning customers in the period
	 */
	public int $returning = 0;

	/**
	 * @var float Repeat customer rate as a percentage
	 */
	public float $repeatRate = 0;
}
