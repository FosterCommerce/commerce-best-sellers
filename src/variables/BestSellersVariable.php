<?php

namespace fostercommerce\bestsellers\variables;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use fostercommerce\bestsellers\records\VariantSale;

class BestSellersVariable
{
	/**
	 * Returns the total sales (sum of qty) for a given variant ID,
	 * optionally filtering by a date range on dateOrdered.
	 *
	 * @param ?string $startDate Human-readable or YYYY-MM-DD, e.g. "2 months ago"
	 * @param ?string $endDate   Human-readable or YYYY-MM-DD
	 */
	public function variantTotalSales(int $variantId, ?string $startDate = null, ?string $endDate = null): int
	{
		$query = (new Query())
			->from(VariantSale::tableName())
			->where([
				'variantId' => $variantId,
			]);

		if ($startDate !== null) {
			// Parse the date into a proper format
			$start = (new \DateTime($startDate))->format('Y-m-d H:i:s');
			$query->andWhere(['>=', 'dateOrdered', $start]);
		}

		if ($endDate !== null) {
			$end = (new \DateTime($endDate))->format('Y-m-d H:i:s');
			$query->andWhere(['<=', 'dateOrdered', $end]);
		}

		/** @var int $sum */
		$sum = $query->sum('qty');

		return $sum;
	}

	/**
	 * Returns the total sales (sum of qty) for a given product ID,
	 * optionally filtering by a date range on dateOrdered.
	 *
	 * @param ?string $startDate Human-readable or YYYY-MM-DD, e.g. "2 months ago"
	 * @param ?string $endDate   Human-readable or YYYY-MM-DD
	 */
	public function productTotalSales(int $productId, ?string $startDate = null, ?string $endDate = null): int
	{
		$query = (new Query())
			->from(VariantSale::tableName())
			->where([
				'productId' => $productId,
			]);

		if ($startDate !== null) {
			$start = (new \DateTime($startDate))->format('Y-m-d H:i:s');
			$query->andWhere(['>=', 'dateOrdered', $start]);
		}

		if ($endDate !== null) {
			$end = (new \DateTime($endDate))->format('Y-m-d H:i:s');
			$query->andWhere(['<=', 'dateOrdered', $end]);
		}

		/** @var int $sum */
		$sum = $query->sum('qty');

		return $sum;
	}

	/**
	 * Returns the most recent purchase info for the current logged-in user
	 * for a given purchasable ID.
	 *
	 * @return array<mixed>|false
	 */
	public function previousPurchaseByCurrentUser(int $purchasableId): array|false
	{
		// check that commerce is installed
		if (! Craft::$app->getPlugins()->isPluginInstalled('commerce')) {
			return false;
		}

		// get current user
		$user = Craft::$app->getUser()->getIdentity();
		if (! $user) {
			return false;
		}

		// get all previous orders for that customer
		$ordersService = Commerce::getInstance()?->getOrders();
		if (! $ordersService) {
			return false;
		}

		/** @var \craft\commerce\elements\Order[] $orders */
		$orders = $ordersService->getOrdersByCustomer($user->id);

		if (empty($orders)) {
			return false;
		}

		// loop through orders and get line items
		$lineItems = [];
		foreach ($orders as $order) {
			foreach ($order->getLineItems() as $lineItem) {
				if ($lineItem->purchasableId === $purchasableId) {
					$lineItems[] = [
						'purchaseDate' => $order->dateOrdered?->format('U'),
						'orderId' => $order->id,
						'reference' => $order->reference,
						'number' => $order->number,
					];
				}
			}
		}

		// if no previous purchases, return false
		if ($lineItems === []) {
			return false;
		}

		// reorder by most recent purchase
		usort($lineItems, fn ($a, $b): int => strtotime((string) $b['purchaseDate']) - strtotime((string) $a['purchaseDate']));

		// return the most recent purchase
		return $lineItems[0];
	}
}
