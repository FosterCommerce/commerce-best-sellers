<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;
use JsonSerializable;

class AgeBucket extends Model implements JsonSerializable
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

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array
	{
		return [
			'count' => $this->count,
			'value' => $this->value,
			'withCustomer' => $this->withCustomer,
			'valueWithEmail' => $this->valueWithEmail,
		];
	}
}
