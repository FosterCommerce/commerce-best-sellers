<?php

namespace fostercommerce\bestsellers\services;

use Craft;
use craft\db\Query;
use DateTime;
use fostercommerce\bestsellers\db\Table;
use fostercommerce\bestsellers\helpers\summary\DeltaCalculator;
use fostercommerce\bestsellers\helpers\summary\RangeLabelBuilder;
use fostercommerce\bestsellers\helpers\summary\SignalClassifier;
use fostercommerce\bestsellers\helpers\summary\TemplateResolver;
use fostercommerce\bestsellers\helpers\summary\WarningGenerator;
use fostercommerce\bestsellers\models\AbandonmentStats;
use fostercommerce\bestsellers\models\DateRangeResult;
use fostercommerce\bestsellers\models\GroupSummary;
use fostercommerce\bestsellers\models\PeriodStats;
use fostercommerce\bestsellers\models\ProductSummary;
use fostercommerce\bestsellers\models\SummaryResult;
use fostercommerce\bestsellers\Plugin;
use yii\base\Component;

class SummaryEngine extends Component
{
	private const MAX_TRAILING_PERIOD_DAYS = 365;

	/**
	 * Generate summaries for all metric groups.
	 */
	public function generate(DateRangeResult $dateRange): SummaryResult
	{
		$plugin = Plugin::getInstance();
		assert($plugin instanceof Plugin);

		$days = RangeLabelBuilder::dayCount($dateRange->from, $dateRange->to);
		$isPartial = RangeLabelBuilder::isPartial($dateRange->preset, $dateRange->to);
		$elapsedDays = $isPartial ? RangeLabelBuilder::elapsedDays($dateRange->from) : $days;
		$rangeLabel = RangeLabelBuilder::rangeLabel($dateRange->preset, $dateRange->from, $dateRange->to);
		$compLabel = RangeLabelBuilder::comparisonLabel($dateRange->preset, $dateRange->from, $dateRange->to, $days);

		$earliestOrderDate = $this->getEarliestOrderDate();
		$yoyDates = $this->getYoyDates($dateRange->from, $dateRange->to);
		$yoyAvailable = $earliestOrderDate !== null && $earliestOrderDate <= $yoyDates['from'];

		$warningContext = [
			'is_partial' => $isPartial,
			'days' => $days,
			'from' => $dateRange->from,
			'to' => $dateRange->to,
			'elapsed_days' => $elapsedDays,
			'earliest_order_date' => $earliestOrderDate,
			'yoy_available' => $yoyAvailable,
			'trailing_chunk_count' => null,
			'trailing_prorated' => false,
			'prev_from' => $dateRange->getPrev()->from,
			'prev_to' => $dateRange->getPrev()->to,
		];

		// Fetch baseline data
		$dailyStats = $plugin->dailyStats;
		$currentStats = $dailyStats->getStatsForRange($dateRange->from, $dateRange->to);
		$prevStats = $dailyStats->getStatsForRange($dateRange->getPrev()->from, $dateRange->getPrev()->to);

		$yoyStats = $yoyAvailable
			? $dailyStats->getStatsForRange($yoyDates['from'], $yoyDates['to'])
			: null;

		$trailing = $this->computeTrailingAverage($dailyStats, $dateRange->from, $days, $isPartial, $elapsedDays, $earliestOrderDate);
		$warningContext['trailing_chunk_count'] = $trailing['chunk_count'] ?? null;
		$warningContext['trailing_prorated'] = $trailing['prorated'];

		$warnings = WarningGenerator::generate($warningContext);

		// -- Orders group --
		$ordersSummary = $this->buildOrdersSummary(
			$currentStats,
			$prevStats,
			$yoyStats,
			$trailing['stats'],
			$rangeLabel,
			$compLabel,
			$warnings,
		);

		// -- Discounts group --
		$operationsStats = $plugin->operationsStats;
		$discountedVsFullPrice = $operationsStats->getDiscountedVsFullPrice($dateRange->fromDT, $dateRange->toDT);
		$prevDiscountedVsFullPrice = $operationsStats->getDiscountedVsFullPrice($dateRange->getPrev()->fromDT, $dateRange->getPrev()->toDT);
		$discountsSummary = $this->buildDiscountsSummary($discountedVsFullPrice, $prevDiscountedVsFullPrice);

		// -- Customers group --
		$customersSummary = $this->buildCustomersSummary(
			$currentStats,
			$prevStats,
			$yoyStats,
			$trailing['stats'],
			$rangeLabel,
			$compLabel,
			$warnings,
		);

		// -- Products group --
		$productStats = $plugin->productStats;
		$currentProductSummary = $productStats->getSummaryStats($dateRange->fromDT, $dateRange->toDT);
		$prevProductSummary = $productStats->getSummaryStats($dateRange->getPrev()->fromDT, $dateRange->getPrev()->toDT);

		$yoyProductSummary = null;
		if ($yoyAvailable) {
			$yoyProductSummary = $productStats->getSummaryStats(
				$yoyDates['from'] . ' 00:00:00',
				$yoyDates['to'] . ' 23:59:59',
			);
		}

		$productsSummary = $this->buildProductsSummary(
			$currentProductSummary,
			$prevProductSummary,
			$yoyProductSummary,
			$rangeLabel,
			$compLabel,
			$warnings,
		);

		// -- Abandonment group --
		$cartAbandonment = $plugin->cartAbandonment;
		$currentAbandonment = $cartAbandonment->getAbandonmentStats($dateRange->fromDT, $dateRange->toDT);
		$prevAbandonment = $cartAbandonment->getAbandonmentStats($dateRange->getPrev()->fromDT, $dateRange->getPrev()->toDT);

		$abandonmentSummary = $this->buildAbandonmentSummary(
			$currentAbandonment,
			$prevAbandonment,
			$rangeLabel,
			$compLabel,
			$warnings,
		);

		return new SummaryResult([
			'orders' => $ordersSummary,
			'discounts' => $discountsSummary,
			'customers' => $customersSummary,
			'products' => $productsSummary,
			'abandonment' => $abandonmentSummary,
		]);
	}

