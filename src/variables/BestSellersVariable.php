<?php

namespace fostercommerce\bestsellers\variables;

use craft\commerce\elements\db\VariantQuery;
use craft\commerce\elements\Order;
use craft\commerce\elements\Variant;
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
	public function previousPurchaseByUser(int $purchasableId, User $user): ?Order
	{
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

	/**
	 * @return VariantQuery <array-key,Variant>|null
	 */
	public function previouslyPurchasedProducts(User $user): ?VariantQuery
	{
		$purchasableIds = (new Query())
			->select('l.purchasableId')
			->from('{{%commerce_orders}} o')
			->leftJoin('{{%commerce_lineitems}} l', '[[o.id]] = [[l.orderId]]')
			->where([
				'[[o.isCompleted]]' => true,
			])
			->andWhere([
				'[[o.customerId]]' => $user->id,
			])
			->orderBy('o.dateOrdered desc')
			->all();

		if (empty($purchasableIds)) {
			return null;
		}

		/** @var array<array-key,array<array-key,int>> $purchasableIds */
		$purchasables = array_map(fn ($row): mixed => $row['purchasableId'], $purchasableIds);
		$purchasables = array_filter($purchasables, fn ($id): bool => $id !== null);

		if ($purchasables === []) {
			return null;
		}

		return Variant::find()
			->id($purchasables)
			->fixedOrder();
	}
}
