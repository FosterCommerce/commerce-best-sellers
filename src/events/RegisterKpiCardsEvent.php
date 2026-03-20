<?php

namespace fostercommerce\bestsellers\events;

use yii\base\Event;

class RegisterKpiCardsEvent extends Event
{
	/**
	 * @var list<array{label: string, value: mixed, change: ?float, sparkline: ?array<string, mixed>}>
	 */
	public array $cards = [];

	public string $page = '';
}
