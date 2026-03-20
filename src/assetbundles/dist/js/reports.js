/**
 * Best Sellers Reports - Shared JS
 */
(function () {
	'use strict';

	window.BestSellersReports = {
		/**
		 * Create a sparkline canvas
		 */
		createSparkline: function (canvasId, data, color) {
			var canvas = document.getElementById(canvasId);
			if (!canvas || typeof Chart === 'undefined') return null;

			color = color || 'rgba(0, 115, 170, 0.4)';

			return new Chart(canvas, {
				type: 'line',
				data: {
					labels: data.map(function (_, i) { return i; }),
					datasets: [{
						data: data,
						borderColor: color,
						borderWidth: 1,
						pointRadius: 0,
						fill: true,
						backgroundColor: 'rgba(0, 115, 170, 0.08)',
						tension: 0.4,
					}]
				},
				options: {
					responsive: true,
					maintainAspectRatio: false,
					layout: { padding: 0 },
					plugins: {
						legend: { display: false },
						tooltip: { enabled: false },
					},
					scales: {
						x: {
							display: false,
							offset: false,
							grid: { display: false, drawTicks: false },
							ticks: { display: false, padding: 0 },
						},
						y: {
							display: false,
							beginAtZero: true,
							grid: { display: false, drawTicks: false },
							ticks: { display: false, padding: 0 },
						},
					},
					elements: {
						line: { borderWidth: 1 }
					}
				}
			});
		},

		/**
		 * Determine appropriate time scale for chart
		 */
		getTimeScale: function (labels) {
			if (!labels || labels.length < 2) {
				return { unit: 'day', stepSize: 1 };
			}

			var startDate = new Date(labels[0]);
			var endDate = new Date(labels[labels.length - 1]);
			var diffDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24)) + 1;

			if (diffDays > 365) {
				return { unit: 'month', stepSize: 1 };
			} else if (diffDays > 90) {
				return { unit: 'month', stepSize: 1 };
			} else if (diffDays > 30) {
				return { unit: 'day', stepSize: 7 };
			}
			return { unit: 'day', stepSize: 1 };
		},

		/**
		 * Format number with locale
		 */
		formatNumber: function (num, decimals) {
			decimals = decimals || 0;
			return Number(num).toLocaleString(undefined, {
				minimumFractionDigits: decimals,
				maximumFractionDigits: decimals
			});
		},

		/**
		 * Format currency
		 */
		formatCurrency: function (num) {
			return '$' + this.formatNumber(num, 2);
		}
	};
})();