	/**
	 * @param list<string> $warnings
	 */
	private function buildOrdersSummary(
		PeriodStats $current,
		PeriodStats $prev,
		?PeriodStats $yoy,
		?PeriodStats $trailing,
		string $rangeLabel,
		string $compLabel,
		array $warnings,
	): GroupSummary {
		$currentMetrics = $this->extractOrderMetrics($current);
		$prevMetrics = $this->extractOrderMetrics($prev);

		$prevDeltas = DeltaCalculator::computeAll($currentMetrics, $prevMetrics);
		$prevSignals = SignalClassifier::classifyAll($prevDeltas, 'previous_period');

		$signature = TemplateResolver::buildSignature(['revenue', 'orders', 'aov'], $prevSignals);
		$template = TemplateResolver::resolve('orders', $signature);

		$variables = $this->buildOrderVariables($currentMetrics, $prevDeltas);
		$sentence = TemplateResolver::interpolate($template, $variables);
		$mainSentence = $this->prependRangeContext($sentence, $rangeLabel, $compLabel);

		$sentences = [$mainSentence];

		// Baseline annotations as separate sentences
		$annotations = $this->buildBaselineAnnotations(
			'revenue',
			$prevSignals['revenue'],
			$yoy !== null ? $this->extractOrderMetrics($yoy) : null,
			$trailing !== null ? $this->extractOrderMetrics($trailing) : null,
			$currentMetrics,
		);
		$sentences = array_merge($sentences, $annotations);

		return new GroupSummary([
			'sentences' => $sentences,
			'warnings' => $warnings,
		]);
	}

