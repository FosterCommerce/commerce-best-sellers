<?php

namespace fostercommerce\bestsellers\helpers;

use craft\base\Element;
use craft\db\Query as DbQuery;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use fostercommerce\bestsellers\behaviors\SaleQueryBehavior;
use fostercommerce\bestsellers\db\Table;

abstract class Query
{
	/**
	 * @template TKey of array-key
	 * @template TElement of Element
	 * @param ElementQuery<TKey, TElement> $query
	 */
	public static function attachQuery(ElementQuery $query, string $id, string $joinCondition): void
	{
		$behaviorExists = $query->getBehavior('bestSellers') !== null;
		if (! $behaviorExists) {
			return;
		}

		/** @var SaleQueryBehavior<TKey, TElement> $behavior */
		$behavior = $query->getBehavior('bestSellers');

		if (! $behavior->getIncludeBestSellersData()) {
			return;
		}

		$withQuery = (new DbQuery())
			->select([
				$id,
				'totalQtySold' => 'COALESCE(SUM(qty), 0)',
				'totalRevenue' => 'COALESCE(SUM(lineItemTotal), 0)',
			])
			->from(Table::VARIANT_SALES)
			->groupBy($id);

		if ($behavior->bestSellersFrom !== null) {
			$withQuery->andWhere(['>=', 'dateOrdered', Db::prepareDateForDb($behavior->bestSellersFrom)]);
		}

		if ($behavior->bestSellersTo !== null) {
			$withQuery->andWhere(['<=', 'dateOrdered', Db::prepareDateForDb($behavior->bestSellersTo)]);
		}

		// Attach CTE only to subQuery (handles filtering/sorting).
		// The outer query selects totalQtySold from the subquery results.
		$query
			->subQuery
			?->addSelect([
				'variant_sales_cte.totalQtySold',
				'variant_sales_cte.totalRevenue',
			])
			->withQuery($withQuery, 'variant_sales_cte')
			->leftJoin(
				'variant_sales_cte',
				$joinCondition,
			);

		$query
			->query
			?->addSelect([
				'subquery.totalQtySold',
				'subquery.totalRevenue',
			]);
	}
}
