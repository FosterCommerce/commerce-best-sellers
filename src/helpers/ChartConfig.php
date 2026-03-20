<?php

namespace fostercommerce\bestsellers\helpers;

class ChartConfig
{
	public const COLOR_PRIMARY = '#0073aa';

	public const COLOR_PRIMARY_BG = 'rgba(0, 115, 170, 0.6)';

	public const COLOR_SECONDARY = '#a100ff';

	public const COLOR_SECONDARY_BG = 'rgba(161, 0, 255, 0.15)';

	public const COLOR_SUCCESS = '#27ae60';

	public const COLOR_WARNING = '#f39c12';

	public const COLOR_DANGER = '#e74c3c';

	public const COLOR_GRAY = '#95a5a6';

	public const PALETTE = [
		'#0073aa',
		'#a100ff',
		'#27ae60',
		'#f39c12',
		'#e74c3c',
		'#3498db',
		'#9b59b6',
		'#1abc9c',
		'#e67e22',
		'#2ecc71',
	];

	/**
	 * @param list<float|int|string> $data
	 * @return array<string, mixed>
	 */
	public static function barDefaults(string $label, array $data, int $colorIndex = 0): array
	{
		$color = self::PALETTE[$colorIndex % count(self::PALETTE)];
		return [
			'type' => 'bar',
			'label' => $label,
			'data' => $data,
			'backgroundColor' => self::hexToRgba($color, 0.6),
			'borderColor' => $color,
			'borderWidth' => 1,
		];
	}

	/**
	 * @param list<float|int|string> $data
	 * @return array<string, mixed>
	 */
	public static function lineDefaults(string $label, array $data, int $colorIndex = 1): array
	{
		$color = self::PALETTE[$colorIndex % count(self::PALETTE)];
		return [
			'type' => 'line',
			'label' => $label,
			'data' => $data,
			'fill' => false,
			'borderColor' => $color,
			'backgroundColor' => $color,
			'cubicInterpolationMode' => 'monotone',
			'pointRadius' => count($data) === 1 ? 6 : 2,
			'borderWidth' => 2,
		];
	}

	/**
	 * @param list<float|int|string> $data
	 * @param list<string> $labels
	 * @return array<string, mixed>
	 */
	public static function doughnutDefaults(array $data, array $labels): array
	{
		$colors = array_slice(self::PALETTE, 0, count($data));
		return [
			'type' => 'doughnut',
			'data' => [
				'labels' => $labels,
				'datasets' => [[
					'data' => $data,
					'backgroundColor' => $colors,
				]],
			],
		];
	}

	public static function hexToRgba(string $hex, float $alpha = 1.0): string
	{
		$hex = ltrim($hex, '#');
		$r = hexdec(substr($hex, 0, 2));
		$g = hexdec(substr($hex, 2, 2));
		$b = hexdec(substr($hex, 4, 2));
		return "rgba({$r}, {$g}, {$b}, {$alpha})";
	}
}
