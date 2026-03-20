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

		$this->applyDateFilter($query, $startDate, $endDate);

		/** @var string|int|null|false $sum */
		$sum = $query->sum('qty');

		return (int) ($sum ?? 0);
	}

	/**
	 * Returns the total revenue (sum of lineItemTotal) for a given variant ID.
	 */
	public function variantTotalRevenue(int $variantId, ?string $startDate = null, ?string $endDate = null): float
	{
		$query = (new Query())
			->from(VariantSale::tableName())
			->where([
				'variantId' => $variantId,
			]);

		$this->applyDateFilter($query, $startDate, $endDate);

		/** @var string|int|null|false $sum */
		$sum = $query->sum('lineItemTotal');

		return (float) ($sum ?? 0);
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

		$this->applyDateFilter($query, $startDate, $endDate);

		/** @var string|int|null|false $sum */
		$sum = $query->sum('qty');

		return (int) ($sum ?? 0);
	}

	/**
	 * Returns the total revenue (sum of lineItemTotal) for a given product ID.
	 */
	public function productTotalRevenue(int $productId, ?string $startDate = null, ?string $endDate = null): float
	{
		$query = (new Query())
			->from(VariantSale::tableName())
			->where([
				'productId' => $productId,
			]);

		$this->applyDateFilter($query, $startDate, $endDate);

		/** @var string|int|null|false $sum */
		$sum = $query->sum('lineItemTotal');

		return (float) ($sum ?? 0);
	}

	/**
	 * Returns the most recent completed order containing a given purchasable for a user.
	 */
	public function previousPurchaseByUser(int $purchasableId, User $user): ?Order
	{
		/** @var Order|null $order */
		$order = Order::find()
			->customer($user)
			->isCompleted()
			->innerJoin(
				[
					'lineitems' => '{{%commerce_lineitems}}',
				],
				'[[commerce_orders.id]] = [[lineitems.orderId]]'
			)
			->andWhere([
				'[[lineitems.purchasableId]]' => $purchasableId,
			])
			->orderBy([
				'dateOrdered' => SORT_DESC,
			])
			->limit(1)
			->one();

		return $order;
	}

	/**
	 * Returns a query for all variants previously purchased by a user,
	 * ordered by most recent purchase.
	 *
	 * @return VariantQuery<array-key, Variant>|null
	 */
	public function previouslyPurchasedProducts(User $user): ?VariantQuery
	{
		/** @var list<array{purchasableId: int|null}> $rows */
		$rows = (new Query())
			->select('[[l.purchasableId]]')
			->from([
				'o' => '{{%commerce_orders}}',
			])
			->leftJoin([
				'l' => '{{%commerce_lineitems}}',
			], '[[o.id]] = [[l.orderId]]')
			->where([
				'[[o.isCompleted]]' => true,
			])
			->andWhere([
				'[[o.customerId]]' => $user->id,
			])
			->andWhere([
				'not', [
					'[[l.purchasableId]]' => null,
				]])
			->orderBy([
				'[[o.dateOrdered]]' => SORT_DESC,
			])
			->all();

		if ($rows === []) {
			return null;
		}

		$purchasableIds = array_map(fn (array $row): int|null => $row['purchasableId'], $rows);

		return Variant::find()
			->id($purchasableIds)
			->fixedOrder();
	}

	/**
	 * @param Query<array-key, mixed> $query
	 */
	private function applyDateFilter(Query $query, ?string $startDate, ?string $endDate): void
	{
		if ($startDate !== null) {
			$start = (new \DateTime($startDate))->format('Y-m-d H:i:s');
			$query->andWhere(['>=', 'dateOrdered', $start]);
		}

		if ($endDate !== null) {
			$end = (new \DateTime($endDate))->format('Y-m-d H:i:s');
			$query->andWhere(['<=', 'dateOrdered', $end]);
		}
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
			->andWhere([
				'not',
				[
					'l.purchasableId' => null,
				],
			])
			->orderBy('o.dateOrdered desc')
			->all();

		if (empty($purchasableIds)) {
			return null;
		}

		/** @var array<int,array{purchasableId: ?int}> $purchasableIds */
		$purchasables = array_map(fn ($row): mixed => $row['purchasableId'], $purchasableIds);

		return Variant::find()
			->id($purchasables)
			->fixedOrder();
	}
}
