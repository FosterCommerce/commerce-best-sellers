<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\Plugin as CommercePlugin;
use craft\web\Controller;
use fostercommerce\bestsellers\Plugin;
use yii\web\Response;

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

	/**
	 * Get the store's currency code (e.g. 'USD').
	 */
	protected function getStoreCurrency(): string
	{
		$store = CommercePlugin::getInstance()?->getStores()->getPrimaryStore();
		return $store?->getCurrency()?->getCode() ?? 'USD';
	}

	/**
	 * Format a number as the store's currency.
	 */
	protected function formatCurrency(float|int $amount): string
	{
		return Craft::$app->getFormatter()->asCurrency($amount, $this->getStoreCurrency());
	}

	/**
	 * Return a CSV response from an array of rows.
	 *
	 * @param list<array<string, mixed>> $rows
	 * @param list<string> $headers
	 */
	protected function asCsv(array $rows, array $headers, string $reportType): Response
	{
		$siteHandle = Craft::$app->getSites()->getCurrentSite()->handle;
		$timestamp = date('Y-m-d-Hi');
		$filename = $siteHandle . '-' . $reportType . '-' . $timestamp . '.csv';

		$output = fopen('php://temp', 'r+');
		fputcsv($output, $headers);

		foreach ($rows as $row) {
			fputcsv($output, array_values($row));
		}

		rewind($output);
		$csv = stream_get_contents($output);
		fclose($output);

		$response = Craft::$app->getResponse();
		$response->format = Response::FORMAT_RAW;
		$response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
		$response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');
		$response->data = $csv;

		return $response;
	}
}
