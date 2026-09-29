<?php

namespace fostercommerce\bestsellers\jobs;

use Craft;
use craft\commerce\elements\Order;
use fostercommerce\bestsellers\Plugin;
use Throwable;

class FillUnitCostsJob extends BackfillOrdersJob
{
	/**
	 * @var list<int>
	 */
	public array $productTypeIds = [];

	protected function processOrder(Order $order): void
	{
		Plugin::getInstance()->sales->fillMissingUnitCosts($order, $this->productTypeIds);
	}

	protected function logFailure(Order $order, Throwable $throwable): void
	{
		Craft::error("Unit cost fill rolled back order #{$order->id}, so its line items keep their missing costs: {$throwable->getMessage()}", 'best-sellers');
	}

	protected function defaultDescription(): string
	{
		return Craft::t('best-sellers', 'jobs.fillUnitCosts', [
			'offset' => $this->offset,
		]);
	}
}
