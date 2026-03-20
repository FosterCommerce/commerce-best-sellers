<?php

namespace fostercommerce\bestsellers\variables;

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

		$this->applyDateFilter($query, $startDate, $endDate);

		/** @var int $sum */
		$sum = $query->sum('qty');

		return $sum;
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

		return (float) $query->sum('lineItemTotal');
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

		/** @var int $sum */
		$sum = $query->sum('qty');

		return $sum;
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

		return (float) $query->sum('lineItemTotal');
	}

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
}
