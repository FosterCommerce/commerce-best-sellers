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
	 * @var float Item subtotal: SUM(lineItemTotal). Gross of line-level Discount
	 * adjustments. Matches the Orders report's "Item Subtotal" column when
	 * summed across the same orders.
	 */
	public float $itemSubtotal = 0;

	/**
	 * @var float Item sales net of line-level Discount adjustments:
	 * SUM(lineItemTotal + lineDiscount). The "Item Sales (Net)" column.
	 */
	public float $revenue = 0;

	/**
	 * @var float Average unit price
	 */
	public float $avgPrice = 0;

	/**
	 * @var float|null Cost of the line items, set only in the Profit view
	 */
	public ?float $cost = null;

	/**
	 * @var float|null Item Sales (Net) minus cost
	 */
	public ?float $grossProfit = null;

	/**
	 * @var float|null Gross profit as a ratio of Item Sales (Net), e.g. 0.42 for 42%
	 */
	public ?float $grossMargin = null;

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

	/**
	 * @var bool True when at least one of the orders contributing to this row
	 * is partially paid (0 < totalPaid < totalPrice). Fully-unpaid orders are
	 * excluded upstream. Live; reflects current payment state, not the state
	 * at order completion.
	 */
	public bool $hasUnpaidOrder = false;
}
