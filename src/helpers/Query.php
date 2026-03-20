<?php

namespace fostercommerce\bestsellers\helpers;

use craft\base\Element;
use craft\db\Query as DbQuery;
use craft\elements\db\ElementQuery;
use craft\helpers\Db;
use fostercommerce\bestsellers\behaviors\SaleQueryBehavior;
use fostercommerce\bestsellers\records\VariantSale;

class Query
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

		/** @var (ElementQuery<TKey, TElement> & SaleQueryBehavior<TKey, TElement>) $query */

		// Behavior types aren't inferred
		if (! $query->getIncludeBestSellersData()) {
			return;
		}

		$withQuery = (new DbQuery())
			->select([
				$id,
				'totalQtySold' => 'SUM(qty)',
			])
			->from(VariantSale::tableName())
			->groupBy($id);

		if ($query->bestSellersFrom !== null) {
			$withQuery->andWhere(['>=', 'dateOrdered', Db::prepareDateForDb($query->bestSellersFrom)]);
		}

		if ($query->bestSellersTo !== null) {
			$withQuery->andWhere(['<=', 'dateOrdered', Db::prepareDateForDb($query->bestSellersTo)]);
		}

		// Attach CTE only to subQuery (handles filtering/sorting).
		// The outer query selects totalQtySold from the subquery results.
		$query
			->subQuery
			?->addSelect(['variant_sales_cte.totalQtySold'])
			->withQuery($withQuery, 'variant_sales_cte')
			->leftJoin(
				'variant_sales_cte',
				$joinCondition,
			);

		$query
			->query
			?->addSelect(['subquery.totalQtySold']);
	}
}
