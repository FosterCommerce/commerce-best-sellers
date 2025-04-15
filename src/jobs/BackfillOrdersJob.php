<?php

namespace fostercommerce\bestsellers\jobs;

use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;

class BackfillOrdersJob extends BaseJob
{
	public int $offset = 0;

	public int $limit = 25;

	// Optional date range properties, as strings (e.g. '2023-01-01')
	public ?string $startDate = null;

	public ?string $endDate = null;

	public function execute($queue): void
	{
		// Get IDs of processed orders.
		$processedOrderIds = VariantSale::find()
			->select('orderId')
			->column();

		$ordersQuery = Order::find()
			->orderBy('id ASC')
			->offset($this->offset)
			->limit($this->limit)
			->isCompleted(true)
			->andWhere(['not in', 'id', $processedOrderIds]);

		if ($this->startDate && $this->endDate) {
			$ordersQuery->andWhere(['between', 'dateOrdered', $this->startDate, $this->endDate]);
		} else {
			$ordersQuery->andWhere(['<', 'dateOrdered', (new \DateTime())->format('Y-m-d H:i:s')]);
		}

		$orders = $ordersQuery->all();

		foreach ($orders as $order) {
			Plugin::getInstance()?->sales->logOrderSales($order);
		}

		$this->setProgress($queue, 1);
	}

	protected function defaultDescription(): string
	{
		return "Backfilling orders starting at offset {$this->offset}";
	}
}
