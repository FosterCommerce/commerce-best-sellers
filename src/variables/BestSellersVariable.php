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
	 * @param int         $variantId
	 * @param string|null $startDate Human-readable or YYYY-MM-DD, e.g. "2 months ago"
	 * @param string|null $endDate   Human-readable or YYYY-MM-DD
	 * @return int
	 */
	public function variantTotalSales(int $variantId, ?string $startDate = null, ?string $endDate = null): int
	{
		$query = (new Query())
			->from(VariantSale::tableName())
			->where(['variantId' => $variantId]);

		if ($startDate) {
			// Parse the date into a proper format
			$start = (new \DateTime($startDate))->format('Y-m-d H:i:s');
			$query->andWhere(['>=', 'dateOrdered', $start]);
		}

		if ($endDate) {
			$end = (new \DateTime($endDate))->format('Y-m-d H:i:s');
			$query->andWhere(['<=', 'dateOrdered', $end]);
		}

		return (int)$query->sum('qty');
	}

	/**
	 * Returns the total sales (sum of qty) for a given product ID,
	 * optionally filtering by a date range on dateOrdered.
	 *
	 * @param int         $productId
	 * @param string|null $startDate Human-readable or YYYY-MM-DD, e.g. "2 months ago"
	 * @param string|null $endDate   Human-readable or YYYY-MM-DD
	 * @return int
	 */
	public function productTotalSales(int $productId, ?string $startDate = null, ?string $endDate = null): int
	{
		$query = (new Query())
			->from(VariantSale::tableName())
			->where(['productId' => $productId]);

		if ($startDate) {
			$start = (new \DateTime($startDate))->format('Y-m-d H:i:s');
			$query->andWhere(['>=', 'dateOrdered', $start]);
		}

		if ($endDate) {
			$end = (new \DateTime($endDate))->format('Y-m-d H:i:s');
			$query->andWhere(['<=', 'dateOrdered', $end]);
		}

		return (int)$query->sum('qty');
	}
}
