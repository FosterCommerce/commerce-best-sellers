<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\web\Request;
use DateTime;
use fostercommerce\bestsellers\models\DateRangeResult;
use fostercommerce\bestsellers\models\ReportScope;
use yii\base\Component;

class DateRange extends Component
{
	public const PRESET_ALL = 'all';

	public const PRESET_TODAY = 'today';

	public const PRESET_THIS_WEEK = 'thisWeek';

	public const PRESET_THIS_MONTH = 'thisMonth';

	public const PRESET_THIS_YEAR = 'thisYear';

	public const PRESET_PAST_7_DAYS = 'past7Days';

	public const PRESET_PAST_30_DAYS = 'past30Days';

	public const PRESET_PAST_90_DAYS = 'past90Days';

	public const PRESET_PAST_YEAR = 'pastYear';

	public const PRESET_CUSTOM = 'custom';

	private const SESSION_KEY_FROM = 'bestSellers.dateRange.from';

	private const SESSION_KEY_TO = 'bestSellers.dateRange.to';

	private const SESSION_KEY_PRESET = 'bestSellers.dateRange.preset';

	private const SESSION_KEY_ORDER_STATUSES = 'bestSellers.scope.orderStatusIds';

	/**
	 * Resolve the date range from query params (priority) or session.
	 */
	public function resolve(): DateRangeResult
	{
		$request = Craft::$app->getRequest();
		$session = Craft::$app->getSession();

		// Query params take priority
		if ($request instanceof Request) {
			$preset = $request->getQueryParam('preset');
			$from = $request->getQueryParam('from');
			$to = $request->getQueryParam('to');
		} else {
			$preset = null;
			$from = null;
			$to = null;
		}

		// If query params present, save to session
		if ($preset !== null || $from !== null || $to !== null) {
			if ($preset !== null) {
				$session->set(self::SESSION_KEY_PRESET, $preset);
			}

			if ($from !== null) {
				$session->set(self::SESSION_KEY_FROM, $from);
			}

			if ($to !== null) {
				$session->set(self::SESSION_KEY_TO, $to);
			}
		} else {
			// Fall back to session
			$preset = $session->get(self::SESSION_KEY_PRESET);
			$from = $session->get(self::SESSION_KEY_FROM);
			$to = $session->get(self::SESSION_KEY_TO);
		}

		// Default preset
		$preset = $preset ?: self::PRESET_PAST_30_DAYS;

		// Resolve preset to dates (unless custom with explicit dates)
		if ($preset !== self::PRESET_CUSTOM) {
			[$from, $to] = $this->resolvePreset($preset);
		} elseif (! $from || ! $to) {
			// Custom - use provided dates or fall back to past 30 days
			[$from, $to] = $this->resolvePreset(self::PRESET_PAST_30_DAYS);
			$preset = self::PRESET_PAST_30_DAYS;
		} else {
			$from = trim((string) $from);
			$to = trim((string) $to);
		}

		// Convert to datetime strings for SQL
		$fromDTObj = new DateTime($from);
		$fromDTObj->setTime(0, 0, 0);

		$fromDT = $fromDTObj->format('Y-m-d H:i:s');

		$toDTObj = new DateTime($to);
		$toDTObj->setTime(23, 59, 59);

		$toDT = $toDTObj->format('Y-m-d H:i:s');

		// Persist to session
		$session->set(self::SESSION_KEY_PRESET, $preset);
		$session->set(self::SESSION_KEY_FROM, $from);
		$session->set(self::SESSION_KEY_TO, $to);

		return new DateRangeResult([
			'from' => $from,
			'to' => $to,
			'fromDT' => $fromDT,
			'toDT' => $toDT,
			'preset' => $preset,
		]);
	}

