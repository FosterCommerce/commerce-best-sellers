<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;
use JsonSerializable;

class AbandonmentStats extends Model implements JsonSerializable
{
	/**
	 * @var int Total abandoned carts
	 */
	public int $totalAbandoned = 0;

	/**
	 * @var int Total completed orders
	 */
	public int $totalCompleted = 0;

	/**
	 * @var float Abandonment rate as a percentage
	 */
	public float $abandonmentRate = 0;

	/**
	 * @var float Total value of abandoned carts
	 */
	public float $abandonedValue = 0;

	/**
	 * @var float Abandoned value from carts with customer email
	 */
	public float $abandonedValueWithEmail = 0;

	/**
	 * @var float Total value of completed orders
	 */
	public float $completedValue = 0;

	/**
	 * @var int Abandoned carts with customer info
	 */
	public int $withCustomer = 0;

	/**
	 * @var int Abandoned carts without customer info
	 */
	public int $withoutCustomer = 0;

	/**
	 * @var array<string, AgeBucket> Abandonment breakdown by age bucket
	 */
	public array $byAge = [];

	/**
	 * @return array<string, mixed>
	 */
	public function jsonSerialize(): array
	{
		return [
			'totalAbandoned' => $this->totalAbandoned,
			'totalCompleted' => $this->totalCompleted,
			'abandonmentRate' => $this->abandonmentRate,
			'abandonedValue' => $this->abandonedValue,
			'abandonedValueWithEmail' => $this->abandonedValueWithEmail,
			'completedValue' => $this->completedValue,
			'withCustomer' => $this->withCustomer,
			'withoutCustomer' => $this->withoutCustomer,
			'byAge' => $this->byAge,
		];
	}
}
