<?php

namespace fostercommerce\bestsellers\behaviors;

use yii\base\Behavior;

class SalesBehavior extends Behavior
{
	public ?int $totalQtySold = null;
}
