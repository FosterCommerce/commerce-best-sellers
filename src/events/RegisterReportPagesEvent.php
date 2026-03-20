<?php

namespace fostercommerce\bestsellers\events;

use yii\base\Event;

class RegisterReportPagesEvent extends Event
{
	/**
	 * @var array<string, array{label: string, url: string}>
	 */
	public array $pages = [];
}
