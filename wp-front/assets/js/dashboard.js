/**
 * Finlyzer — Dashboard Client Controller
 *
 * Implements strict Content-Security-Policy compliance:
 *  - Zero inline event handlers (all event listeners bound via addEventListener)
 *  - Zero unsafe DOM sinks (DOM manipulation handled declaratively via htmx fragments)
 *  - Nonce dynamically injected into every outgoing htmx request via htmx:configRequest
 */
(function () {
	'use strict';

	// dynamically inject WordPress REST nonce into all htmx requests
	document.addEventListener('htmx:configRequest', function (evt) {
		var config = window.Finlyzer || window.FXLI;
		if (config && config.nonce && evt.detail && evt.detail.headers) {
			evt.detail.headers['X-WP-Nonce'] = config.nonce;
		}
	});

	// defense-in-depth: unwrap JSON string literal if WordPress REST API ever serializes HTML
	document.addEventListener('htmx:beforeSwap', function (evt) {
		var response = evt.detail && evt.detail.serverResponse;
		if (typeof response === 'string') {
			var trimmed = response.trim();
			if ((trimmed.startsWith('"') && trimmed.endsWith('"')) || (trimmed.startsWith("'") && trimmed.endsWith("'"))) {
				try {
					var parsed = JSON.parse(trimmed);
					if (typeof parsed === 'string') {
						evt.detail.serverResponse = parsed;
					}
				} catch (e) {
					// response was not JSON encoded string; leave as is
				}
			}
		}
	});

	document.addEventListener('DOMContentLoaded', function () {
		var app = document.getElementById('finlyzer-app') || document.getElementById('fxli-app');
		var config = window.Finlyzer || window.FXLI;

		if (!app || !config || !config.restUrl || !config.nonce) {
			return;
		}

		var buttons = app.querySelectorAll('.finlyzer-range-btn, .fxli-range-btn');
		var summary = document.getElementById('finlyzer-summary') || document.getElementById('fxli-summary');
		var insight = document.getElementById('finlyzer-insight') || document.getElementById('fxli-insight');

		if (!summary || !insight) {
			return;
		}

		// handle timeframe range button toggles
		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				// update active button state
				buttons.forEach(function (b) {
					b.classList.remove('is-active');
				});
				btn.classList.add('is-active');

				var days = btn.getAttribute('data-days') || '30';
				var summaryUrl = config.restUrl + '/summary?days=' + encodeURIComponent(days);
				var insightUrl = config.restUrl + '/insight?days=' + encodeURIComponent(days);

				// update htmx targets and trigger fresh fragment swaps
				summary.setAttribute('hx-get', summaryUrl);
				insight.setAttribute('hx-get', insightUrl);

				if (window.htmx) {
					window.htmx.trigger(summary, 'load');
					window.htmx.trigger(insight, 'load');
				}
			});
		});

		// handle interactive gateway filter tabs for products ledger (CSP compliant event delegation)
		app.addEventListener('click', function (e) {
			var tab = e.target.closest('.finlyzer-gw-tab');
			if (!tab) {
				return;
			}
			var filterBar = tab.closest('.finlyzer-gw-filter-bar');
			if (!filterBar) {
				return;
			}

			var filter = tab.getAttribute('data-gw-filter') || 'all';
			filterBar.querySelectorAll('.finlyzer-gw-tab').forEach(function (btn) {
				btn.classList.remove('finlyzer-gw-tab--active');
			});
			tab.classList.add('finlyzer-gw-tab--active');

			var container = tab.closest('.finlyzer-products-section') || app;
			var rows = container.querySelectorAll('.finlyzer-product-row');
			rows.forEach(function (row) {
				if (filter === 'all' || row.getAttribute('data-gateway') === filter) {
					row.style.display = '';
				} else {
					row.style.display = 'none';
				}
			});
		});

		// handle quick sample order generator button
		var genBtn = document.getElementById('finlyzer-gen-orders-btn');
		if (genBtn) {
			genBtn.addEventListener('click', function () {
				if (genBtn.disabled) {
					return;
				}
				var origHtml = genBtn.innerHTML;
				genBtn.disabled = true;
				genBtn.style.opacity = '0.7';
				genBtn.innerHTML = '<span>⏳ Generating Orders...</span>';

				fetch(config.restUrl + '/generate-sample-orders', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': config.nonce,
					},
					body: JSON.stringify({ count: 25 }),
				})
					.then(function (res) {
						return res.json();
					})
					.then(function (data) {
						genBtn.disabled = false;
						genBtn.style.opacity = '1';
						if (data && data.success) {
							genBtn.innerHTML = '<span>✅ ' + data.created + ' Orders Generated!</span>';
							setTimeout(function () {
								genBtn.innerHTML = origHtml;
							}, 3500);

							// refresh summary and insight fragments via htmx
							if (window.htmx) {
								window.htmx.trigger(summary, 'load');
								window.htmx.trigger(insight, 'load');
							}
						} else {
							genBtn.innerHTML = '<span>❌ ' + (data && data.message ? data.message : 'Error') + '</span>';
							setTimeout(function () {
								genBtn.innerHTML = origHtml;
							}, 3500);
						}
					})
					.catch(function () {
						genBtn.disabled = false;
						genBtn.style.opacity = '1';
						genBtn.innerHTML = '<span>❌ Request Failed</span>';
						setTimeout(function () {
							genBtn.innerHTML = origHtml;
						}, 3500);
					});
			});
		}
	});
})();
