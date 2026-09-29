<?php

namespace fostercommerce\bestsellers\jobs;

use Craft;
use craft\commerce\elements\Order;
use craft\queue\BaseJob;
use fostercommerce\bestsellers\helpers\Query;
use fostercommerce\bestsellers\Plugin;
use Throwable;

class BackfillOrdersJob extends BaseJob
{
	public int $offset = 0;

	public int $limit = 25;

	// Optional date range properties, as strings (e.g. '2023-01-01')
	public ?string $startDate = null;

	public ?string $endDate = null;

	/**
	 * When true, existing variant_sales rows for each processed order are
	 * deleted before re-inserting. Used by rebuild migrations that change
	 * how rows are computed.
	 */
	public bool $force = false;

	public function execute($queue): void
	{
		$ordersQuery = Order::find()
			->orderBy('id ASC')
			->offset($this->offset)
			->limit($this->limit)
			->isCompleted(true);

		$ordersQuery->andWhere(Query::dateOrderedCondition($this->startDate, $this->endDate));

		$orders = $ordersQuery->all();
		$total = count($orders);

		foreach ($orders as $i => $order) {
			try {
				$this->processOrder($order);
			} catch (Throwable $throwable) {
				$this->logFailure($order, $throwable);
			}

			$this->setProgress($queue, ($i + 1) / $total);
		}
	}

	protected function processOrder(Order $order): void
	{
		Plugin::getInstance()->sales->logOrderSales($order, $this->force);
	}

	protected function logFailure(Order $order, Throwable $throwable): void
	{
		Craft::warning("Failed to process order #{$order->id}: {$throwable->getMessage()}", 'best-sellers');
		Plugin::getInstance()->backfillLogs->log('backfill', (string) $order->id, $throwable->getMessage());
	}

	protected function defaultDescription(): string
	{
		return Craft::t('best-sellers', 'jobs.backfillOrders', [
			'offset' => $this->offset,
		]);
	}
}
