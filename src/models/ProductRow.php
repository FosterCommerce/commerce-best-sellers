<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class ProductRow extends Model
{
	/**
	 * @var int Product ID
	 */
	public int $productId = 0;

	/**
	 * @var string Product title
	 */
	public string $productTitle = '';

	/**
	 * @var int Total units sold
	 */
	public int $unitsSold = 0;

	/**
	 * @var int Number of orders containing this product
	 */
	public int $orderCount = 0;

	/**
	 * @var float Total revenue
	 */
	public float $revenue = 0;

	/**
	 * @var float Average unit price
	 */
	public float $avgPrice = 0;

	/**
	 * @var string Product type name
	 */
	public string $productType = '';

	/**
	 * @var int|null Variant ID
	 */
	public ?int $variantId = null;

	/**
	 * @var string|null Variant title
	 */
	public ?string $variantTitle = null;

	/**
	 * @var string|null Variant SKU
	 */
	public ?string $variantSku = null;

	/**
	 * @var bool True when any units rolled up into this row were sold as part of a bundle.
	 */
	public bool $fromBundle = false;
}
