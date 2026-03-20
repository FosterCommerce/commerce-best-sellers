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
	 * Calculate percentage change between two values.
	 */
	public function percentChange(float|int $current, float|int $previous): ?float
	{
		if ((float) $previous === 0.0 && (float) $current === 0.0) {
			return null;
		}

		if ((float) $previous === 0.0) {
			return null;
		}

		return round((($current - $previous) / $previous) * 100, 1);
	}

	/**
	 * Resolve the current and previous date range.
	 *
	 * @return array{from: string, to: string, preset: string, fromDT: string, toDT: string, prev: array{from: string, to: string, fromDT: string, toDT: string}}
	 */
	protected function resolveDateRange(): array
	{
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);
		$dateRange = $plugin->dateRange;
		$current = $dateRange->resolve();
		$previous = $dateRange->previousPeriod($current['from'], $current['to']);

		return array_merge($current, [
			'prev' => $previous,
		]);
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
		if ($output === false) {
			throw new \RuntimeException('Failed to open temp stream');
		}

		fputcsv($output, $headers);

		foreach ($rows as $row) {
			/** @var array<int, bool|float|int|string|null> $values */
			$values = array_values($row);
			fputcsv($output, $values);
		}

		rewind($output);
		$csv = stream_get_contents($output);
		fclose($output);

		/** @var \craft\web\Response $response */
		$response = Craft::$app->getResponse();
		$response->format = Response::FORMAT_RAW;
		$response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
		$response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

		$response->data = $csv;

		return $response;
	}
}
