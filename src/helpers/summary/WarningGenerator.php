<?php

namespace fostercommerce\bestsellers\helpers\summary;

use Craft;
use DateTime;

/**
 * Generates contextual warning strings for summaries.
 */
abstract class WarningGenerator
{
	private const LOW_CHUNK_COUNT_THRESHOLD = 4;

	/**
	 * Generate all applicable warnings for a summary.
	 *
	 * @param array{
	 *   is_partial: bool,
	 *   days: int,
	 *   from: string,
	 *   to: string,
	 *   elapsed_days: int,
	 *   earliest_order_date: ?string,
	 *   yoy_available: bool,
	 *   trailing_chunk_count: ?int,
	 *   trailing_prorated: bool,
	 * } $context
	 * @return list<string>
	 */
	public static function generate(array $context): array
	{
		$warnings = [];

		if ($context['is_partial']) {
			$warnings[] = Craft::t('best-sellers', 'This period is not complete. Totals will change.');
		}

		if ($context['days'] <= 7) {
			$warnings[] = Craft::t('best-sellers', 'Weekly data can be volatile. A single large order can skew these numbers.');
		}

		if ($context['days'] >= 90) {
			$warnings[] = Craft::t('best-sellers', 'This is a long date range. Short-term changes may not be visible.');
		}

		if (! $context['yoy_available'] && $context['earliest_order_date'] !== null) {
			$warnings[] = Craft::t('best-sellers', 'Year-over-year data is unavailable. Site data begins {date}.', [
				'date' => (new DateTime($context['earliest_order_date']))->format('M j, Y'),
			]);
		}

		if ($context['trailing_chunk_count'] !== null && $context['trailing_chunk_count'] < self::LOW_CHUNK_COUNT_THRESHOLD) {
			$warnings[] = Craft::t('best-sellers', 'The 12-month average is based on only {count} comparable periods and may not be representative.', [
				'count' => $context['trailing_chunk_count'],
			]);
		}

		if ($context['trailing_prorated']) {
			$warnings[] = Craft::t('best-sellers', 'Averages are prorated to {days} days for a fair comparison.', [
				'days' => $context['elapsed_days'],
			]);
		}

		return $warnings;
	}
}
