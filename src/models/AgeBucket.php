<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class AgeBucket extends Model
{
	/**
	 * @var int Total abandoned carts in this bucket
	 */
	public int $count = 0;

	/**
	 * @var float Total value of abandoned carts
	 */
	public float $value = 0;

	/**
	 * @var int Abandoned carts with customer info
	 */
	public int $withCustomer = 0;

	/**
	 * @var float Value of abandoned carts with customer email
	 */
	public float $valueWithEmail = 0;
}
