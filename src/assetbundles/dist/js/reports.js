/**
 * Best Sellers Reports - Shared JS
 */
(function () {
	'use strict';

	const loadingPhraseKeys = [
		'loading.phrase.archives',
		'loading.phrase.elfBoxes',
		'loading.phrase.ledger',
		'loading.phrase.gnome',
		'loading.phrase.spreadsheets',
		'loading.phrase.elfReceipts',
		'loading.phrase.elfSpeed',
		'loading.phrase.vault',
		'loading.phrase.fairy',
		'loading.phrase.packingSlips',
	];

	window.BestSellersReports = {
		/**
		 * Create a sparkline canvas
		 */
		createSparkline: function (canvasId, data, color) {
			const canvas = document.getElementById(canvasId);
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

			const startDate = new Date(labels[0]);
			const endDate = new Date(labels[labels.length - 1]);
			const diffDays = Math.ceil((endDate - startDate) / (1000 * 60 * 60 * 24)) + 1;

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
			const container = options.container;
			const btn = container.querySelector('.bs-multiselect__btn');
			const dropdown = container.querySelector('.bs-multiselect__dropdown');
			const checkboxes = dropdown.querySelectorAll('input[type="checkbox"]');
			const labelText = btn.getAttribute('data-label') || btn.textContent.trim();

			function updateLabel() {
				const selected = [];
				checkboxes.forEach(function (checkbox) {
					if (checkbox.checked) {
						selected.push(checkbox.parentElement.textContent.trim());
					}
				});

				btn.innerHTML = '';
				const textNode = document.createTextNode(selected.length > 0 ? labelText : labelText);
				btn.appendChild(textNode);

				if (selected.length > 0) {
					const badge = document.createElement('span');
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
					const selected = [];
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
		 * Truncate a string to `len` characters, appending an ellipsis when
		 * truncated. Returns the original string if shorter.
		 */
		truncate: function (str, len) {
			str = String(str == null ? '' : str);
			return str.length > len ? str.substring(0, len) + '…' : str;
		},

		/**
		 * Escape a string for safe inclusion in an HTML attribute value.
		 * Use when concatenating strings into HTML built via innerHTML.
		 */
		escapeAttr: function (str) {
			return String(str == null ? '' : str).replace(/&/g, '&amp;').replace(/"/g, '&quot;');
		},

		/**
		 * Build totals bar content. Returns a DocumentFragment so that the
		 * label and item spans become direct children of the caller-provided
		 * totalsEl (the .bs-totals-bar flex container). A wrapper element
		 * would break the parent's flex gap layout.
		 *
		 *   label: leading bold label (e.g. "All Results Total")
		 *   items: array of { label: string, value: string } pairs
		 */
		buildTotalsBar: function (label, items) {
			const fragment = document.createDocumentFragment();
			const labelEl = document.createElement('span');
			labelEl.className = 'bs-totals-bar__label';
			labelEl.textContent = label;
			fragment.appendChild(labelEl);
			items.forEach(function (item) {
				const itemEl = document.createElement('span');
				itemEl.className = 'bs-totals-bar__item';
				const itemLabel = document.createElement('span');
				itemLabel.className = 'bs-totals-bar__item-label';
				itemLabel.textContent = item.label + ':';
				const itemValue = document.createElement('span');
				itemValue.className = 'bs-totals-bar__item-value';
				itemValue.textContent = item.value;
				itemEl.appendChild(itemLabel);
				itemEl.appendChild(itemValue);
				fragment.appendChild(itemEl);
			});
			return fragment;
		},

		/**
		 * Create an AJAX-loaded table with pagination and loading animation.
		 *
		 * Options:
		 *   loadingEl: DOM element for loading state
		 *   containerEl: DOM element for table container
		 *   tbodyEl: DOM element for table body
		 *   paginationEl: DOM element for pagination controls
		 *   totalsEl: DOM element for the totals bar above the table (optional)
		 *   actionUrl: Craft action URL string
		 *   baseParams: object of params always sent (from, to, preset)
		 *   buildRow: function(item) => TR element
		 *   buildTotalsBar: function(totals) => HTMLElement to put in totalsEl
		 *   buildTotalsRow: function(totals) => TR element (legacy footer; used
		 *     when totalsEl is not provided)
		 *   getFilterParams: function() => object of current filter values
		 *   emptyMessage: string shown when no results
		 *   itemLabel: string like 'orders' or 'products'
		 *   perPage: number (default 100)
		 */
		createAjaxTable: function (options) {
			const loadingEl = options.loadingEl;
			const containerEl = options.containerEl;
			const tbodyEl = options.tbodyEl;
			const paginationEl = options.paginationEl;
			const messageEl = loadingEl.querySelector('.bs-loading__message');
			const spinnerEl = loadingEl.querySelector('.bs-loading__spinner');
			const perPage = options.perPage || 100;
			const itemLabel = options.itemLabel || 'items';
			let currentSort = options.defaultSort || '';
			let currentSortDir = options.defaultSortDir || 'desc';
			let currentPage = 1;

			// Persist/restore table state via sessionStorage
			const stateKey = 'bs-table-' + options.actionUrl;

			function readSavedState() {
				try {
					const stored = sessionStorage.getItem(stateKey);
					return stored ? JSON.parse(stored) : null;
				} catch (err) {
					return null;
				}
			}

			function writeState() {
				const state = {
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
			const urlFilters = {};
			let urlFilterLabel = '';
			try {
				const urlParams = new URLSearchParams(window.location.search);
				['shippingMethod', 'discountStatus', 'discountId', 'itemsPerOrder'].forEach(function (key) {
					const val = urlParams.get(key);
					if (val) { urlFilters[key] = val; }
				});
				if (urlFilters.shippingMethod) {
					urlFilterLabel = Craft.t('best-sellers', 'sales.urlFilter.shipping', { method: urlFilters.shippingMethod });
				} else if (urlFilters.discountStatus) {
					urlFilterLabel = urlFilters.discountStatus === 'discounted'
						? Craft.t('best-sellers', 'sales.urlFilter.discountedOrders')
						: Craft.t('best-sellers', 'sales.urlFilter.fullPriceOrders');
				} else if (urlFilters.discountId) {
					urlFilterLabel = Craft.t('best-sellers', 'sales.urlFilter.discountId', { id: urlFilters.discountId });
				} else if (urlFilters.itemsPerOrder) {
					urlFilterLabel = Craft.t('best-sellers', 'sales.urlFilter.itemsPerOrder', { bucket: urlFilters.itemsPerOrder });
				}
			} catch (err) {
				// URLSearchParams not supported or other error
			}

			const savedState = Object.keys(urlFilters).length > 0 ? null : readSavedState();

			// Wire up sortable headers
			const tableEl = tbodyEl.closest('table');
			if (tableEl) {
				const sortHeaders = tableEl.querySelectorAll('th[data-sort]');
				sortHeaders.forEach(function (header) {
					header.style.cursor = 'pointer';
					header.style.userSelect = 'none';
					header.addEventListener('click', function () {
						const sortKey = this.getAttribute('data-sort');
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
					let indicator = header.querySelector('.bs-sort-indicator');
					if (!indicator) {
						indicator = document.createElement('span');
						indicator.className = 'bs-sort-indicator';
						header.appendChild(indicator);
					}
					const sortKey = header.getAttribute('data-sort');
					if (sortKey === currentSort) {
						indicator.textContent = currentSortDir === 'asc' ? ' ▲' : ' ▼';
						header.classList.add('bs-th--sorted');
					} else {
						indicator.textContent = '';
						header.classList.remove('bs-th--sorted');
					}
				});
			}

			function showLoading() {
				const phraseKey = loadingPhraseKeys[Math.floor(Math.random() * loadingPhraseKeys.length)];
				messageEl.textContent = Craft.t('best-sellers', phraseKey);
				spinnerEl.classList.remove('hidden');
				loadingEl.classList.remove('hidden');
				containerEl.classList.add('hidden');
			}

			function hideLoading() {
				loadingEl.classList.add('hidden');
				containerEl.classList.remove('hidden');
			}

			function renderData(data) {
				tbodyEl.innerHTML = '';

				const items = data.items || data.orders || [];

				if (items.length === 0) {
					tbodyEl.innerHTML = '<tr><td colspan="20" style="text-align:center; padding:2rem; color:#999;">' +
						(options.emptyMessage || 'No results found.') + '</td></tr>';
				} else {
					items.forEach(function (item) {
						tbodyEl.appendChild(options.buildRow(item));
					});
				}

				// Totals - either render into a standalone bar above the table
				// (preferred, set via totalsEl + buildTotalsBar) or as a legacy
				// tfoot row inside the table (buildTotalsRow).
				const existingTfoot = tableEl ? tableEl.querySelector('tfoot') : null;
				if (existingTfoot) {
					existingTfoot.remove();
				}

				if (options.totalsEl) {
					options.totalsEl.innerHTML = '';
					if (data.totals && options.buildTotalsBar && items.length > 0) {
						options.totalsEl.appendChild(options.buildTotalsBar(data.totals));
						options.totalsEl.hidden = false;
					} else {
						options.totalsEl.hidden = true;
					}
				} else if (data.totals && options.buildTotalsRow && items.length > 0 && tableEl) {
					const tfoot = document.createElement('tfoot');
					tfoot.appendChild(options.buildTotalsRow(data.totals));
					tableEl.appendChild(tfoot);
				}

				// Pagination - left-aligned with Prev/Next, range info, and page indicator.
				// Export CSV (rendered below) sticks to the right via marginLeft: auto.
				paginationEl.innerHTML = '';
				paginationEl.style.gap = '1rem';
				paginationEl.style.justifyContent = 'flex-start';
				const totalItems = data.totalItems || data.totalOrders || 0;
				const totalPages = data.totalPages || 1;
				const renderedPage = data.currentPage || 1;

				if (totalItems > 0) {
					const buttons = document.createElement('div');
					buttons.style.display = 'flex';
					buttons.style.gap = '0.5rem';

					const prevBtn = document.createElement('button');
					prevBtn.className = 'btn';
					prevBtn.textContent = Craft.t('best-sellers', 'pagination.previous');
					if (renderedPage > 1) {
						prevBtn.addEventListener('click', function () { loadPage(renderedPage - 1); });
					} else {
						prevBtn.disabled = true;
					}
					buttons.appendChild(prevBtn);

					const nextBtn = document.createElement('button');
					nextBtn.className = 'btn';
					nextBtn.textContent = Craft.t('best-sellers', 'pagination.next');
					if (renderedPage < totalPages) {
						nextBtn.addEventListener('click', function () { loadPage(renderedPage + 1); });
					} else {
						nextBtn.disabled = true;
					}
					buttons.appendChild(nextBtn);
					paginationEl.appendChild(buttons);

					const rangeStart = (renderedPage - 1) * perPage + 1;
					const rangeEnd = Math.min(renderedPage * perPage, totalItems);

					const info = document.createElement('span');
					info.className = 'light';
					info.textContent = Craft.t('best-sellers', 'pagination.showingRange', { start: rangeStart, end: rangeEnd, total: totalItems, label: itemLabel });
					paginationEl.appendChild(info);

					const pageIndicator = document.createElement('span');
					pageIndicator.className = 'light';
					pageIndicator.textContent = Craft.t('best-sellers', 'pagination.pageOf', { current: renderedPage, total: totalPages });
					paginationEl.appendChild(pageIndicator);
				}

				// Export CSV button
				if (options.exportUrl && totalItems > 0) {
					const exportTarget = options.exportContainerEl || paginationEl;
					if (!exportTarget.querySelector('.bs-export-btn')) {
						const exportBtn = document.createElement('a');
						exportBtn.className = 'btn bs-export-btn';
						exportBtn.textContent = Craft.t('best-sellers', 'pagination.exportCsv');
						if (!options.exportContainerEl) {
							exportBtn.style.marginLeft = 'auto';
						}
						exportBtn.addEventListener('click', function (event) {
							event.preventDefault();
							const exportParams = Object.assign({}, options.baseParams);
							if (options.getFilterParams) {
								Object.assign(exportParams, options.getFilterParams());
							}
							if (currentSort) {
								exportParams.sort = currentSort;
								exportParams.sortDir = currentSortDir;
							}
							window.location.href = Craft.getActionUrl(options.exportUrl, exportParams);
						});
						exportTarget.appendChild(exportBtn);
					}
				}

				hideLoading();
			}

			function loadPage(page) {
				currentPage = page;
				showLoading();

				const params = Object.assign({}, options.baseParams, urlFilters, { page: page });

				if (currentSort) {
					params.sort = currentSort;
					params.sortDir = currentSortDir;
				}

				if (options.getFilterParams) {
					const filterParams = options.getFilterParams();
					Object.assign(params, filterParams);
				}

				const url = Craft.getActionUrl(options.actionUrl, params);

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
					spinnerEl.classList.add('hidden');
					messageEl.textContent = Craft.t('best-sellers', 'errors.loadData', { error: error.message });
					console.error('Best Sellers data load error:', error);
				});
			}

			return {
				loadPage: loadPage,
				reload: function () { loadPage(1); },
				init: function () {
					// Show filter banner for cross-page links
					if (urlFilterLabel && containerEl) {
						const banner = document.createElement('div');
						banner.className = 'bs-filter-banner';
						const labelSpan = document.createElement('span');
						labelSpan.textContent = Craft.t('best-sellers', 'chip.filtered', { label: urlFilterLabel });
						banner.appendChild(labelSpan);
						const clearLink = document.createElement('a');
						clearLink.href = window.location.pathname + '?from=' + (options.baseParams.from || '') + '&to=' + (options.baseParams.to || '') + '&preset=' + (options.baseParams.preset || '');
						clearLink.textContent = Craft.t('best-sellers', 'chip.clearFilter');
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
