<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\Plugin as CommercePlugin;
use craft\helpers\MoneyHelper;
use craft\web\Controller;
use fostercommerce\bestsellers\models\ReportScope;
use fostercommerce\bestsellers\Plugin;
use Money\Currency;
use Money\Money;
use RuntimeException;
use yii\web\Response;

abstract class BaseReportController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	private ?Currency $storeCurrency = null;

	/**
	 * @param \yii\base\Action<static> $action
	 */
	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requirePermission(Plugin::PERMISSION_VIEW_REPORTS);

		return true;
	}

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
	 * Resolve the full report scope (date range + order status filter).
	 */
	protected function resolveScope(): ReportScope
	{
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);

		return $plugin->dateRange->resolveScope();
	}

	protected function getStoreCurrency(): Currency
	{
		if (! $this->storeCurrency instanceof Currency) {
			$store = CommercePlugin::getInstance()?->getStores()->getPrimaryStore();
			$code = $store?->getCurrency()?->getCode() ?? 'USD';
			$this->storeCurrency = new Currency($code);
		}

		return $this->storeCurrency;
	}

	/**
	 * @return non-empty-string
	 */
	protected function getStoreCurrencyCode(): string
	{
		return $this->getStoreCurrency()->getCode();
	}

	/**
	 * Format a number as the store's currency.
	 */
	protected function formatCurrency(float|int|string $amount): string
	{
		return Craft::$app->getFormatter()->asCurrency($amount, $this->getStoreCurrencyCode());
	}

	/**
	 * Format a Money object as the store's currency string.
	 */
	protected function formatMoney(Money $money): string
	{
		return $this->formatCurrency((string) MoneyHelper::toDecimal($money));
	}

	protected function toMoney(float $amount): Money
	{
		/** @var Money $money */
		$money = MoneyHelper::toMoney([
			'value' => (string) $amount,
			'currency' => $this->getStoreCurrency(),
		]);

		return $money;
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
			throw new RuntimeException('Failed to open temp stream');
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

		/** @var Response $response */
		$response = Craft::$app->getResponse();
		$response->format = Response::FORMAT_RAW;
		$response->headers->set('Content-Type', 'text/csv; charset=UTF-8');
		$response->headers->set('Content-Disposition', 'attachment; filename="' . $filename . '"');

		$response->data = $csv;

		return $response;
	}
}
