<?php

namespace fostercommerce\bestsellers\helpers;

use craft\db\Query;
use craft\db\Table as CraftTable;

/**
 * Joins {{%elements}} and excludes soft-deleted rows.
 *
 * Required when running raw queries against element-backed tables
 * (commerce_orders, commerce_products, commerce_variants, users, addresses, etc.)
 * because the underlying element row carries the dateDeleted flag, not the
 * subtable. Element queries (Order::find(), etc.) handle this automatically;
 * raw Query objects do not.
 */
abstract class NotTrashed
{
	/**
	 * The caller must have already aliased the element-backed table in the
	 * outer query, e.g. ->from(['orders' => CommerceTable::ORDERS]).
	 *
	 * @template TKey of array-key
	 * @template TValue
	 * @param Query<TKey, TValue> $query
	 * @param string $tableAlias
	 * @return Query<TKey, TValue>
	 */
	public static function join(Query $query, string $tableAlias): Query
	{
		$joinAlias = $tableAlias . 'Element';

		return $query
			->innerJoin(
				[
					$joinAlias => CraftTable::ELEMENTS,
				],
				"[[{$joinAlias}.id]] = [[{$tableAlias}.id]]"
			)
			->andWhere([
				"[[{$joinAlias}.dateDeleted]]" => null,
			]);
	}
}
