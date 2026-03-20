<?php

namespace fostercommerce\bestsellers\events;

use yii\base\Event;

class RegisterKpiCardsEvent extends Event
{
	/**
	 * @var array<int, array{label: string, value: mixed, change: ?float, sparkline: ?array}>
	 */
	public array $cards = [];

	public string $page = '';
}
