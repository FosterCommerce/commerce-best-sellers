<?php

namespace fostercommerce\bestsellers\helpers;

class KpiCards
{
	/**
	 * Build KPI card data for the given keys.
	 *
	 * @param array<string, mixed> $stats Current period stats
	 * @param array<string, mixed> $prevStats Previous period stats
	 * @param list<string> $keys Which cards to include
	 * @param callable $percentChange fn(current, previous) => ?float
	 * @return list<array<string, mixed>>
	 */
	public static function build(array $stats, array $prevStats, array $keys, callable $percentChange): array
	{
		$definitions = self::definitions();
		$cards = [];

		foreach ($keys as $key) {
			if (! isset($definitions[$key])) {
				continue;
			}

			$def = $definitions[$key];

			/** @var callable(array<string, mixed>): (float|int) $resolve */
			$resolve = $def['resolve'];
			$value = $resolve($stats);
			$prevValue = $resolve($prevStats);

			$cards[] = [
				'label' => $def['label'],
				'value' => $value,
				'change' => $percentChange($value, $prevValue),
				'format' => $def['format'],
				'sparklineId' => $def['sparklineId'] ?? null,
				'sparklineColumn' => $def['sparklineColumn'] ?? null,
				'description' => $def['description'] ?? null,
			];
		}

		return $cards;
	}

	/**
	 * Get the sparkline columns needed for the given card keys.
	 *
	 * @param list<string> $keys
	 * @return array<string, string> sparklineId => column name
	 */
	public static function sparklineColumns(array $keys): array
	{
		$definitions = self::definitions();
		$columns = [];

		foreach ($keys as $key) {
			if (! isset($definitions[$key])) {
				continue;
			}

			$def = $definitions[$key];
			if (! empty($def['sparklineId']) && ! empty($def['sparklineColumn'])) {
				/** @var string $sparklineId */
				$sparklineId = $def['sparklineId'];
				/** @var string $sparklineColumn */
				$sparklineColumn = $def['sparklineColumn'];
				$columns[$sparklineId] = $sparklineColumn;
			}
		}

		return $columns;
	}

	/**
	 * @return array<string, array<string, mixed>>
	 */
	private static function definitions(): array
	{
		return [
			'revenue' => [
				'label' => 'Revenue',
				'format' => 'currency',
				'sparklineId' => 'sparkRevenue',
				'sparklineColumn' => 'totalRevenue',
				'resolve' => fn (array $s) => $s['totalRevenue'] ?? 0,
			],
			'orders' => [
				'label' => 'Orders',
				'format' => 'number',
				'sparklineId' => 'sparkOrders',
				'sparklineColumn' => 'totalOrders',

				'resolve' => fn (array $s) => $s['totalOrders'] ?? 0,
			],
			'aov' => [
				'label' => 'Avg. Order Value',
				'format' => 'currency',
				'sparklineId' => 'sparkAov',
				'sparklineColumn' => 'averageOrderValue',

				'resolve' => fn (array $s) => $s['averageOrderValue'] ?? 0,
			],
			'customers' => [
				'label' => 'Customers',
				'format' => 'number',
				'sparklineId' => 'sparkCustomers',
				'sparklineColumn' => 'uniqueCustomers',
				'resolve' => fn (array $s) => $s['uniqueCustomers'] ?? 0,
			],
			'itemsSold' => [
				'label' => 'Items Sold',
				'format' => 'number',
				'sparklineId' => 'sparkItemsSold',
				'sparklineColumn' => 'totalItemsSold',
				'resolve' => fn (array $s) => $s['totalItemsSold'] ?? 0,
			],
			'newCustomers' => [
				'label' => 'New Customers',
				'format' => 'number',
				'sparklineId' => 'sparkNewCustomers',
				'sparklineColumn' => 'newCustomers',
				'resolve' => fn (array $s) => $s['newCustomers'] ?? 0,
			],
			'repeatRate' => [
				'label' => 'Repeat Rate',
				'format' => 'percent',
				'sparklineId' => 'sparkReturning',
				'sparklineColumn' => 'returningCustomers',
				'resolve' => function (array $s): float|int {
					$unique = $s['uniqueCustomers'] ?? 0;
					$returning = $s['returningCustomers'] ?? 0;
					return $unique > 0 ? round(($returning / $unique) * 100, 1) : 0;
				},
			],
			'avgItemsPerOrder' => [
				'label' => 'Avg Items / Order',
				'format' => 'decimal',
				'sparklineId' => 'sparkAvgItems',
				'sparklineColumn' => 'averageItemsPerOrder',

				'resolve' => fn (array $s) => $s['averageItemsPerOrder'] ?? 0,
			],
			'totalDiscount' => [
				'label' => 'Total Discount',
				'format' => 'currency',
				'sparklineId' => 'sparkDiscount',
				'sparklineColumn' => 'totalDiscount',
				'resolve' => fn (array $s): float|int => abs($s['totalDiscount'] ?? 0),
			],
		];
	}
}
