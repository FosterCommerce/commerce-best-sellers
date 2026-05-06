<?php

namespace fostercommerce\bestsellers\models;

use craft\base\Model;

class Settings extends Model
{
	/**
	 * @var list<string>
	 */
	public array $defaultOrderStatusHandles = [];

	/**
	 * @return array<int, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			...parent::defineRules(),
			[
				['defaultOrderStatusHandles'],
				'each',
				'rule' => ['string'],
			],
		];
	}
}