	/**
	 * Build the discounts section summary.
	 *
	 * @param array{discounted: array{orders: int, revenue: float, aov: float}, fullPrice: array{orders: int, revenue: float, aov: float}} $current
	 * @param array{discounted: array{orders: int, revenue: float, aov: float}, fullPrice: array{orders: int, revenue: float, aov: float}} $prev
	 */
	private function buildDiscountsSummary(
		array $current,
		array $prev,
	): GroupSummary {
		$totalOrders = $current['discounted']['orders'] + $current['fullPrice']['orders'];
		$discountedOrders = $current['discounted']['orders'];
		$discountedRevenue = $current['discounted']['revenue'];
		$discountedAov = $current['discounted']['aov'];
		$fullPriceAov = $current['fullPrice']['aov'];

		if ($totalOrders === 0 || $discountedOrders === 0) {
			return new GroupSummary([
				'sentences' => [Craft::t('best-sellers', 'No discounted orders in this period.')],
			]);
		}

		$pctDiscounted = round(($discountedOrders / $totalOrders) * 100, 1);
		$formatter = Craft::$app->getFormatter();

		$formattedPct = $this->markedValue(number_format($pctDiscounted, 1) . '%');
		$formattedRevenue = $this->markedValue($formatter->asCurrency($discountedRevenue));
		$formattedDiscountedAov = $this->markedValue($formatter->asCurrency($discountedAov));
		$formattedFullPriceAov = $this->markedValue($formatter->asCurrency($fullPriceAov));

		// Main sentence: share + revenue + AOV comparison with concrete numbers
		if ($fullPriceAov > 0 && $discountedAov > $fullPriceAov) {
			$sentence = Craft::t('best-sellers', '{pct} of orders used a discount, accounting for {revenue} in revenue. Discounted orders have a {discountedAov} AOV vs. {fullPriceAov} for full-price, suggesting discounts are driving larger buyers.', [
				'pct' => $formattedPct,
				'revenue' => $formattedRevenue,
				'discountedAov' => $formattedDiscountedAov,
				'fullPriceAov' => $formattedFullPriceAov,
			]);
		} elseif ($fullPriceAov > 0 && $discountedAov < $fullPriceAov) {
			$sentence = Craft::t('best-sellers', '{pct} of orders used a discount, accounting for {revenue} in revenue. Discounted orders have a {discountedAov} AOV vs. {fullPriceAov} for full-price.', [
				'pct' => $formattedPct,
				'revenue' => $formattedRevenue,
				'discountedAov' => $formattedDiscountedAov,
				'fullPriceAov' => $formattedFullPriceAov,
			]);
		} else {
			$sentence = Craft::t('best-sellers', '{pct} of orders used a discount, accounting for {revenue} in revenue.', [
				'pct' => $formattedPct,
				'revenue' => $formattedRevenue,
			]);
		}

		$sentences = [$sentence];

		// Context line: share volume and direction vs. prior period
		$prevTotalOrders = $prev['discounted']['orders'] + $prev['fullPrice']['orders'];
		$prevPctDiscounted = $prevTotalOrders > 0
			? round(($prev['discounted']['orders'] / $prevTotalOrders) * 100, 1)
			: 0;

		if ($prevTotalOrders > 0 && $prevPctDiscounted > 0) {
			$diff = $pctDiscounted - $prevPctDiscounted;
			if (abs($diff) >= 1.0) {
				$direction = $diff > 0
					? Craft::t('best-sellers', 'up')
					: Craft::t('best-sellers', 'down');
				$formattedDiff = $this->markedValue(number_format(abs($diff), 1) . 'pp');
				$formattedPrevPct = $this->markedValue(number_format($prevPctDiscounted, 1) . '%');
				$sentences[] = Craft::t('best-sellers', 'Discount share is {direction} {diff} from {prevPct} last period', [
					'direction' => $direction,
					'diff' => $formattedDiff,
					'prevPct' => $formattedPrevPct,
				]);
			}
		}

		// Low/high volume callout
		if ($pctDiscounted < 5) {
			$sentences[] = Craft::t('best-sellers', 'A small share of overall volume');
		} elseif ($pctDiscounted > 50) {
			$sentences[] = Craft::t('best-sellers', 'More than half of orders are discounted');
		}

		return new GroupSummary([
			'sentences' => $sentences,
		]);
	}

