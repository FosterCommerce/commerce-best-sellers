<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class CustomerRow extends Model
{
	/**
	 * @var string Customer email address
	 */
	public string $email = '';

	/**
	 * @var int|null Craft user ID
	 */
	public ?int $customerId = null;

	/**
	 * @var bool Whether the customer is a guest
	 */
	public bool $isGuest = true;

	/**
	 * @var string Customer status (guest or credentialed)
	 */
	public string $status = '';

	/**
	 * @var int Number of orders placed
	 */
	public int $orderCount = 0;

	/**
	 * @var float Total amount spent
	 */
	public float $totalSpent = 0;

	/**
	 * @var float Average order value
	 */
	public float $aov = 0;

	/**
	 * @var string|null Date of last order
	 */
	public ?string $lastOrder = null;
}
