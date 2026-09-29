<?php

namespace fostercommerce\bestsellers\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\MoneyHelper;
use craft\web\Controller;
use craft\web\Request;
use fostercommerce\bestsellers\models\ReportScope;
use fostercommerce\bestsellers\Plugin;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Money;
use Money\Parser\DecimalMoneyParser;
use RuntimeException;
use yii\base\Action;
use yii\web\Response;

abstract class BaseReportController extends Controller
{
	protected array|bool|int $allowAnonymous = false;

	private ?Currency $_storeCurrency = null;

	private ?DecimalMoneyParser $_moneyParser = null;

	/**
	 * @param Action<static> $action
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

		// Divide by the magnitude so a rise from a negative value, such as a loss, reads as a rise
		return round((($current - $previous) / abs($previous)) * 100, 1);
	}

	/**
	 * Resolve the full report scope (date range + order status filter).
	 */
	protected function resolveScope(): ReportScope
	{
		$plugin = Plugin::getInstance();

		return $plugin->dateRange->resolveScope();
	}

	protected function getStoreCurrency(): Currency
	{
		if (! $this->_storeCurrency instanceof Currency) {
			/** @var Commerce $commercePlugin */
			$commercePlugin = Commerce::getInstance();
			$store = $commercePlugin->getStores()->getPrimaryStore();
			$code = $store?->getCurrency()?->getCode() ?? 'USD';
			$this->_storeCurrency = new Currency($code);
		}

		return $this->_storeCurrency;
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

	/**
	 * Parse a decimal amount string into a Money value in the store's currency.
	 *
	 * Direct DecimalMoneyParser, not MoneyHelper::toMoney, so the return type is
	 * Money (not Money|false): a malformed amount throws at the parse boundary
	 * rather than silently coercing to false.
	 */
	protected function parseMoney(string $amount): Money
	{
		if (! $this->_moneyParser instanceof DecimalMoneyParser) {
			$this->_moneyParser = new DecimalMoneyParser(new ISOCurrencies());
		}

		return $this->_moneyParser->parse($amount, $this->getStoreCurrency());
	}

	protected function toMoney(float $amount): Money
	{
		return $this->parseMoney((string) $amount);
	}

	/**
	 * Read field filter selections from a `<param>[<instanceUid>][]` query parameter.
	 *
	 * @return array<string, list<string>>
	 */
	protected function resolveFieldFilterValues(string $param): array
	{
		/** @var Request $request */
		$request = Craft::$app->getRequest();
		$rawFieldFilters = $request->getQueryParam($param, []);
		if (! is_array($rawFieldFilters)) {
			return [];
		}

		$filterValues = [];
		foreach ($rawFieldFilters as $instanceUid => $rawValues) {
			if (! is_array($rawValues)) {
				continue;
			}

			foreach ($rawValues as $rawValue) {
				if (is_scalar($rawValue) && (string) $rawValue !== '') {
					$filterValues[(string) $instanceUid][] = (string) $rawValue;
				}
			}
		}

		return $filterValues;
	}

	/**
	 * Return a CSV response, written to a temp stream instead of a string.
	 *
	 * @param iterable<array<string, mixed>> $rows
	 * @param list<string> $headers
	 */
	protected function asCsv(iterable $rows, array $headers, string $reportType): Response
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

		/** @var Response $response */
		$response = Craft::$app->getResponse();

		return $response->sendStreamAsFile($output, $filename, [
			'mimeType' => 'text/csv',
		]);
	}
}
