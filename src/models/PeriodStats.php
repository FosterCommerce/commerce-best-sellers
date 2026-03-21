<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class PeriodStats extends Model
{
	/**
	 * @var int Total number of orders
	 */
	public int $totalOrders = 0;

	/**
	 * @var float Total revenue
	 */
	public float $totalRevenue = 0;

	/**
	 * @var float Total discount amount
	 */
	public float $totalDiscount = 0;

	/**
	 * @var float Total shipping cost
	 */
	public float $totalShipping = 0;

	/**
	 * @var float Total tax
	 */
	public float $totalTax = 0;

	/**
	 * @var int Total items sold
	 */
	public int $totalItemsSold = 0;

	/**
	 * @var int Unique customer count
	 */
	public int $uniqueCustomers = 0;

	/**
	 * @var int New customer count
	 */
	public int $newCustomers = 0;

	/**
	 * @var int Returning customer count
	 */
	public int $returningCustomers = 0;

	/**
	 * @var float Average order value
	 */
	public float $averageOrderValue = 0;

	/**
	 * @var float Average items per order
	 */
	public float $averageItemsPerOrder = 0;
}
