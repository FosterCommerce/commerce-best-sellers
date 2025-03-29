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
		$withQuery = (new DbQuery())
			->select([
				$id,
				'totalQtySold' => 'SUM(qty)',
			])
			->from(VariantSale::tableName())
			->groupBy($id);

		// Behavior types aren't inferred
		/** @var SaleQueryBehavior<TKey, TElement> $query */
		if ($query->bestSellersFrom !== null) {
			$withQuery->andWhere(['>=', 'dateCreated', Db::prepareDateForDb($query->bestSellersFrom)]);
		}

		if ($query->bestSellersTo !== null) {
			$withQuery->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($query->bestSellersTo)]);
		}

		// We need to reset the type here
		/** @var ElementQuery<TKey, TElement> $query */
		$query
			->query
			?->addSelect(['variant_sales_cte.totalQtySold'])
			->withQuery($withQuery, 'variant_sales_cte')
			->leftJoin(
				'variant_sales_cte',
				$joinCondition,
			);

		$query
			->subQuery
			?->addSelect(['variant_sales_cte.totalQtySold'])
			->withQuery($withQuery, 'variant_sales_cte')
			->leftJoin(
				'variant_sales_cte',
				$joinCondition,
			);
	}
}