	/**
	 * @param list<string> $warnings
	 */
	private function buildCustomersSummary(
		PeriodStats $current,
		PeriodStats $prev,
		?PeriodStats $yoy,
		?PeriodStats $trailing,
		string $rangeLabel,
		string $compLabel,
		array $warnings,
	): GroupSummary {
		$currentMetrics = $this->extractCustomerMetrics($current);
		$prevMetrics = $this->extractCustomerMetrics($prev);

		$prevDeltas = DeltaCalculator::computeAll($currentMetrics, $prevMetrics);
		$prevSignals = SignalClassifier::classifyAll($prevDeltas, 'previous_period');

		$signature = TemplateResolver::buildSignature(['customers', 'new_customers', 'repeat_rate'], $prevSignals);
		$template = TemplateResolver::resolve('customers', $signature);

		$variables = $this->buildCustomerVariables($currentMetrics, $prevDeltas);
		$sentence = TemplateResolver::interpolate($template, $variables);
		$mainSentence = $this->prependRangeContext($sentence, $rangeLabel, $compLabel);

		return new GroupSummary([
			'sentences' => [$mainSentence],
			'warnings' => $warnings,
		]);
	}

	/**
	 * @param list<string> $warnings
	 */
	private function buildProductsSummary(
		ProductSummary $current,
		ProductSummary $prev,
		?ProductSummary $yoy,
		string $rangeLabel,
		string $compLabel,
		array $warnings,
	): GroupSummary {
		$currentMetrics = [
			'product_revenue' => $current->totalProductRevenue,
			'unique_products_sold' => $current->uniqueProducts,
		];
		$prevMetrics = [
			'product_revenue' => $prev->totalProductRevenue,
			'unique_products_sold' => $prev->uniqueProducts,
		];

		$prevDeltas = DeltaCalculator::computeAll($currentMetrics, $prevMetrics);
		$prevSignals = SignalClassifier::classifyAll($prevDeltas, 'previous_period');

		$signature = TemplateResolver::buildSignature(['product_revenue', 'unique_products_sold'], $prevSignals);
		$template = TemplateResolver::resolve('products', $signature);

		$variables = [
			'product_revenue_delta' => $this->markedDelta($prevDeltas['product_revenue']),
			'unique_products_delta' => $this->markedDelta($prevDeltas['unique_products_sold']),
			'unique_products_count' => $this->markedValue((string) $current->uniqueProducts),
		];
		$sentence = TemplateResolver::interpolate($template, $variables);
		$mainSentence = $this->prependRangeContext($sentence, $rangeLabel, $compLabel);

		$sentences = [$mainSentence];

		// YoY annotation for product revenue
		if ($yoy !== null) {
			$yoyMetrics = [
				'product_revenue' => $yoy->totalProductRevenue,
				'unique_products_sold' => $yoy->uniqueProducts,
			];
			$yoyDeltas = DeltaCalculator::computeAll($currentMetrics, $yoyMetrics);
			$yoySignals = SignalClassifier::classifyAll($yoyDeltas, 'same_period_last_year');

			if ($yoySignals['product_revenue'] !== $prevSignals['product_revenue']) {
				$direction = SignalClassifier::directionWord($yoySignals['product_revenue']);
				$coloredPct = $this->markedDelta($yoyDeltas['product_revenue']);
				$sentences[] = Craft::t('best-sellers', 'This period last year: product revenue {direction} {delta}', [
					'direction' => $direction,
					'delta' => $coloredPct,
				]);
			}
		}

		return new GroupSummary([
			'sentences' => $sentences,
			'warnings' => $warnings,
		]);
	}

	/**
	 * @param list<string> $warnings
	 */
	private function buildAbandonmentSummary(
		AbandonmentStats $current,
		AbandonmentStats $prev,
		string $rangeLabel,
		string $compLabel,
		array $warnings,
	): GroupSummary {
		$currentMetrics = [
			'abandonment_rate' => $current->abandonmentRate,
			'abandoned_value' => $current->abandonedValue,
		];
		$prevMetrics = [
			'abandonment_rate' => $prev->abandonmentRate,
			'abandoned_value' => $prev->abandonedValue,
		];

		$prevDeltas = DeltaCalculator::computeAll($currentMetrics, $prevMetrics);
		$prevSignals = SignalClassifier::classifyAll($prevDeltas, 'previous_period');

		$signature = TemplateResolver::buildSignature(['abandonment_rate', 'abandoned_value'], $prevSignals);
		$template = TemplateResolver::resolve('abandonment', $signature);

		$variables = [
			'abandonment_rate_value' => $this->markedValue(number_format($current->abandonmentRate, 1) . '%'),
			'abandonment_rate_delta' => $this->markedDelta($prevDeltas['abandonment_rate']),
			'abandoned_value_delta' => $this->markedDelta($prevDeltas['abandoned_value']),
			'abandoned_value_formatted' => $this->markedValue(Craft::$app->getFormatter()->asCurrency($current->abandonedValue)),
		];
		$sentence = TemplateResolver::interpolate($template, $variables);
		$mainSentence = $this->prependRangeContext($sentence, $rangeLabel, $compLabel);

		return new GroupSummary([
			'sentences' => [$mainSentence],
			'warnings' => $warnings,
		]);
	}

