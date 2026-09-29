<?php

namespace fostercommerce\bestsellers\helpers;

use craft\base\Element;
use craft\db\Query as DbQuery;
use craft\elements\db\ElementQuery;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use fostercommerce\bestsellers\behaviors\SaleQueryBehavior;
use fostercommerce\bestsellers\db\Table;

abstract class Query
{
	/**
	 * Parse one end of a date range in the site timezone. A bare `YYYY-MM-DD` end date covers the whole day.
	 */
	public static function toDateBound(string|DateTime $date, bool $isEnd): ?DateTime
	{
		if ($isEnd && is_string($date) && preg_match('/^\d{4}-\d{1,2}-\d{1,2}$/', $date) === 1) {
			$date .= ' 23:59:59';
		}

		return DateTimeHelper::toDateTime($date, true) ?: null;
	}

	/**
	 * Build a condition for orders placed within a site-timezone date range, or before now when either date is missing.
	 *
	 * @return array<array-key, mixed>
	 */
	public static function dateOrderedCondition(?string $startDate, ?string $endDate): array
	{
		if ($startDate === null || $startDate === '' || $endDate === null || $endDate === '') {
			return ['<', 'dateOrdered', Db::prepareDateForDb(new DateTime())];
		}

		// Convert the site-timezone day boundaries to UTC, which is how order dates are stored
		/** @var array<array-key, mixed> $condition */
		$condition = Db::parseDateParam('dateOrdered', ['and', ">= {$startDate} 00:00:00", "<= {$endDate} 23:59:59"]);

		return $condition;
	}

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

		// totalRevenue is gross (lineItemTotal only). totalItemSalesNet subtracts
		// line-level Discount adjustments and matches the CP Products report's
		// "Item Sales (Net)" column. Both kept for backward compatibility; new
		// callers should prefer totalItemSalesNet.
		$withQuery = (new DbQuery())
			->select([
				$id,
				'totalQtySold' => 'COALESCE(SUM(qty), 0)',
				'totalRevenue' => 'COALESCE(SUM([[lineItemTotal]]), 0)',
				'totalItemSalesNet' => 'COALESCE(SUM([[lineItemTotal]] + [[lineDiscount]]), 0)',
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
		// Zero rather than null for unsold elements, since PostgreSQL sorts nulls first in descending order
		$query
			->subQuery
			?->addSelect([
				'totalQtySold' => 'COALESCE([[variant_sales_cte.totalQtySold]], 0)',
				'totalRevenue' => 'COALESCE([[variant_sales_cte.totalRevenue]], 0)',
				'totalItemSalesNet' => 'COALESCE([[variant_sales_cte.totalItemSalesNet]], 0)',
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
				'subquery.totalItemSalesNet',
			]);
	}
}
