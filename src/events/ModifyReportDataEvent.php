<?php

namespace fostercommerce\bestsellers\events;

use yii\base\Event;

class ModifyReportDataEvent extends Event
{
	public string $page = '';

	/**
	 * @var array<string, mixed>
	 */
	public array $data = [];
}
