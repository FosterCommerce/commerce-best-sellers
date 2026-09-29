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
	 * @var array<string, string> Unit cost field instance UID by product type UID
	 */
	public array $unitCostFields = [];

	/**
	 * @var array<string, list<string>> Filter field instance UIDs by product type UID
	 */
	public array $filterFields = [];

	/**
	 * @var list<string> Order field layout element UIDs shown and filterable on the Orders report
	 */
	public array $orderFields = [];

	public ?string $shippedOrderStatusHandle = null;

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
			[['unitCostFields', 'filterFields', 'orderFields'], 'safe'],
			[['shippedOrderStatusHandle'], 'string'],
		];
	}
}
