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
		$templates = self::templates();
		$groupTemplates = $templates[$group] ?? [];

		return $groupTemplates[$signature] ?? self::fallbacks()[$group] ?? '';
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
	 * @return array<string, array<string, string>>
	 */
	private static function templates(): array
	{
		return [
			'orders' => [
				'up|up|flat' => Craft::t('best-sellers', 'Revenue is up {revenue_delta}, with order volume also climbing ({orders_delta}).'),
				'up|up|down' => Craft::t('best-sellers', 'Revenue is up {revenue_delta} on higher volume ({orders_delta} more orders), though AOV dipped {aov_delta}.'),
				'up|up|up' => Craft::t('best-sellers', 'Revenue is up {revenue_delta}, with both order volume ({orders_delta}) and AOV ({aov_delta}) climbing.'),
				'up|up|slightly_up' => Craft::t('best-sellers', 'Revenue is up {revenue_delta}, with both order volume ({orders_delta}) and AOV ({aov_delta}) climbing.'),
				'up|up|slightly_down' => Craft::t('best-sellers', 'Revenue is up {revenue_delta} on higher volume ({orders_delta} more orders), though AOV dipped slightly.'),
				'up|flat|up' => Craft::t('best-sellers', 'Revenue is up {revenue_delta}, driven by higher AOV ({aov_delta}).'),
				'up|flat|slightly_up' => Craft::t('best-sellers', 'Revenue is up {revenue_delta}, nudged by a slight AOV increase.'),
				'up|down|up' => Craft::t('best-sellers', 'Revenue is up {revenue_delta} despite fewer orders, with AOV climbing {aov_delta}.'),
				'up|slightly_up|flat' => Craft::t('best-sellers', 'Revenue is up {revenue_delta}, with a slight uptick in order volume.'),
				'up|slightly_up|slightly_up' => Craft::t('best-sellers', 'Revenue is up {revenue_delta}, with both order volume and AOV nudging upward.'),
				'up|slightly_down|up' => Craft::t('best-sellers', 'Revenue is up {revenue_delta} as higher AOV ({aov_delta}) more than offset a slight dip in orders.'),
				'slightly_up|slightly_up|flat' => Craft::t('best-sellers', 'Revenue is up slightly ({revenue_delta}), with a modest increase in orders.'),
				'slightly_up|flat|slightly_up' => Craft::t('best-sellers', 'Revenue is up slightly ({revenue_delta}), with AOV inching higher.'),
				'slightly_up|slightly_up|slightly_up' => Craft::t('best-sellers', 'Revenue is up slightly ({revenue_delta}), with both orders and AOV trending upward.'),
				'flat|flat|flat' => Craft::t('best-sellers', 'Revenue and order volume are holding steady.'),
				'flat|down|up' => Craft::t('best-sellers', 'Revenue is roughly flat ({revenue_delta}) on fewer orders, but AOV is up {aov_delta}.'),
				'flat|up|down' => Craft::t('best-sellers', 'Revenue is roughly flat ({revenue_delta}) despite more orders ({orders_delta}), as AOV dipped {aov_delta}.'),
				'flat|slightly_down|slightly_up' => Craft::t('best-sellers', 'Revenue is holding steady as a slight drop in orders was offset by higher AOV.'),
				'flat|slightly_up|slightly_down' => Craft::t('best-sellers', 'Revenue is holding steady as a slight increase in orders was offset by lower AOV.'),
				'slightly_down|down|up' => Craft::t('best-sellers', 'Revenue dipped slightly ({revenue_delta}) as fewer orders were partially offset by higher AOV ({aov_delta}).'),
				'slightly_down|down|flat' => Craft::t('best-sellers', 'Revenue is down slightly ({revenue_delta}), driven by a {orders_delta} drop in orders.'),
				'slightly_down|flat|slightly_down' => Craft::t('best-sellers', 'Revenue dipped slightly ({revenue_delta}), with AOV trending a bit lower.'),
				'slightly_down|slightly_down|flat' => Craft::t('best-sellers', 'Revenue is down slightly ({revenue_delta}), with a modest decline in orders.'),
				'slightly_down|slightly_down|slightly_down' => Craft::t('best-sellers', 'Revenue dipped slightly ({revenue_delta}), with both orders and AOV trending a bit lower.'),
				'down|down|flat' => Craft::t('best-sellers', 'Revenue is down {revenue_delta}, driven by a {orders_delta} drop in orders.'),
				'down|down|down' => Craft::t('best-sellers', 'Revenue is down {revenue_delta}, with both order volume ({orders_delta}) and AOV ({aov_delta}) declining.'),
				'down|down|up' => Craft::t('best-sellers', 'Revenue is down {revenue_delta} on a {orders_delta} drop in orders, though AOV rose {aov_delta}.'),
				'down|down|slightly_up' => Craft::t('best-sellers', 'Revenue is down {revenue_delta} on fewer orders ({orders_delta}), with AOV only slightly higher.'),
				'down|down|slightly_down' => Craft::t('best-sellers', 'Revenue is down {revenue_delta} on fewer orders ({orders_delta}), with AOV also slipping.'),
				'down|flat|down' => Craft::t('best-sellers', 'Revenue is down {revenue_delta}, driven by lower AOV ({aov_delta}).'),
				'down|up|down' => Craft::t('best-sellers', 'Revenue is down {revenue_delta} despite more orders ({orders_delta}), as AOV dropped {aov_delta}.'),
				'down|slightly_down|down' => Craft::t('best-sellers', 'Revenue is down {revenue_delta}, with both orders and AOV declining.'),
				'down|slightly_down|slightly_down' => Craft::t('best-sellers', 'Revenue is down {revenue_delta}, with both orders and AOV slipping.'),
			],
			'customers' => [
				'up|up|flat' => Craft::t('best-sellers', 'Customer base is growing, with {new_customers_delta} more new customers.'),
				'up|up|up' => Craft::t('best-sellers', 'Customer base is growing ({customers_delta} more buyers), with {new_customers_delta} more new customers and a rising repeat rate ({repeat_rate_value}).'),
				'up|up|down' => Craft::t('best-sellers', 'More customers ordered ({customers_delta}), driven by {new_customers_delta} more new buyers, though repeat rate dipped to {repeat_rate_value}.'),
				'up|up|slightly_down' => Craft::t('best-sellers', 'Customer base is growing ({customers_delta}), with {new_customers_delta} more new buyers. Repeat rate slipped slightly.'),
				'up|up|slightly_up' => Craft::t('best-sellers', 'Customer base is growing ({customers_delta}), with {new_customers_delta} more new buyers and a slightly improving repeat rate.'),
				'up|flat|up' => Craft::t('best-sellers', '{customers_delta} more customers ordered, with an increasing share of repeat buyers ({repeat_rate_value}).'),
				'up|down|up' => Craft::t('best-sellers', '{customers_delta} more customers ordered despite fewer new buyers, as repeat rate climbed to {repeat_rate_value}.'),
				'up|slightly_up|flat' => Craft::t('best-sellers', 'Customer count is up {customers_delta}, with a slight uptick in new buyer acquisition.'),
				'flat|flat|flat' => Craft::t('best-sellers', 'Customer activity is holding steady.'),
				'flat|up|down' => Craft::t('best-sellers', 'Customer count is stable with more new buyers, but repeat rate dropped to {repeat_rate_value}.'),
				'flat|down|up' => Craft::t('best-sellers', 'Customer count is stable despite fewer new buyers, as repeat rate climbed to {repeat_rate_value}.'),
				'slightly_down|slightly_down|flat' => Craft::t('best-sellers', 'Customer activity dipped slightly ({customers_delta}), with marginally fewer new buyers.'),
				'slightly_down|down|up' => Craft::t('best-sellers', 'Fewer customers overall ({customers_delta}), but repeat rate improved to {repeat_rate_value}.'),
				'down|down|flat' => Craft::t('best-sellers', 'Customer activity declined, with {new_customers_delta} fewer new buyers.'),
				'down|down|up' => Craft::t('best-sellers', 'Fewer customers ordered ({customers_delta}), but those who did are increasingly repeat buyers (repeat rate up to {repeat_rate_value}).'),
				'down|down|down' => Craft::t('best-sellers', 'Customer activity declined across the board, with {customers_delta} fewer customers and {new_customers_delta} fewer new buyers.'),
				'down|down|slightly_down' => Craft::t('best-sellers', 'Fewer customers ordered ({customers_delta}), with both new buyer acquisition and repeat rate declining.'),
				'down|flat|down' => Craft::t('best-sellers', 'Fewer customers ordered ({customers_delta}), with repeat rate dropping to {repeat_rate_value}.'),
				'down|up|down' => Craft::t('best-sellers', 'Fewer customers overall ({customers_delta}) despite more new buyers, as repeat rate dropped to {repeat_rate_value}.'),
			],
			'products' => [
				'up|up' => Craft::t('best-sellers', 'Product revenue is up {product_revenue_delta}, with {unique_products_delta} more unique products sold.'),
				'up|flat' => Craft::t('best-sellers', 'Product revenue is up {product_revenue_delta} from the same product mix.'),
				'up|down' => Craft::t('best-sellers', 'Product revenue is up {product_revenue_delta} despite fewer unique products selling ({unique_products_delta}).'),
				'up|slightly_up' => Craft::t('best-sellers', 'Product revenue is up {product_revenue_delta}, with a slightly broader product mix.'),
				'up|slightly_down' => Craft::t('best-sellers', 'Product revenue is up {product_revenue_delta} from a slightly narrower product mix.'),
				'flat|flat' => Craft::t('best-sellers', 'Product revenue and product mix are holding steady.'),
				'flat|up' => Craft::t('best-sellers', 'Product revenue is flat, though {unique_products_delta} more unique products sold.'),
				'flat|down' => Craft::t('best-sellers', 'Product revenue is flat despite fewer unique products selling ({unique_products_delta}).'),
				'slightly_up|flat' => Craft::t('best-sellers', 'Product revenue is up slightly ({product_revenue_delta}).'),
				'slightly_down|flat' => Craft::t('best-sellers', 'Product revenue dipped slightly ({product_revenue_delta}).'),
				'down|down' => Craft::t('best-sellers', 'Product revenue is down {product_revenue_delta}, with {unique_products_delta} fewer unique products sold.'),
				'down|flat' => Craft::t('best-sellers', 'Product revenue is down {product_revenue_delta} from roughly the same product mix.'),
				'down|up' => Craft::t('best-sellers', 'Product revenue is down {product_revenue_delta} despite more unique products selling ({unique_products_delta}).'),
				'down|slightly_down' => Craft::t('best-sellers', 'Product revenue is down {product_revenue_delta}, with a slightly narrower product mix.'),
				'slightly_down|down' => Craft::t('best-sellers', 'Product revenue dipped slightly ({product_revenue_delta}), with fewer unique products sold.'),
				'slightly_down|slightly_down' => Craft::t('best-sellers', 'Product revenue dipped slightly ({product_revenue_delta}), with a slightly narrower product mix.'),
			],
			// For abandonment, "down" is good and "up" is bad
			'abandonment' => [
				'down|down' => Craft::t('best-sellers', 'Cart abandonment improved, with the rate dropping {abandonment_rate_delta} and abandoned value down {abandoned_value_delta}.'),
				'down|flat' => Craft::t('best-sellers', 'Cart abandonment rate improved ({abandonment_rate_delta} lower), though abandoned value held steady.'),
				'down|up' => Craft::t('best-sellers', 'Abandonment rate improved ({abandonment_rate_delta} lower), but the value of abandoned carts rose {abandoned_value_delta}.'),
				'flat|flat' => Craft::t('best-sellers', 'Cart abandonment is holding steady at {abandonment_rate_value}.'),
				'flat|up' => Craft::t('best-sellers', 'Cart abandonment rate is steady at {abandonment_rate_value}, but abandoned value rose {abandoned_value_delta}.'),
				'flat|down' => Craft::t('best-sellers', 'Cart abandonment rate is steady at {abandonment_rate_value}, with abandoned value declining.'),
				'up|up' => Craft::t('best-sellers', 'Cart abandonment worsened, with the rate up {abandonment_rate_delta} and abandoned value rising {abandoned_value_delta}.'),
				'up|flat' => Craft::t('best-sellers', 'Cart abandonment rate rose {abandonment_rate_delta}, though abandoned value held steady.'),
				'up|down' => Craft::t('best-sellers', 'Cart abandonment rate rose {abandonment_rate_delta}, but the value of abandoned carts declined.'),
				'slightly_up|slightly_up' => Craft::t('best-sellers', 'Cart abandonment ticked up slightly (rate +{abandonment_rate_delta}).'),
				'slightly_down|slightly_down' => Craft::t('best-sellers', 'Cart abandonment improved slightly (rate -{abandonment_rate_delta}).'),
			],
		];
	}

	/**
	 * @return array<string, string>
	 */
	private static function fallbacks(): array
	{
		return [
			'orders' => Craft::t('best-sellers', 'Revenue changed {revenue_delta} with {orders_count} orders at an average of {aov_value}.'),
			'customers' => Craft::t('best-sellers', '{customers_count} customers ordered, {new_customers_count} of them new, with a {repeat_rate_value} repeat rate.'),
			'products' => Craft::t('best-sellers', 'Product revenue changed {product_revenue_delta} with {unique_products_count} unique products sold.'),
			'abandonment' => Craft::t('best-sellers', 'Cart abandonment rate is {abandonment_rate_value} with {abandoned_value_formatted} in abandoned carts.'),
		];
	}
}
