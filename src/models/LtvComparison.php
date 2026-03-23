<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class LtvComparison extends Model
{
	/**
	 * @var LtvSegment|null Credentialed customer segment
	 */
	public ?LtvSegment $credentialed = null;

	/**
	 * @var LtvSegment|null Guest customer segment
	 */
	public ?LtvSegment $guest = null;
}
