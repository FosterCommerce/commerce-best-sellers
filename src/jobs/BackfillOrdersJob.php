<?php

namespace fostercommerce\bestsellers\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use DateTime;
use fostercommerce\bestsellers\Plugin;
use fostercommerce\bestsellers\records\VariantSale;
use Throwable;

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
			$ordersQuery->andWhere(['<', 'dateOrdered', (new DateTime())->format('Y-m-d H:i:s')]);
		}

		$orders = $ordersQuery->all();
		$total = count($orders);

		$plugin = Plugin::getInstance();

		foreach ($orders as $i => $order) {
			try {
				$plugin->sales->logOrderSales($order);
			} catch (Throwable $e) {
				Craft::warning("Failed to process order #{$order->id}: {$e->getMessage()}", 'best-sellers');
				$plugin->backfillLogs->log('backfill', (string) $order->id, $e->getMessage());
			}

			$this->setProgress($queue, ($i + 1) / $total);
		}
	}

	protected function defaultDescription(): string
	{
		return Craft::t('best-sellers', 'Backfilling orders starting at offset {offset}', [
			'offset' => $this->offset,
		]);
	}
}
