<?php

namespace fostercommerce\bestsellers\behaviors;

use yii\base\Behavior;

/**
 * @extends Behavior<\craft\base\Component>
 */
class SalesBehavior extends Behavior
{
	public ?int $totalQtySold = null;

	public ?float $totalRevenue = null;
}
