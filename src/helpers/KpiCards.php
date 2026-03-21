<?php

namespace fostercommerce\bestsellers\helpers;

use Craft;
use fostercommerce\bestsellers\models\PeriodStats;

abstract class KpiCards
{
	/**
	 * Build KPI card data for the given keys.
	 *
	 * @param list<string> $keys Which cards to include
	 * @param callable $percentChange fn(current, previous) => ?float
	 * @return list<array<string, mixed>>
	 */
	public static function build(PeriodStats $stats, PeriodStats $prevStats, array $keys, callable $percentChange): array
	{
		$definitions = self::definitions();
		$cards = [];

		foreach ($keys as $key) {
			if (! isset($definitions[$key])) {
				continue;
			}

			$def = $definitions[$key];

			/** @var callable(PeriodStats): (float|int) $resolve */
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
				'label' => Craft::t('best-sellers', 'Revenue'),
				'format' => 'currency',
				'sparklineId' => 'sparkRevenue',
				'sparklineColumn' => 'totalRevenue',
				'resolve' => fn (PeriodStats $s): float => $s->totalRevenue,
			],
			'orders' => [
				'label' => Craft::t('best-sellers', 'Orders'),
				'format' => 'number',
				'sparklineId' => 'sparkOrders',
				'sparklineColumn' => 'totalOrders',
				'resolve' => fn (PeriodStats $s): int => $s->totalOrders,
			],
			'aov' => [
				'label' => Craft::t('best-sellers', 'Avg. Order Value'),
				'format' => 'currency',
				'sparklineId' => 'sparkAov',
				'sparklineColumn' => 'averageOrderValue',
				'resolve' => fn (PeriodStats $s): float => $s->averageOrderValue,
			],
			'customers' => [
				'label' => Craft::t('best-sellers', 'Customers'),
				'format' => 'number',
				'sparklineId' => 'sparkCustomers',
				'sparklineColumn' => 'uniqueCustomers',
				'resolve' => fn (PeriodStats $s): int => $s->uniqueCustomers,
			],
			'itemsSold' => [
				'label' => Craft::t('best-sellers', 'Items Sold'),
				'format' => 'number',
				'sparklineId' => 'sparkItemsSold',
				'sparklineColumn' => 'totalItemsSold',
				'resolve' => fn (PeriodStats $s): int => $s->totalItemsSold,
			],
			'newCustomers' => [
				'label' => Craft::t('best-sellers', 'New Customers'),
				'format' => 'number',
				'sparklineId' => 'sparkNewCustomers',
				'sparklineColumn' => 'newCustomers',
				'resolve' => fn (PeriodStats $s): int => $s->newCustomers,
			],
			'repeatRate' => [
				'label' => Craft::t('best-sellers', 'Repeat Rate'),
				'format' => 'percent',
				'sparklineId' => 'sparkReturning',
				'sparklineColumn' => 'returningCustomers',
				'resolve' => function (PeriodStats $s): float|int {
					$unique = $s->uniqueCustomers;
					$returning = $s->returningCustomers;
					return $unique > 0 ? round(($returning / $unique) * 100, 1) : 0;
				},
			],
			'avgItemsPerOrder' => [
				'label' => Craft::t('best-sellers', 'Avg Items / Order'),
				'format' => 'decimal',
				'sparklineId' => 'sparkAvgItems',
				'sparklineColumn' => 'averageItemsPerOrder',
				'resolve' => fn (PeriodStats $s): float => $s->averageItemsPerOrder,
			],
			'totalDiscount' => [
				'label' => Craft::t('best-sellers', 'Total Discounts'),
				'format' => 'currency',
				'sparklineId' => 'sparkDiscount',
				'sparklineColumn' => 'totalDiscount',
				'resolve' => fn (PeriodStats $s): float => abs($s->totalDiscount),
			],
		];
	}
}