	/**
	 * Build baseline annotations as individual sentences.
	 *
	 * @param array<string, float|int>|null $yoyMetrics
	 * @param array<string, float|int>|null $trailingMetrics
	 * @param array<string, float|int> $currentMetrics
	 * @return list<string>
	 */
	private function buildBaselineAnnotations(
		string $leadMetric,
		string $prevSignal,
		?array $yoyMetrics,
		?array $trailingMetrics,
		array $currentMetrics,
	): array {
		$annotations = [];

		$yoySignal = null;
		$yoyDelta = null;
		if ($yoyMetrics !== null) {
			$yoyDelta = DeltaCalculator::delta($currentMetrics[$leadMetric], $yoyMetrics[$leadMetric]);
			$yoySignal = SignalClassifier::classify($yoyDelta, $leadMetric, 'same_period_last_year');
		}

		$trailingSignal = null;
		$trailingDelta = null;
		if ($trailingMetrics !== null) {
			$trailingDelta = DeltaCalculator::delta($currentMetrics[$leadMetric], $trailingMetrics[$leadMetric]);
			$trailingSignal = SignalClassifier::classify($trailingDelta, $leadMetric, 'trailing_average');
		}

		// All three baselines agree on decline
		if (
			$yoySignal !== null
			&& $trailingSignal !== null
			&& SignalClassifier::isNegative($prevSignal)
			&& SignalClassifier::isNegative($yoySignal)
			&& SignalClassifier::isNegative($trailingSignal)
		) {
			return ['{b}' . Craft::t('best-sellers', 'Decline is consistent across all comparison periods') . '{/b}'];
		}

		// Seasonal detection: prev says up but YoY says flat/slightly_up
		if (
			$yoySignal !== null
			&& SignalClassifier::isPositive($prevSignal)
			&& in_array($yoySignal, [SignalClassifier::FLAT, SignalClassifier::SLIGHTLY_UP], true)
		) {
			$coloredPct = $this->markedDelta($yoyDelta);
			$annotations[] = Craft::t('best-sellers', 'This period last year: roughly flat ({delta}), suggesting seasonal patterns', [
				'delta' => $coloredPct,
			]);
		} elseif ($yoySignal !== null && $yoySignal !== $prevSignal) {
			$direction = SignalClassifier::directionWord($yoySignal);
			$coloredPct = $this->markedDelta($yoyDelta);
			$annotations[] = Craft::t('best-sellers', 'This period last year: {direction} {delta}', [
				'direction' => $direction,
				'delta' => $coloredPct,
			]);
		}

		if ($trailingSignal !== null && $trailingSignal !== $prevSignal) {
			$direction = SignalClassifier::directionWord($trailingSignal);
			$coloredPct = $this->markedDelta($trailingDelta);
			$annotations[] = Craft::t('best-sellers', 'Trailing 12-month avg: {direction} {delta}', [
				'direction' => $direction,
				'delta' => $coloredPct,
			]);
		}

		return $annotations;
	}

	/**
	 * @return array<string, float|int>
	 */
	private function extractOrderMetrics(PeriodStats $stats): array
	{
		return [
			'revenue' => $stats->totalRevenue,
			'orders' => $stats->totalOrders,
			'aov' => $stats->averageOrderValue,
		];
	}

	/**
	 * @return array<string, float|int>
	 */
	private function extractCustomerMetrics(PeriodStats $stats): array
	{
		$repeatRate = $stats->uniqueCustomers > 0
			? round(($stats->returningCustomers / $stats->uniqueCustomers) * 100, 1)
			: 0;

		return [
			'customers' => $stats->uniqueCustomers,
			'new_customers' => $stats->newCustomers,
			'repeat_rate' => $repeatRate,
		];
	}

