<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class SummaryResult extends Model
{
	public ?GroupSummary $orders = null;

	public ?GroupSummary $discounts = null;

	public ?GroupSummary $customers = null;

	public ?GroupSummary $products = null;

	public ?GroupSummary $abandonment = null;
}
