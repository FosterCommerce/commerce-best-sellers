<?php

namespace fostercommerce\bestsellers\traits;

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
}