	/**
	 * @param array<string, float|int> $metrics
	 * @param array<string, float|null> $deltas
	 * @return array<string, string|float|int>
	 */
	private function buildOrderVariables(array $metrics, array $deltas): array
	{
		return [
			'revenue_delta' => $this->markedDelta($deltas['revenue']),
			'orders_delta' => $this->markedDelta($deltas['orders']),
			'aov_delta' => $this->markedDelta($deltas['aov']),
			'orders_count' => $this->markedValue(number_format((int) $metrics['orders'])),
			'aov_value' => $this->markedValue(Craft::$app->getFormatter()->asCurrency($metrics['aov'])),
		];
	}

	/**
	 * @param array<string, float|int> $metrics
	 * @param array<string, float|null> $deltas
	 * @return array<string, string|float|int>
	 */
	private function buildCustomerVariables(array $metrics, array $deltas): array
	{
		return [
			'customers_delta' => $this->markedDelta($deltas['customers']),
			'new_customers_delta' => $this->markedDelta($deltas['new_customers']),
			'repeat_rate_delta' => $this->markedDelta($deltas['repeat_rate']),
			'repeat_rate_value' => $this->markedValue(number_format((float) $metrics['repeat_rate'], 1) . '%'),
			'customers_count' => $this->markedValue(number_format((int) $metrics['customers'])),
			'new_customers_count' => $this->markedValue(number_format((int) $metrics['new_customers'])),
		];
	}

	/**
	 * Prepend the date range context and comparison label to a sentence.
	 */
	private function prependRangeContext(string $sentence, string $rangeLabel, string $compLabel): string
	{
		$sentence = rtrim($sentence, '.');

		return Craft::t('best-sellers', 'Over {range}, {summary} vs. {comparison}.', [
			'range' => $rangeLabel,
			'summary' => lcfirst($sentence),
			'comparison' => $compLabel,
		]);
	}

	/**
	 * Wrap a percentage delta in a bold marker.
	 */
	private function markedDelta(?float $delta): string
	{
		return '{b}' . $this->formatDelta($delta) . '%{/b}';
	}

	/**
	 * Wrap a value in a bold marker.
	 */
	private function markedValue(string $value): string
	{
		return '{b}' . $value . '{/b}';
	}

	/**
	 * Format a delta for display: absolute value, one decimal place.
	 */
	private function formatDelta(?float $delta): string
	{
		if ($delta === null || is_infinite($delta)) {
			return '0.0';
		}

		return number_format(abs($delta), 1);
	}

	/**
	 * Get the earliest order date from the daily stats table.
	 */
	private function getEarliestOrderDate(): ?string
	{
		/** @var string|false $date */
		$date = (new Query())
			->select('MIN([[date]])')
			->from(Table::DAILY_STATS)
			->scalar();

		return $date !== false ? $date : null;
	}

	/**
	 * Calculate same-period-last-year date range.
	 *
	 * @return array{from: string, to: string}
	 */
	private function getYoyDates(string $from, string $to): array
	{
		$fromDT = new DateTime($from);
		$toDT = new DateTime($to);

		$yoyFrom = (clone $fromDT)->modify('-1 year');
		$yoyTo = (clone $toDT)->modify('-1 year');

		// Ensure the YoY range has the same number of days
		$currentDays = (int) $fromDT->diff($toDT)->days;
		$yoyDays = (int) $yoyFrom->diff($yoyTo)->days;

		if ($yoyDays !== $currentDays) {
			$yoyTo = (clone $yoyFrom)->modify('+' . $currentDays . ' days');
		}

		return [
			'from' => $yoyFrom->format('Y-m-d'),
			'to' => $yoyTo->format('Y-m-d'),
		];
	}