	/**
	 * Resolve a full report scope including date range and order status filter.
	 */
	public function resolveScope(): ReportScope
	{
		$dateRange = $this->resolve();

		// For partial periods (thisMonth, thisYear, thisWeek), clamp the comparison
		// to the elapsed portion so we compare apples to apples
		$today = (new DateTime('now'))->format('Y-m-d');
		$effectiveTo = $dateRange->to > $today ? $today : $dateRange->to;
		$previous = $this->previousPeriod($dateRange->from, $effectiveTo);

		$request = Craft::$app->getRequest();
		$session = Craft::$app->getSession();

		// Read order statuses from query params (priority) or session
		$statusHandles = null;
		if ($request instanceof Request) {
			$statusHandles = $request->getQueryParam('orderStatuses');
		}

		if ($statusHandles !== null) {
			if (is_string($statusHandles)) {
				// Empty string means "clear filter" (from hidden input fallback)
				$statusHandles = $statusHandles !== '' ? [$statusHandles] : [];
			} elseif (! is_array($statusHandles)) {
				$statusHandles = [];
			}

			// Convert handles to IDs
			$statusIds = $this->resolveStatusIds($statusHandles);
			$session->set(self::SESSION_KEY_ORDER_STATUSES, $statusIds);
		} else {
			$sessionValue = $session->get(self::SESSION_KEY_ORDER_STATUSES, []);
			/** @var list<int> $statusIds */
			$statusIds = is_array($sessionValue) ? $sessionValue : [];
		}

		$dateRange->prev = $previous;

		return new ReportScope([
			'dateRange' => $dateRange,
			'orderStatusIds' => $statusIds,
		]);
	}

	/**
	 * Calculate the previous period of the same duration.
	 */
	public function previousPeriod(string $from, string $to): DateRangeResult
	{
		$currentFrom = new DateTime($from);
		$currentTo = new DateTime($to);
		$interval = $currentFrom->diff($currentTo);

		$previousToDTObj = (clone $currentFrom)->modify('-1 second');
		$previousFromDTObj = (clone $previousToDTObj)->sub($interval);

		return new DateRangeResult([
			'from' => $previousFromDTObj->format('Y-m-d'),
			'to' => $previousToDTObj->format('Y-m-d'),
			'fromDT' => $previousFromDTObj->format('Y-m-d H:i:s'),
			'toDT' => $previousToDTObj->format('Y-m-d H:i:s'),
		]);
	}

	/**
	 * Convert order status handles to IDs.
	 *
	 * @param list<string> $handles
	 * @return list<int>
	 */
	private function resolveStatusIds(array $handles): array
	{
		if ($handles === []) {
			return [];
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$allStatuses = $commerce->getOrderStatuses()->getAllOrderStatuses();
		$ids = [];

		foreach ($allStatuses as $status) {
			if (in_array($status->handle, $handles, true)) {
				$ids[] = (int) $status->id;
			}
		}

		return $ids;
	}

	/**
	 * Resolve a preset handle to [from, to] date strings.
	 *
	 * @return array{0: string, 1: string}
	 */
	private function resolvePreset(string $preset): array
	{
		$now = new DateTime('now');
		$today = $now->format('Y-m-d');

		return match ($preset) {
			self::PRESET_TODAY => [$today, $today],
			self::PRESET_THIS_WEEK => [
				(new DateTime('monday this week'))->format('Y-m-d'),
				(new DateTime('sunday this week'))->format('Y-m-d'),
			],
			self::PRESET_THIS_MONTH => [
				$now->format('Y-m-01'),
				$now->format('Y-m-t'),
			],
			self::PRESET_THIS_YEAR => [
				$now->format('Y-01-01'),
				$now->format('Y-12-31'),
			],
			self::PRESET_PAST_7_DAYS => [
				(new DateTime('-7 days'))->format('Y-m-d'),
				(new DateTime('-1 day'))->format('Y-m-d'),
			],
			self::PRESET_PAST_30_DAYS => [
				(new DateTime('-30 days'))->format('Y-m-d'),
				(new DateTime('-1 day'))->format('Y-m-d'),
			],
			self::PRESET_PAST_90_DAYS => [
				(new DateTime('-90 days'))->format('Y-m-d'),
				(new DateTime('-1 day'))->format('Y-m-d'),
			],
			self::PRESET_PAST_YEAR => [
				(new DateTime('-1 year'))->format('Y-m-d'),
				(new DateTime('-1 day'))->format('Y-m-d'),
			],
			self::PRESET_ALL => [
				'2000-01-01',
				$today,
			],
			default => [
				(new DateTime('-30 days'))->format('Y-m-d'),
				$today,
			],
		};
	}
}
