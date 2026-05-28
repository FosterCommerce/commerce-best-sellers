<?php

namespace fostercommerce\bestsellers\traits;

use craft\db\Query;
use craft\db\Table as CraftTable;
use fostercommerce\bestsellers\models\ReportScope;

/**
 * Shared query condition builder for services that query commerce_orders.
 */
trait OrderQueryConditions
{
	/**
	 * Build a standard date + status condition for commerce_orders queries.
	 *
	 * @return list<mixed>
	 */
	private function buildDateCondition(ReportScope $scope, string $tableAlias = ''): array
	{
		$prefix = $tableAlias !== '' ? $tableAlias . '.' : '';

		$condition = [
			'and',
			['=', "[[{$prefix}isCompleted]]", true],
			$scope->dateRange->dateCondition("[[{$prefix}dateOrdered]]"),
		];

		$statusCondition = $scope->statusCondition($tableAlias);
		if ($statusCondition !== null) {
			$condition[] = $statusCondition;
		}

		return $condition;
	}

	/**
	 * Conditionally join the addresses table on the order's shippingAddressId
	 * and apply the shipping locations filter from the scope.
	 *
	 * No-op when no shipping locations filter is active.
	 *
	 * @param Query<array-key, mixed> $query
	 */
	private function applyShippingLocations(Query $query, ReportScope $scope, string $orderAlias = 'orders', string $addressesAlias = 'shippingAddresses'): void
	{
		if (! $scope->hasShippingLocationsFilter()) {
			return;
		}

		$orderPrefix = $orderAlias !== '' ? $orderAlias . '.' : '';

		$query->innerJoin(
			[
				$addressesAlias => CraftTable::ADDRESSES,
			],
			"[[{$orderPrefix}shippingAddressId]] = [[{$addressesAlias}.id]]"
		);

		$locationCondition = $scope->shippingLocationsCondition($addressesAlias);
		if ($locationCondition !== null) {
			$query->andWhere($locationCondition);
		}
	}
}