	/**
	 * Compute the trailing 12-month average from daily stats.
	 *
	 * Breaks the 365 days before the range into non-overlapping chunks
	 * of the same length as the current range, averages them.
	 *
	 * @return array{stats: ?PeriodStats, chunk_count: ?int, prorated: bool}
	 */
	private function computeTrailingAverage(
		DailyStats $dailyStats,
		string $rangeFrom,
		int $rangeDays,
		bool $isPartial,
		int $elapsedDays,
		?string $earliestOrderDate,
	): array {
		// Skip for very long ranges (trailing average of a 365-day range would need 2 years of data)
		if ($rangeDays >= self::MAX_TRAILING_PERIOD_DAYS) {
			return [
				'stats' => null,
				'chunk_count' => null,
				'prorated' => false,
			];
		}

		$trailingEnd = (new DateTime($rangeFrom))->modify('-1 day');
		$trailingStart = (clone $trailingEnd)->modify('-' . self::MAX_TRAILING_PERIOD_DAYS . ' days');

		// Trim to available data
		if ($earliestOrderDate !== null && $trailingStart->format('Y-m-d') < $earliestOrderDate) {
			$trailingStart = new DateTime($earliestOrderDate);
		}

		// Build chunks working backward from trailingEnd
		$chunks = [];
		$chunkEnd = clone $trailingEnd;

		while ($chunkEnd >= $trailingStart) {
			$chunkStart = (clone $chunkEnd)->modify('-' . ($rangeDays - 1) . ' days');

			if ($chunkStart < $trailingStart) {
				break; // Incomplete chunk, skip
			}

			$chunks[] = [
				'from' => $chunkStart->format('Y-m-d'),
				'to' => $chunkEnd->format('Y-m-d'),
			];

			$chunkEnd = (clone $chunkStart)->modify('-1 day');
		}

		if ($chunks === []) {
			return [
				'stats' => null,
				'chunk_count' => null,
				'prorated' => false,
			];
		}

		// Fetch stats for each chunk and average
		$totals = [
			'totalRevenue' => 0.0,
			'totalOrders' => 0,
			'uniqueCustomers' => 0,
			'newCustomers' => 0,
			'returningCustomers' => 0,
			'totalItemsSold' => 0,
		];

		foreach ($chunks as $chunk) {
			$chunkStats = $dailyStats->getStatsForRange($chunk['from'], $chunk['to']);
			$totals['totalRevenue'] += $chunkStats->totalRevenue;
			$totals['totalOrders'] += $chunkStats->totalOrders;
			$totals['uniqueCustomers'] += $chunkStats->uniqueCustomers;
			$totals['newCustomers'] += $chunkStats->newCustomers;
			$totals['returningCustomers'] += $chunkStats->returningCustomers;
			$totals['totalItemsSold'] += $chunkStats->totalItemsSold;
		}

		$chunkCount = count($chunks);
		$avgRevenue = $totals['totalRevenue'] / $chunkCount;
		$avgOrders = $totals['totalOrders'] / $chunkCount;
		$avgCustomers = $totals['uniqueCustomers'] / $chunkCount;
		$avgNewCustomers = $totals['newCustomers'] / $chunkCount;
		$avgReturning = $totals['returningCustomers'] / $chunkCount;
		$avgItemsSold = $totals['totalItemsSold'] / $chunkCount;

		$prorated = false;

		// Prorate for partial periods
		if ($isPartial && $elapsedDays < $rangeDays) {
			$ratio = $elapsedDays / $rangeDays;
			$avgRevenue *= $ratio;
			$avgOrders *= $ratio;
			$avgCustomers *= $ratio;
			$avgNewCustomers *= $ratio;
			$avgReturning *= $ratio;
			$avgItemsSold *= $ratio;
			$prorated = true;
		}

		$avgStats = new PeriodStats([
			'totalRevenue' => round($avgRevenue, 2),
			'totalOrders' => (int) round($avgOrders),
			'uniqueCustomers' => (int) round($avgCustomers),
			'newCustomers' => (int) round($avgNewCustomers),
			'returningCustomers' => (int) round($avgReturning),
			'totalItemsSold' => (int) round($avgItemsSold),
			'averageOrderValue' => $avgOrders > 0 ? round($avgRevenue / $avgOrders, 2) : 0,
			'averageItemsPerOrder' => $avgOrders > 0 ? round($avgItemsSold / $avgOrders, 2) : 0,
		]);

		return [
			'stats' => $avgStats,
			'chunk_count' => $chunkCount,
			'prorated' => $prorated,
		];
	}
}
