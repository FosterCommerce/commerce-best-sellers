<?php

namespace fostercommerce\bestsellers\helpers\summary;

use Craft;

/**
 * Matches metric-group signal signatures to sentence templates and interpolates variables.
 */
abstract class TemplateResolver
{
	/**
	 * Resolve a signature to a template string for the given group.
	 */
	public static function resolve(string $group, string $signature): string
	{
		$key = self::lookupKey($group, $signature);
		if ($key === null) {
			$fallbackKey = self::fallbackKey($group);
			if ($fallbackKey === null) {
				return '';
			}

			return Craft::t('best-sellers', $fallbackKey);
		}

		return Craft::t('best-sellers', $key);
	}

	/**
	 * Interpolate variables into a template string.
	 *
	 * @param array<string, string|float|int> $variables
	 */
	public static function interpolate(string $template, array $variables): string
	{
		$replacements = [];
		foreach ($variables as $key => $value) {
			$replacements['{' . $key . '}'] = (string) $value;
		}

		return strtr($template, $replacements);
	}

	/**
	 * Build a signature string from signals.
	 *
	 * @param list<string> $signalKeys Metric keys in signature order
	 * @param array<string, string> $signals
	 */
	public static function buildSignature(array $signalKeys, array $signals): string
	{
		$parts = [];
		foreach ($signalKeys as $signalKey) {
			$parts[] = $signals[$signalKey] ?? SignalClassifier::FLAT;
		}

		return implode('|', $parts);
	}

	/**
	 * Convert a pipe-separated signature into a camelCase key suffix.
	 *
	 * Example: "up|slightly_down|flat" => "upSlightlyDownFlat".
	 */
	private static function signatureToKey(string $signature): string
	{
		$parts = explode('|', $signature);
		$out = '';
		foreach ($parts as $index => $part) {
			$camel = lcfirst(str_replace('_', '', ucwords($part, '_')));
			$out .= $index === 0 ? $camel : ucfirst($camel);
		}

		return $out;
	}

	private static function lookupKey(string $group, string $signature): ?string
	{
		$known = self::knownSignatures()[$group] ?? [];
		if (! in_array($signature, $known, true)) {
			return null;
		}

		return 'summary.template.' . $group . '.' . self::signatureToKey($signature);
	}

	private static function fallbackKey(string $group): ?string
	{
		return in_array($group, ['orders', 'customers', 'products', 'abandonment'], true)
			? 'summary.fallback.' . $group
			: null;
	}

	/**
	 * Map of signatures defined in src/translations/en/best-sellers.php for each group.
	 * Add new signatures here alongside the en entry to keep the resolver in sync.
	 *
	 * @return array<string, list<string>>
	 */
	private static function knownSignatures(): array
	{
		return [
			'orders' => [
				'up|up|flat',
				'up|up|down',
				'up|up|up',
				'up|up|slightly_up',
				'up|up|slightly_down',
				'up|flat|up',
				'up|flat|slightly_up',
				'up|down|up',
				'up|slightly_up|flat',
				'up|slightly_up|slightly_up',
				'up|slightly_down|up',
				'slightly_up|slightly_up|flat',
				'slightly_up|flat|slightly_up',
				'slightly_up|slightly_up|slightly_up',
				'flat|flat|flat',
				'flat|down|up',
				'flat|up|down',
				'flat|slightly_down|slightly_up',
				'flat|slightly_up|slightly_down',
				'slightly_down|down|up',
				'slightly_down|down|flat',
				'slightly_down|flat|slightly_down',
				'slightly_down|slightly_down|flat',
				'slightly_down|slightly_down|slightly_down',
				'down|down|flat',
				'down|down|down',
				'down|down|up',
				'down|down|slightly_up',
				'down|down|slightly_down',
				'down|flat|down',
				'down|up|down',
				'down|slightly_down|down',
				'down|slightly_down|slightly_down',
			],
			'customers' => [
				'up|up|flat',
				'up|up|up',
				'up|up|down',
				'up|up|slightly_down',
				'up|up|slightly_up',
				'up|flat|up',
				'up|down|up',
				'up|slightly_up|flat',
				'flat|flat|flat',
				'flat|up|down',
				'flat|down|up',
				'slightly_down|slightly_down|flat',
				'slightly_down|down|up',
				'down|down|flat',
				'down|down|up',
				'down|down|down',
				'down|down|slightly_down',
				'down|flat|down',
				'down|up|down',
			],
			'products' => [
				'up|up',
				'up|flat',
				'up|down',
				'up|slightly_up',
				'up|slightly_down',
				'flat|flat',
				'flat|up',
				'flat|down',
				'slightly_up|flat',
				'slightly_down|flat',
				'down|down',
				'down|flat',
				'down|up',
				'down|slightly_down',
				'slightly_down|down',
				'slightly_down|slightly_down',
			],
			'abandonment' => [
				'down|down',
				'down|flat',
				'down|up',
				'flat|flat',
				'flat|up',
				'flat|down',
				'up|up',
				'up|flat',
				'up|down',
				'slightly_up|slightly_up',
				'slightly_down|slightly_down',
			],
		];
	}
}
