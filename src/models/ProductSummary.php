<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class ProductSummary extends Model
{
	/**
	 * @var int Number of unique products sold
	 */
	public int $uniqueProducts = 0;

	/**
	 * @var string Title of the best-selling product
	 */
	public string $bestSeller = '';

	/**
	 * @var int Units sold for the best-selling product
	 */
	public int $bestSellerUnits = 0;

	/**
	 * @var float Total product revenue
	 */
	public float $totalProductRevenue = 0;
}
