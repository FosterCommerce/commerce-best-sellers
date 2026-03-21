/**
 * Best Sellers Reports - Shared JS
 */
(function () {
	'use strict';

	var loadingPhrases = [
		'Rummaging through the archives...',
		'Our warehouse elf is counting boxes...',
		'Dusting off the ledger books...',
		'The data gnome is doing the heavy lifting...',
		'Untangling the spreadsheets...',
		'Our diligent elf is sorting receipts...',
		'Crunching numbers at elf speed...',
		'Fetching your data from the vault...',
		'The data fairy is waving her wand...',
		'Sifting through packing slips...',
	];

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
					labels: data.map(function (_, index) { return index; }),
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
		 * Create a multi-select checkbox dropdown.
		 *
		 * Options:
		 *   container: DOM element with class bs-multiselect
		 *   onChange: function() called when selection changes
		 *
		 * Returns: { getSelected: function() => array of selected values }
		 */
		createMultiSelect: function (options) {
			var container = options.container;
			var btn = container.querySelector('.bs-multiselect__btn');
			var dropdown = container.querySelector('.bs-multiselect__dropdown');
			var checkboxes = dropdown.querySelectorAll('input[type="checkbox"]');
			var labelText = btn.getAttribute('data-label') || btn.textContent.trim();

			function updateLabel() {
				var selected = [];
				checkboxes.forEach(function (checkbox) {
					if (checkbox.checked) {
						selected.push(checkbox.parentElement.textContent.trim());
					}
				});

				btn.innerHTML = '';
				var textNode = document.createTextNode(selected.length > 0 ? labelText : labelText);
				btn.appendChild(textNode);

				if (selected.length > 0) {
					var badge = document.createElement('span');
					badge.className = 'bs-multiselect__badge';
					badge.textContent = selected.length;
					btn.appendChild(badge);
					btn.classList.add('bs-multiselect__btn--active');
				} else {
					btn.classList.remove('bs-multiselect__btn--active');
				}
			}

			btn.addEventListener('click', function (event) {
				event.stopPropagation();
				dropdown.classList.toggle('is-open');
			});

			document.addEventListener('click', function (event) {
				if (!container.contains(event.target)) {
					dropdown.classList.remove('is-open');
				}
			});

			checkboxes.forEach(function (checkbox) {
				checkbox.addEventListener('change', function () {
					updateLabel();
					if (options.onChange) {
						options.onChange();
					}
				});
			});

			return {
				getSelected: function () {
					var selected = [];
					checkboxes.forEach(function (checkbox) {
						if (checkbox.checked) {
							selected.push(checkbox.value);
						}
					});
					return selected;
				},
				refresh: function () {
					updateLabel();
				}
			};
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
		},

		/**
		 * Create an AJAX-loaded table with pagination and loading animation.
		 *
		 * Options:
		 *   loadingEl: DOM element for loading state
		 *   containerEl: DOM element for table container
		 *   tbodyEl: DOM element for table body
		 *   paginationEl: DOM element for pagination controls
		 *   actionUrl: Craft action URL string
		 *   baseParams: object of params always sent (from, to, preset)
		 *   buildRow: function(item) => TR element
		 *   getFilterParams: function() => object of current filter values
		 *   emptyMessage: string shown when no results
		 *   itemLabel: string like 'orders' or 'products'
		 *   perPage: number (default 100)
		 */
		createAjaxTable: function (options) {
			var loadingEl = options.loadingEl;
			var containerEl = options.containerEl;
			var tbodyEl = options.tbodyEl;
			var paginationEl = options.paginationEl;
			var messageEl = loadingEl.querySelector('.bs-loading__message');
			var spinnerEl = loadingEl.querySelector('.bs-loading__spinner');
			var perPage = options.perPage || 100;
			var itemLabel = options.itemLabel || 'items';
			var currentSort = options.defaultSort || '';
			var currentSortDir = options.defaultSortDir || 'desc';
			var currentPage = 1;

			// Persist/restore table state via sessionStorage
			var stateKey = 'bs-table-' + options.actionUrl;

			function readSavedState() {
				try {
					var stored = sessionStorage.getItem(stateKey);
					return stored ? JSON.parse(stored) : null;
				} catch (err) {
					return null;
				}
			}

			function writeState() {
				var state = {
					page: currentPage,
					sort: currentSort,
					sortDir: currentSortDir
				};
				if (options.getFilterParams) {
					state.filters = options.getFilterParams();
				}
				try {
					sessionStorage.setItem(stateKey, JSON.stringify(state));
				} catch (err) {
					// sessionStorage may be full or unavailable
				}
			}

			// Apply saved filter state to DOM elements
			function restoreFilters(savedFilters) {
				if (!savedFilters || !options.restoreFilterState) return;
				options.restoreFilterState(savedFilters);
			}

			// Read URL query params for cross-page filter links (e.g. from dashboard)
			var urlFilters = {};
			var urlFilterLabel = '';
			try {
				var urlParams = new URLSearchParams(window.location.search);
				['shippingMethod', 'discountStatus', 'discountName'].forEach(function (key) {
					var val = urlParams.get(key);
					if (val) { urlFilters[key] = val; }
				});
				if (urlFilters.shippingMethod) {
					urlFilterLabel = Craft.t('best-sellers', 'Shipping: {method}', { method: urlFilters.shippingMethod });
				} else if (urlFilters.discountStatus) {
					urlFilterLabel = urlFilters.discountStatus === 'discounted'
						? Craft.t('best-sellers', 'Discounted orders')
						: Craft.t('best-sellers', 'Full-price orders');
				} else if (urlFilters.discountName) {
					urlFilterLabel = Craft.t('best-sellers', 'Discount: {name}', { name: urlFilters.discountName });
				}
			} catch (err) {
				// URLSearchParams not supported or other error
			}

			var savedState = Object.keys(urlFilters).length > 0 ? null : readSavedState();

			// Wire up sortable headers
			var tableEl = tbodyEl.closest('table');
			if (tableEl) {
				var sortHeaders = tableEl.querySelectorAll('th[data-sort]');
				sortHeaders.forEach(function (header) {
					header.style.cursor = 'pointer';
					header.style.userSelect = 'none';
					header.addEventListener('click', function () {
						var sortKey = this.getAttribute('data-sort');
						if (currentSort === sortKey) {
							currentSortDir = currentSortDir === 'asc' ? 'desc' : 'asc';
						} else {
							currentSort = sortKey;
							currentSortDir = 'desc';
						}
						updateSortIndicators();
						loadPage(1);
					});
				});
			}

			function updateSortIndicators() {
				if (!tableEl) return;
				tableEl.querySelectorAll('th[data-sort]').forEach(function (header) {
					var indicator = header.querySelector('.bs-sort-indicator');
					if (!indicator) {
						indicator = document.createElement('span');
						indicator.className = 'bs-sort-indicator';
						header.appendChild(indicator);
					}
					var sortKey = header.getAttribute('data-sort');
					if (sortKey === currentSort) {
						indicator.textContent = currentSortDir === 'asc' ? ' \u25B2' : ' \u25BC';
						header.classList.add('bs-th--sorted');
					} else {
						indicator.textContent = '';
						header.classList.remove('bs-th--sorted');
					}
				});
			}

			function showLoading() {
				var phrase = loadingPhrases[Math.floor(Math.random() * loadingPhrases.length)];
				messageEl.textContent = phrase;
				spinnerEl.style.display = '';
				loadingEl.style.display = 'flex';
				containerEl.style.display = 'none';
			}

			function hideLoading() {
				loadingEl.style.display = 'none';
				containerEl.style.display = 'block';
			}

			function renderData(data) {
				tbodyEl.innerHTML = '';

				var items = data.items || data.orders || [];

				if (items.length === 0) {
					tbodyEl.innerHTML = '<tr><td colspan="20" style="text-align:center; padding:2rem; color:#999;">' +
						(options.emptyMessage || 'No results found.') + '</td></tr>';
				} else {
					items.forEach(function (item) {
						tbodyEl.appendChild(options.buildRow(item));
					});
				}

				// Totals footer row
				var existingTfoot = tableEl ? tableEl.querySelector('tfoot') : null;
				if (existingTfoot) {
					existingTfoot.remove();
				}
				if (data.totals && options.buildTotalsRow && items.length > 0 && tableEl) {
					var tfoot = document.createElement('tfoot');
					tfoot.appendChild(options.buildTotalsRow(data.totals));
					tableEl.appendChild(tfoot);
				}

				// Pagination
				paginationEl.innerHTML = '';
				paginationEl.style.gap = '1rem';
				var totalItems = data.totalItems || data.totalOrders || 0;
				var totalPages = data.totalPages || 1;
				var currentPage = data.currentPage || 1;

				if (totalPages > 1) {
					var rangeStart = (currentPage - 1) * perPage + 1;
					var rangeEnd = Math.min(currentPage * perPage, totalItems);

					var info = document.createElement('span');
					info.className = 'light';
					info.textContent = 'Showing ' + rangeStart + '\u2013' + rangeEnd + ' of ' + totalItems + ' ' + itemLabel;
					paginationEl.appendChild(info);

					var buttons = document.createElement('div');
					buttons.style.display = 'flex';
					buttons.style.gap = '0.5rem';

					if (currentPage > 1) {
						var prevBtn = document.createElement('button');
						prevBtn.className = 'btn';
						prevBtn.textContent = 'Previous';
						prevBtn.addEventListener('click', function () { loadPage(currentPage - 1); });
						buttons.appendChild(prevBtn);
					}
					if (currentPage < totalPages) {
						var nextBtn = document.createElement('button');
						nextBtn.className = 'btn';
						nextBtn.textContent = 'Next';
						nextBtn.addEventListener('click', function () { loadPage(currentPage + 1); });
						buttons.appendChild(nextBtn);
					}
					paginationEl.appendChild(buttons);
				} else if (totalItems > 0) {
					var info = document.createElement('span');
					info.className = 'light';
					info.textContent = totalItems + ' ' + itemLabel;
					paginationEl.appendChild(info);
				}

				// Export CSV button
				if (options.exportUrl && totalItems > 0) {
					var exportBtn = document.createElement('a');
					exportBtn.className = 'btn';
					exportBtn.textContent = 'Export CSV';
					exportBtn.style.marginLeft = 'auto';
					exportBtn.addEventListener('click', function (event) {
						event.preventDefault();
						var exportParams = Object.assign({}, options.baseParams);
						if (options.getFilterParams) {
							Object.assign(exportParams, options.getFilterParams());
						}
						if (currentSort) {
							exportParams.sort = currentSort;
							exportParams.sortDir = currentSortDir;
						}
						window.location.href = Craft.getActionUrl(options.exportUrl, exportParams);
					});
					paginationEl.appendChild(exportBtn);
				}

				hideLoading();
			}

			function loadPage(page) {
				currentPage = page;
				showLoading();

				var params = Object.assign({}, options.baseParams, urlFilters, { page: page });

				if (currentSort) {
					params.sort = currentSort;
					params.sortDir = currentSortDir;
				}

				if (options.getFilterParams) {
					var filterParams = options.getFilterParams();
					Object.assign(params, filterParams);
				}

				var url = Craft.getActionUrl(options.actionUrl, params);

				fetch(url, {
					headers: { 'Accept': 'application/json', 'X-Requested-With': 'XMLHttpRequest' }
				})
				.then(function (response) {
					if (!response.ok) {
						throw new Error('Server returned ' + response.status + ' ' + response.statusText);
					}
					return response.json();
				})
				.then(function (data) { renderData(data); writeState(); })
				.catch(function (error) {
					spinnerEl.style.display = 'none';
					messageEl.textContent = 'Failed to load data: ' + error.message;
					console.error('Best Sellers data load error:', error);
				});
			}

			return {
				loadPage: loadPage,
				reload: function () { loadPage(1); },
				init: function () {
					// Show filter banner for cross-page links
					if (urlFilterLabel && containerEl) {
						var banner = document.createElement('div');
						banner.className = 'bs-filter-banner';
						var labelSpan = document.createElement('span');
						labelSpan.textContent = Craft.t('best-sellers', 'Filtered: {label}', { label: urlFilterLabel });
						banner.appendChild(labelSpan);
						var clearLink = document.createElement('a');
						clearLink.href = window.location.pathname + '?from=' + (options.baseParams.from || '') + '&to=' + (options.baseParams.to || '') + '&preset=' + (options.baseParams.preset || '');
						clearLink.textContent = Craft.t('best-sellers', 'Clear filter');
						clearLink.className = 'bs-filter-banner__clear';
						banner.appendChild(clearLink);
						containerEl.parentNode.insertBefore(banner, containerEl);
					}

					if (savedState) {
						currentSort = savedState.sort || currentSort;
						currentSortDir = savedState.sortDir || currentSortDir;
						if (savedState.filters) {
							restoreFilters(savedState.filters);
						}
						updateSortIndicators();
						loadPage(savedState.page || 1);
					} else {
						updateSortIndicators();
						loadPage(1);
					}
				}
			};
		}
	};
})();
