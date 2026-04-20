<?php

namespace fostercommerce\bestsellers\helpers;

use craft\commerce\Plugin as Commerce;
use Money\Currencies\ISOCurrencies;
use Money\Currency;
use Money\Formatter\DecimalMoneyFormatter;
use Money\Money;

/**
 * Money-safe math for stats and reports.
 *
 * Stats queries return aggregates as PHP floats from SQL SUM/AVG. Dividing or
 * accumulating those floats and then rounding is drift-prone. These helpers
 * perform the one-time boundary conversion into Money, do the math in minor
 * units, and hand back a string (DB/CSV) or float (model fields).
 */
final class MoneyMath
{
	public static function currency(): Currency
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$store = $commerce->getStores()->getPrimaryStore();
		$code = $store?->getCurrency()?->getCode();
		return new Currency($code !== null && $code !== '' ? $code : 'USD');
	}

	public static function toMoney(float|int|string $amount, ?Currency $currency = null): Money
	{
		$currency ??= self::currency();
		$subunit = (new ISOCurrencies())->subunitFor($currency);
		return new Money((int) round((float) $amount * (10 ** $subunit)), $currency);
	}

	/**
	 * Divide a monetary total by a divisor (count or weighted count).
	 * Returns Money(0) when divisor is zero or negative.
	 */
	public static function average(float|int|string $total, float|int|string $divisor, ?Currency $currency = null): Money
	{
		$currency ??= self::currency();
		$divisorFloat = (float) $divisor;
		if ($divisorFloat <= 0.0) {
			return new Money(0, $currency);
		}

		/** @var numeric-string $divisorString */
		$divisorString = (string) $divisorFloat;
		return self::toMoney($total, $currency)->divide($divisorString);
	}

	public static function toDecimal(Money $money): string
	{
		return (new DecimalMoneyFormatter(new ISOCurrencies()))->format($money);
	}

	public static function toFloat(Money $money): float
	{
		return (float) self::toDecimal($money);
	}
}
