<?php

namespace fostercommerce\bestsellers\variables;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
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
	 * Returns the most recent purchase info for the user
	 * for a given purchasable ID.
	 */
	public function previousPurchaseByUser(int $purchasableId, ?User $user = null): ?Order
	{
		// if no user provided, use the current logged-in user
		$user ??= Craft::$app->getUser()->getIdentity();

		// just in case there is still no user
		if ($user === null) {
			return null;
		}

		// query for most recent completed order with this purchasable
		$query = Order::find()
			->customer($user)
			->isCompleted()
			->innerJoin('{{%commerce_lineitems}} lineitems', '[[commerce_orders.id]] = [[lineitems.orderId]]')
			->andWhere([
				'lineitems.purchasableId' => $purchasableId,
			])
			->orderBy([
				'dateOrdered' => SORT_DESC,
			])
			->limit(1);

		/** @var ?Order */
		$order = $query->one();

		if (empty($order)) {
			return null;
		}

		return $order;
	}
}
