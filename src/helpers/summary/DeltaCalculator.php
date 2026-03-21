<?php

namespace fostercommerce\bestsellers\helpers\summary;

/**
 * Computes percent-change deltas between current and baseline metric values.
 */
abstract class DeltaCalculator
{
	/**
	 * Calculate the percent change from baseline to current.
	 *
	 * Returns null only when both values are zero (no data).
	 * Returns INF when baseline is zero but current is positive (conceptually infinite growth).
	 */
	public static function delta(float|int $current, float|int $baseline): ?float
	{
		$currentFloat = (float) $current;
		$baselineFloat = (float) $baseline;

		if ($baselineFloat === 0.0 && $currentFloat === 0.0) {
			return null;
		}

		if ($baselineFloat === 0.0) {
			return $currentFloat > 0 ? INF : -INF;
		}

		return round((($currentFloat - $baselineFloat) / $baselineFloat) * 100, 1);
	}

	/**
	 * Compute deltas for a set of metrics against a baseline.
	 *
	 * @param array<string, float|int> $current
	 * @param array<string, float|int> $baseline
	 * @return array<string, float|null>
	 */
	public static function computeAll(array $current, array $baseline): array
	{
		$deltas = [];

		foreach ($current as $metric => $value) {
			$baselineValue = $baseline[$metric] ?? 0;
			$deltas[$metric] = self::delta($value, $baselineValue);
		}

		return $deltas;
	}
}
