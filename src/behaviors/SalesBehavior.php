<?php

namespace fostercommerce\bestsellers\behaviors;

use yii\base\Behavior;

/**
 * @extends Behavior<\craft\base\Component>
 */
class SalesBehavior extends Behavior
{
	public ?int $totalQtySold = null;

	/**
	 * Gross item sales (sum of lineItemTotal). Does NOT subtract line-level
	 * Discount adjustments.
	 *
	 * @deprecated since 1.6.0. Prefer $totalItemSalesNet, which matches the
	 * "Item Sales (Net)" column in the CP Products report.
	 */
	public ?float $totalRevenue = null;

	/**
	 * Item sales net of line-level Discount adjustments
	 * (SUM(lineItemTotal + lineDiscount)). Matches the "Item Sales (Net)"
	 * column in the CP Products report.
	 *
	 * @since 1.6.0
	 */
	public ?float $totalItemSalesNet = null;
}
