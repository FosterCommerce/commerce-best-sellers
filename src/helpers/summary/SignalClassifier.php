<?php

namespace fostercommerce\bestsellers\helpers\summary;

use Craft;

/**
 * Classifies a percent-change delta into a directional signal string.
 */
abstract class SignalClassifier
{
	public const UP = 'up';

	public const SLIGHTLY_UP = 'slightly_up';

	public const FLAT = 'flat';

	public const SLIGHTLY_DOWN = 'slightly_down';

	public const DOWN = 'down';

	/**
	 * Classify a delta value into a signal string.
	 */
	public static function classify(?float $delta, string $metric, string $baseline): string
	{
		if ($delta === null) {
			return self::FLAT;
		}

		if (is_infinite($delta)) {
			return $delta > 0 ? self::UP : self::DOWN;
		}

		$mode = $baseline;
		if ($baseline === 'previous_period' && in_array($metric, self::wideThresholdMetrics(), true)) {
			$mode = 'previous_period_wide';
		}

		$thresholds = self::thresholds()[$mode] ?? self::thresholds()['previous_period'];

		if ($delta >= $thresholds['up']) {
			return self::UP;
		}

		if ($delta >= $thresholds['slightly_up']) {
			return self::SLIGHTLY_UP;
		}

		if ($delta >= $thresholds['flat']) {
			return self::FLAT;
		}

		if ($delta >= $thresholds['slightly_down']) {
			return self::SLIGHTLY_DOWN;
		}

		return self::DOWN;
	}

	/**
	 * Classify all deltas for a baseline.
	 *
	 * @param array<string, float|null> $deltas
	 * @return array<string, string>
	 */
	public static function classifyAll(array $deltas, string $baseline): array
	{
		$signals = [];

		foreach ($deltas as $metric => $delta) {
			$signals[$metric] = self::classify($delta, $metric, $baseline);
		}

		return $signals;
	}

	/**
	 * Check if a signal indicates a positive direction.
	 */
	public static function isPositive(string $signal): bool
	{
		return in_array($signal, [self::UP, self::SLIGHTLY_UP], true);
	}

	/**
	 * Check if a signal indicates a negative direction.
	 */
	public static function isNegative(string $signal): bool
	{
		return in_array($signal, [self::DOWN, self::SLIGHTLY_DOWN], true);
	}

	/**
	 * Get a human-readable direction word for a signal.
	 */
	public static function directionWord(string $signal): string
	{
		return match ($signal) {
			self::UP, self::SLIGHTLY_UP => Craft::t('best-sellers', 'up'),
			self::DOWN, self::SLIGHTLY_DOWN => Craft::t('best-sellers', 'down'),
			default => Craft::t('best-sellers', 'flat'),
		};
	}

	/**
	 * Default thresholds per comparison mode.
	 *
	 * Each set defines the lower bound for each signal bucket.
	 * Checked top-down: if delta >= threshold, that signal is returned.
	 *
	 * @return array<string, array{up: float, slightly_up: float, flat: float, slightly_down: float}>
	 */
	private static function thresholds(): array
	{
		return [
			'previous_period' => [
				'up' => 5,
				'slightly_up' => 1,
				'flat' => -1,
				'slightly_down' => -5,
			],
			'previous_period_wide' => [
				'up' => 10,
				'slightly_up' => 3,
				'flat' => -3,
				'slightly_down' => -10,
			],
			'trailing_average' => [
				'up' => 3,
				'slightly_up' => 1,
				'flat' => -1,
				'slightly_down' => -3,
			],
			'same_period_last_year' => [
				'up' => 8,
				'slightly_up' => 2,
				'flat' => -2,
				'slightly_down' => -8,
			],
		];
	}

	/**
	 * Metrics that use wider thresholds for previous_period comparison.
	 *
	 * @return list<string>
	 */
	private static function wideThresholdMetrics(): array
	{
		return ['new_customers', 'unique_products_sold'];
	}
}
