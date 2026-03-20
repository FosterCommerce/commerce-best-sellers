<?php

namespace fostercommerce\bestsellers\controllers;

use craft\web\Controller;
use fostercommerce\bestsellers\Plugin;

abstract class BaseReportController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	/**
	 * Resolve the current and previous date range.
	 *
	 * @return array{from: string, to: string, preset: string, fromDT: string, toDT: string, prev: array{from: string, to: string, fromDT: string, toDT: string}}
	 */
	protected function resolveDateRange(): array
	{
		$dateRange = Plugin::getInstance()->dateRange;
		$current = $dateRange->resolve();
		$previous = $dateRange->previousPeriod($current['from'], $current['to']);

		return array_merge($current, ['prev' => $previous]);
	}

	/**
	 * Calculate percentage change between two values.
	 */
	public function percentChange(float|int $current, float|int $previous): ?float
	{
		if ($previous == 0 && $current == 0) {
			return null;
		}
		if ($previous == 0) {
			return null;
		}
		return round((($current - $previous) / $previous) * 100, 1);
	}
}
