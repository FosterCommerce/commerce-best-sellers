<?php

namespace fostercommerce\bestsellers\helpers\summary;

use Craft;
use DateTime;
use fostercommerce\bestsellers\services\DateRange;

/**
 * Builds human-readable date range and comparison labels for summaries.
 */
abstract class RangeLabelBuilder
{
	/**
	 * Build a human-readable label for the current date range.
	 */
	public static function rangeLabel(string $preset, string $from, string $to): string
	{
		$fromDT = new DateTime($from);

		return match ($preset) {
			DateRange::PRESET_TODAY => Craft::t('best-sellers', 'today'),
			DateRange::PRESET_PAST_7_DAYS => Craft::t('best-sellers', 'this past week'),
			DateRange::PRESET_PAST_30_DAYS => Craft::t('best-sellers', 'the last 30 days'),
			DateRange::PRESET_PAST_90_DAYS => Craft::t('best-sellers', 'the last 90 days'),
			DateRange::PRESET_PAST_YEAR => Craft::t('best-sellers', 'the last year'),
			DateRange::PRESET_THIS_WEEK => Craft::t('best-sellers', 'this week so far'),
			DateRange::PRESET_THIS_MONTH => Craft::t('best-sellers', '{month} so far', [
				'month' => $fromDT->format('F'),
			]),
			DateRange::PRESET_THIS_YEAR => Craft::t('best-sellers', '{year} so far', [
				'year' => $fromDT->format('Y'),
			]),
			DateRange::PRESET_ALL => Craft::t('best-sellers', 'all time'),
			DateRange::PRESET_CUSTOM => self::formatCustomRange($fromDT, new DateTime($to)),
			default => self::formatCustomRange($fromDT, new DateTime($to)),
		};
	}

	/**
	 * Build a human-readable label for the comparison period.
	 */
	public static function comparisonLabel(string $preset, string $from, string $to, int $days): string
	{
		$fromDT = new DateTime($from);

		return match ($preset) {
			DateRange::PRESET_TODAY => Craft::t('best-sellers', 'yesterday'),
			DateRange::PRESET_PAST_7_DAYS => Craft::t('best-sellers', 'the prior week'),
			DateRange::PRESET_PAST_30_DAYS => Craft::t('best-sellers', 'the prior 30 days'),
			DateRange::PRESET_PAST_90_DAYS => Craft::t('best-sellers', 'the prior 90 days'),
			DateRange::PRESET_PAST_YEAR => Craft::t('best-sellers', 'the prior year'),
			DateRange::PRESET_THIS_WEEK => Craft::t('best-sellers', 'the same point last week'),
			DateRange::PRESET_THIS_MONTH => Craft::t('best-sellers', 'the same point in {month}', [
				'month' => (clone $fromDT)->modify('-1 month')->format('F'),
			]),
			DateRange::PRESET_THIS_YEAR => Craft::t('best-sellers', 'the same point in {year}', [
				'year' => (int) $fromDT->format('Y') - 1,
			]),
			DateRange::PRESET_CUSTOM => Craft::t('best-sellers', 'the prior {days} days', [
				'days' => $days,
			]),
			default => Craft::t('best-sellers', 'the prior {days} days', [
				'days' => $days,
			]),
		};
	}

	/**
	 * Determine whether the selected range is a partial (incomplete) period.
	 */
	public static function isPartial(string $preset, string $to): bool
	{
		$today = (new DateTime('now'))->format('Y-m-d');

		return match ($preset) {
			DateRange::PRESET_THIS_MONTH,
			DateRange::PRESET_THIS_YEAR,
			DateRange::PRESET_THIS_WEEK => $to > $today,
			default => false,
		};
	}

	/**
	 * Calculate the number of days in a range (inclusive).
	 */
	public static function dayCount(string $from, string $to): int
	{
		$fromDT = new DateTime($from);
		$toDT = new DateTime($to);

		return (int) $fromDT->diff($toDT)->days + 1;
	}

	/**
	 * Calculate elapsed days for partial periods (from range start to today).
	 */
	public static function elapsedDays(string $from): int
	{
		$fromDT = new DateTime($from);
		$today = new DateTime('now');

		return (int) $fromDT->diff($today)->days + 1;
	}

	private static function formatCustomRange(DateTime $from, DateTime $to): string
	{
		if ($from->format('Y') === $to->format('Y')) {
			return $from->format('M j') . ' - ' . $to->format('M j');
		}

		return $from->format('M j, Y') . ' - ' . $to->format('M j, Y');
	}
}
