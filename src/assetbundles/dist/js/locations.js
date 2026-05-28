(function () {
	'use strict';

	const root = document.querySelector('[data-locations-map]');
	if (!root) {
		return;
	}

	const container = root.querySelector('[data-globe]');
	const topoUrl = root.dataset.topoUrl;
	const baseTopoUrl = root.dataset.baseTopoUrl || null;
	const rawData = root.dataset.rows;
	const drillBaseUrl = root.dataset.drillBaseUrl;
	const mode = root.dataset.mode || 'world';
	const matchKey = root.dataset.matchKey || 'numericCode';

	if (!container || !topoUrl || !rawData) {
		return;
	}

	if (typeof d3 === 'undefined' || typeof topojson === 'undefined') {
		console.error('locations: d3 or topojson not loaded');
		return;
	}

	let rowsByKey;
	try {
		const parsed = JSON.parse(rawData);
		rowsByKey = new Map(parsed.map((row) => [normalizeKey(row[matchKey]), row]));
	} catch (error) {
		console.error('locations: failed to parse data', error);
		return;
	}

	const currencyFormatter = new Intl.NumberFormat(undefined, {
		style: 'currency',
		currency: root.dataset.currency || 'USD',
		maximumFractionDigits: 0,
	});

	const size = container.clientWidth || 400;
	const pixelRatio = window.devicePixelRatio || 1;
	container.style.width = size + 'px';
	container.style.height = size + 'px';
	container.style.position = 'relative';

	const canvas = document.createElement('canvas');
	canvas.width = size * pixelRatio;
	canvas.height = size * pixelRatio;
	canvas.style.width = size + 'px';
	canvas.style.height = size + 'px';
	canvas.style.display = 'block';
	canvas.style.borderRadius = '0';
	canvas.style.border = 'none';
	canvas.style.background = 'transparent';
	canvas.style.cursor = 'grab';
	container.innerHTML = '';
	container.appendChild(canvas);

	const tooltip = document.createElement('div');
	tooltip.style.cssText = [
		'position:absolute',
		'pointer-events:none',
		'background:#fff',
		'color:#29333d',
		'border:1px solid #dfe5ec',
		'border-radius:4px',
		'box-shadow:0 4px 14px rgba(20,35,50,0.12)',
		'padding:10px 12px',
		'font:12px/1.45 system-ui,-apple-system,"Helvetica Neue",sans-serif',
		'min-width:180px',
		'transform:translate(12px,12px)',
		'opacity:0',
		'transition:opacity 80ms ease',
		'z-index:5',
	].join(';');
	container.appendChild(tooltip);

	const ctx = canvas.getContext('2d');
	ctx.scale(pixelRatio, pixelRatio);

	const projection = d3.geoOrthographic()
		.scale((size / 2) - 4)
		.translate([size / 2, size / 2])
		.clipAngle(90)
		.precision(0.3);

	const path = d3.geoPath(projection, ctx);

	const topoRequests = [fetch(topoUrl).then((response) => response.json())];
	if (baseTopoUrl) {
		topoRequests.push(fetch(baseTopoUrl).then((response) => response.json()));
	}

	Promise.all(topoRequests)
		.then((datasets) => {
			const primaryFeatures = extractFeatures(datasets[0]);
			const baseFeatures = datasets[1] ? extractFeatures(datasets[1]) : [];

			focusInitial(primaryFeatures);

			const palette = ['#c8defa', '#9dc3f2', '#6ea4e6', '#447fd6', '#2761bf'];
			const regionNoDataColor = '#cedcef';
			const otherCountryColor = '#e2ecf6';
			const sphereColor = '#f0f6fd';

			const revenues = [];
			primaryFeatures.forEach((feature) => {
				const row = matchFeature(feature);
				if (row && row.revenue > 0) {
					revenues.push(Math.log1p(row.revenue));
				}
			});
			const maxLogRevenue = revenues.length > 0 ? Math.max(...revenues) : 0;

			function colorFor(feature) {
				const row = matchFeature(feature);
				if (!row || row.revenue <= 0 || maxLogRevenue === 0) {
					return regionNoDataColor;
				}
				const intensity = Math.log1p(row.revenue) / maxLogRevenue;
				const index = Math.min(palette.length - 1, Math.floor(intensity * palette.length));
				return palette[index];
			}

			function render() {
				ctx.clearRect(0, 0, size, size);

				// Sphere fill (ocean)
				ctx.beginPath();
				path({ type: 'Sphere' });
				ctx.fillStyle = sphereColor;
				ctx.fill();

				// Base features (other countries when drilled)
				baseFeatures.forEach((feature) => {
					ctx.beginPath();
					path(feature);
					ctx.fillStyle = otherCountryColor;
					ctx.fill();
				});

				// Primary features (countries or regions)
				primaryFeatures.forEach((feature) => {
					ctx.beginPath();
					path(feature);
					ctx.fillStyle = colorFor(feature);
					ctx.fill();
				});
			}

			render();

			// Drag to rotate
			const drag = d3.drag()
				.on('start', () => {
					canvas.style.cursor = 'grabbing';
				})
				.on('drag', (event) => {
					const rotation = projection.rotate();
					const sensitivity = 75 / projection.scale();
					projection.rotate([
						rotation[0] + event.dx * sensitivity,
						rotation[1] - event.dy * sensitivity,
						rotation[2],
					]);
					render();
				})
				.on('end', () => {
					canvas.style.cursor = 'grab';
				});
			d3.select(canvas).call(drag);

			// Hover tooltip + click drill
			canvas.addEventListener('mousemove', (event) => {
				const rect = canvas.getBoundingClientRect();
				const x = event.clientX - rect.left;
				const y = event.clientY - rect.top;
				const coords = projection.invert([x, y]);
				if (!coords) {
					hideTooltip();
					return;
				}

				const feature = featureAt(coords, primaryFeatures);
				if (!feature) {
					hideTooltip();
					return;
				}
				showTooltip(feature, x, y);
			});

			canvas.addEventListener('mouseleave', hideTooltip);

			canvas.addEventListener('click', (event) => {
				if (!drillBaseUrl) {
					return;
				}
				const rect = canvas.getBoundingClientRect();
				const x = event.clientX - rect.left;
				const y = event.clientY - rect.top;
				const coords = projection.invert([x, y]);
				if (!coords) {
					return;
				}
				const feature = featureAt(coords, primaryFeatures);
				if (!feature) {
					return;
				}

				const url = new URL(drillBaseUrl, window.location.origin);
				if (mode === 'world') {
					const row = matchFeature(feature);
					if (!row || !row.countryCode) {
						return;
					}
					url.searchParams.set('country', row.countryCode);
				} else if (mode === 'country' || mode === 'region') {
					const activeCountry = root.dataset.activeCountry;
					if (!activeCountry) {
						return;
					}
					const row = matchFeature(feature);
					if (!row || !row.code) {
						return;
					}
					url.searchParams.set('country', activeCountry);
					url.searchParams.set('region', row.code);
				} else {
					return;
				}
				window.location.href = url.toString();
			});

			function showTooltip(feature, x, y) {
				const row = matchFeature(feature);
				const name = (row && row.name) || (feature.properties && feature.properties.name) || '';
				const titleStyle = 'font-size:13px;font-weight:600;color:#0f1419;margin-bottom:6px;';
				const rowStyle = 'display:flex;justify-content:space-between;gap:16px;';
				const labelStyle = 'color:#606d7b;';
				const valueStyle = 'color:#29333d;font-variant-numeric:tabular-nums;';
				const emptyStyle = 'color:#8794a1;font-style:italic;';

				let html;
				if (!row || row.revenue <= 0) {
					html = `<div style="${titleStyle}">${name}</div>
						<div style="${emptyStyle}">No orders</div>`;
				} else {
					const rowHtml = (label, value) => `<div style="${rowStyle}">
						<span style="${labelStyle}">${label}</span>
						<span style="${valueStyle}">${value}</span>
					</div>`;
					html = `<div style="${titleStyle}">${name}</div>
						${rowHtml('Revenue', currencyFormatter.format(row.revenue))}
						${rowHtml('Orders', row.orders.toLocaleString())}
						${rowHtml('Customers', row.customers.toLocaleString())}`;
				}

				tooltip.innerHTML = html;
				tooltip.style.left = x + 'px';
				tooltip.style.top = y + 'px';
				tooltip.style.opacity = '1';

				if (mode !== 'region' && row && (row.countryCode || row.code)) {
					canvas.style.cursor = 'pointer';
				}
			}

			function hideTooltip() {
				tooltip.style.opacity = '0';
				canvas.style.cursor = 'grab';
			}
		})
		.catch((error) => {
			console.error('locations: failed to load topology', error);
		});

	function extractFeatures(data) {
		if (data && data.type === 'Topology' && data.objects) {
			const collection = data.objects.states
				|| data.objects.countries
				|| data.objects.provinces
				|| Object.values(data.objects)[0];
			return topojson.feature(data, collection).features;
		}
		if (data && data.type === 'FeatureCollection' && Array.isArray(data.features)) {
			return data.features;
		}
		return [];
	}

	function matchFeature(feature) {
		if (matchKey === 'numericCode') {
			const numericId = String(feature.id || '').padStart(3, '0');
			return rowsByKey.get(numericId) || null;
		}
		const name = feature.properties && feature.properties.name;
		return name ? rowsByKey.get(normalizeKey(name)) : null;
	}

	function normalizeKey(value) {
		return value == null ? '' : String(value).toLowerCase().trim();
	}

	function featureAt(coords, features) {
		const point = { type: 'Point', coordinates: coords };
		for (let index = 0; index < features.length; index++) {
			if (d3.geoContains(features[index], point.coordinates)) {
				return features[index];
			}
		}
		return null;
	}

	function focusInitial(primaryFeatures) {
		if (mode === 'region') {
			const activeRegionName = (root.dataset.activeRegionName || '').toLowerCase();
			const feature = primaryFeatures.find((candidate) => {
				const name = candidate.properties && candidate.properties.name;
				return name && name.toLowerCase() === activeRegionName;
			});
			if (feature) {
				rotateTo(feature);
				const bounds = d3.geoBounds(feature);
				const span = Math.max(bounds[1][0] - bounds[0][0], bounds[1][1] - bounds[0][1]);
				const baseScale = (size / 2) - 4;
				const zoomFactor = Math.max(2.4, 50 / Math.max(span, 0.5));
				projection.scale(Math.min(size * 6, baseScale * zoomFactor));
			}
			return;
		}

		if (mode === 'country') {
			const activeCountry = root.dataset.activeCountry;
			const preset = countryFocusPoint(activeCountry);
			if (preset) {
				projection.rotate([-preset.lng, -preset.lat, 0]);
				projection.scale(preset.scale);
			}
			return;
		}

		// World: focus on top revenue country if any
		let topRow = null;
		rowsByKey.forEach((row) => {
			if (!topRow || row.revenue > topRow.revenue) {
				topRow = row;
			}
		});
		if (!topRow || matchKey !== 'numericCode') {
			return;
		}
		const feature = primaryFeatures.find((candidate) => {
			const numericId = String(candidate.id || '').padStart(3, '0');
			return numericId === String(topRow.numericCode);
		});
		if (feature) {
			rotateTo(feature);
		}
	}

	function rotateTo(feature) {
		const centroid = d3.geoCentroid(feature);
		projection.rotate([-centroid[0], -centroid[1], 0]);
	}

	function countryFocusPoint(countryCode) {
		const presets = {
			US: { lng: -98, lat: 39, scale: (Math.min(container.clientWidth || 600, 600) / 2) * 1.6 },
			CA: { lng: -106, lat: 56, scale: (Math.min(container.clientWidth || 600, 600) / 2) * 1.6 },
		};
		return presets[countryCode] || null;
	}

	// Client-side pagination for any `.bs-paginated` table on the page.
	document.querySelectorAll('table.bs-paginated').forEach(function (table) {
		const perPage = parseInt(table.dataset.bsPaginate || '10', 10);
		const tbody = table.querySelector('tbody');
		if (!tbody) {
			return;
		}
		const rows = Array.from(tbody.querySelectorAll(':scope > tr'));
		if (rows.length <= perPage) {
			return;
		}
		const totalPages = Math.ceil(rows.length / perPage);
		let page = 1;

		const controls = document.createElement('div');
		controls.className = 'bs-paginator';
		controls.style.cssText = 'display:flex;align-items:center;gap:8px;margin-top:8px;font-size:12px;color:#606d7b;';

		const prev = document.createElement('button');
		prev.type = 'button';
		prev.className = 'btn small';
		prev.textContent = '←';

		const next = document.createElement('button');
		next.type = 'button';
		next.className = 'btn small';
		next.textContent = '→';

		const label = document.createElement('span');

		controls.appendChild(prev);
		controls.appendChild(label);
		controls.appendChild(next);
		table.insertAdjacentElement('afterend', controls);

		function render() {
			const start = (page - 1) * perPage;
			const end = start + perPage;
			rows.forEach(function (row, index) {
				row.style.display = (index >= start && index < end) ? '' : 'none';
			});
			label.textContent = page + ' / ' + totalPages + ' (' + rows.length + ' total)';
			prev.disabled = page <= 1;
			next.disabled = page >= totalPages;
		}

		prev.addEventListener('click', function () {
			if (page > 1) {
				page--;
				render();
			}
		});

		next.addEventListener('click', function () {
			if (page < totalPages) {
				page++;
				render();
			}
		});

		render();
	});

})();
