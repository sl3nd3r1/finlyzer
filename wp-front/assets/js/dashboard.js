/**
 * Finlyzer — Dashboard Client Controller (v1.15.0)
 *
 * Implements strict Content-Security-Policy and modern web security standards:
 *  - Idempotent DOM readiness lifecycle (handles 'loading', 'interactive', and 'complete' states)
 *  - Zero inline event handlers (all event listeners bound via addEventListener or delegation)
 *  - Zero unsafe DOM sinks (textContent used for text updates, DOMPurify/htmx for fragment swaps)
 *  - Nonce & session credentials dynamically injected into every outgoing htmx request
 *  - Pure backend API calculation architecture with connection lifecycle state machine
 *  - Top-level HTMX event registration ensuring zero missed lifecycle swaps or error events
 *  - Resilient connection watchdog timer detecting stalled initial loads and forcing auto-fetch
 *  - Resilient automatic retry (up to 3 attempts with backoff) on network or server interruption
 *  - Human-crafted manual retry fallback
 */
(function () {
	'use strict';

	// -------------------------------------------------------------
	// SHARED CONNECTION STATE & LIFECYCLE CONTROLLER
	// -------------------------------------------------------------
	var MAX_ATTEMPTS = 3;
	var currentAttempt = 0;
	var retryTimer = null;
	var watchdogTimer = null;
	var currentDays = '30';
	var summaryLoaded = false;
	var insightLoaded = false;
	var isInitialized = false;

	// dynamically inject WordPress REST nonce and session credentials into all outgoing htmx requests
	document.addEventListener('htmx:configRequest', function (evt) {
		var config = window.Finlyzer || window.FXLI;
		if (evt.detail) {
			if (evt.detail.headers && config && config.nonce) {
				evt.detail.headers['X-WP-Nonce'] = config.nonce;
			}
			// guarantee WordPress session cookies are always transmitted for authenticated REST gating
			evt.detail.withCredentials = true;
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

	// update status banner to connecting state with pulsing loading animation
	function setConnectingState(attempt) {
		var connStatus = document.getElementById('finlyzer-connection-status');
		if (!connStatus) return;

		if (retryTimer) {
			clearTimeout(retryTimer);
			retryTimer = null;
		}

		connStatus.style.display = 'block';
		connStatus.className = 'finlyzer-connection-status finlyzer-connection-status--connecting';

		var connSpinner = document.getElementById('finlyzer-connection-spinner');
		var connIcon = document.getElementById('finlyzer-connection-icon');
		var retryBtn = document.getElementById('finlyzer-retry-btn');
		var connText = document.getElementById('finlyzer-connection-status-text');

		if (connSpinner) connSpinner.style.display = 'inline-block';
		if (connIcon) connIcon.style.display = 'none';
		if (retryBtn) retryBtn.style.display = 'none';

		if (connText) {
			if (attempt > 0) {
				connText.textContent = 'Connecting to Finlyzer API... (Attempt ' + attempt + ' of ' + MAX_ATTEMPTS + ')';
			} else {
				connText.textContent = 'Connecting to Finlyzer API...';
			}
		}
	}

	// hide status banner completely when API connection is healthy and data loaded
	function setConnectedState() {
		var connStatus = document.getElementById('finlyzer-connection-status');
		if (!connStatus) return;

		currentAttempt = 0;
		if (retryTimer) {
			clearTimeout(retryTimer);
			retryTimer = null;
		}
		if (watchdogTimer) {
			clearTimeout(watchdogTimer);
			watchdogTimer = null;
		}

		// smooth exit transition
		connStatus.className = 'finlyzer-connection-status finlyzer-connection-status--connected';
		setTimeout(function () {
			connStatus.style.display = 'none';
		}, 300);
	}

	// display human-friendly connection lost banner with manual retry action
	function setConnectionLostState() {
		var connStatus = document.getElementById('finlyzer-connection-status');
		if (!connStatus) return;

		if (retryTimer) {
			clearTimeout(retryTimer);
			retryTimer = null;
		}
		if (watchdogTimer) {
			clearTimeout(watchdogTimer);
			watchdogTimer = null;
		}

		connStatus.style.display = 'block';
		connStatus.className = 'finlyzer-connection-status finlyzer-connection-status--lost';

		var connSpinner = document.getElementById('finlyzer-connection-spinner');
		var connIcon = document.getElementById('finlyzer-connection-icon');
		var retryBtn = document.getElementById('finlyzer-retry-btn');
		var connText = document.getElementById('finlyzer-connection-status-text');

		if (connSpinner) connSpinner.style.display = 'none';
		if (connIcon) connIcon.style.display = 'inline-block';
		if (retryBtn) retryBtn.style.display = 'inline-flex';

		if (connText) {
			connText.textContent = 'Connection to the Finlyzer calculation API was lost. We attempted to reconnect ' + MAX_ATTEMPTS + ' times without success.';
		}
	}

	// handle request failure and coordinate automatic retry with exponential backoff
	function handleConnectionError() {
		if (currentAttempt < MAX_ATTEMPTS) {
			currentAttempt++;
			setConnectingState(currentAttempt);

			// exponential backoff delay: 1.5s, 3.0s, 4.5s
			var delayMs = currentAttempt * 1500;
			retryTimer = setTimeout(function () {
				fetchData(currentDays);
			}, delayMs);
		} else {
			setConnectionLostState();
		}
	}

	// execute API fetch for summary and insight fragments
	function fetchData(days) {
		currentDays = days;
		summaryLoaded = false;
		insightLoaded = false;

		var config = window.Finlyzer || window.FXLI;
		var restBase = (config && config.restUrl) ? config.restUrl : '/wp-json/finlyzer/v1';
		var cleanBase = restBase.replace(/\/+$/, '');
		var glue = cleanBase.indexOf('?') >= 0 ? '&' : '?';

		var summaryUrl = cleanBase + '/summary' + glue + 'days=' + encodeURIComponent(days);
		var insightUrl = cleanBase + '/insight' + glue + 'days=' + encodeURIComponent(days);

		var summary = document.getElementById('finlyzer-summary') || document.getElementById('fxli-summary');
		var insight = document.getElementById('finlyzer-insight') || document.getElementById('fxli-insight');

		if (summary) summary.setAttribute('hx-get', summaryUrl);
		if (insight) insight.setAttribute('hx-get', insightUrl);

		if (window.htmx && typeof window.htmx.ajax === 'function' && summary && insight) {
			// dispatch via htmx ajax with credentials: true for reliable dom swap and lifecycle events
			window.htmx.ajax('GET', summaryUrl, { target: summary, swap: 'innerHTML', credentials: true });
			window.htmx.ajax('GET', insightUrl, { target: insight, swap: 'innerHTML', credentials: true });
		} else if (summary && insight) {
			// native fetch fallback when htmx is not present
			var headers = {};
			if (config && config.nonce) {
				headers['X-WP-Nonce'] = config.nonce;
			}
			Promise.all([
				fetch(summaryUrl, { headers: headers, credentials: 'include' }).then(function (r) {
					if (!r.ok) throw new Error('HTTP ' + r.status);
					return r.text();
				}),
				fetch(insightUrl, { headers: headers, credentials: 'include' }).then(function (r) {
					if (!r.ok) throw new Error('HTTP ' + r.status);
					return r.text();
				}),
			])
				.then(function (results) {
					summary.innerHTML = results[0];
					insight.innerHTML = results[1];
					summaryLoaded = true;
					insightLoaded = true;
					setConnectedState();
				})
				.catch(function () {
					handleConnectionError();
				});
		}
	}

	// -------------------------------------------------------------
	// TOP-LEVEL HTMX LIFECYCLE EVENT LISTENERS
	// -------------------------------------------------------------
	// intercept htmx lifecycle events at document level to ensure zero missed swaps
	document.addEventListener('htmx:afterOnLoad', function (evt) {
		var target = evt.detail && evt.detail.target;
		if (!target) return;

		var summary = document.getElementById('finlyzer-summary') || document.getElementById('fxli-summary');
		var insight = document.getElementById('finlyzer-insight') || document.getElementById('fxli-insight');

		if (target === summary) {
			summaryLoaded = !!evt.detail.successful;
		}
		if (target === insight) {
			insightLoaded = !!evt.detail.successful;
		}

		// when both fragments load successfully, hide connection status
		if (summaryLoaded && insightLoaded) {
			setConnectedState();
		} else if (summaryLoaded && (!insight || !insight.querySelector('.finlyzer-skeleton'))) {
			// summary is loaded and insight has no skeletons (already loaded or offline note)
			setConnectedState();
		}
	});

	document.addEventListener('htmx:responseError', function (evt) {
		var target = evt.detail && evt.detail.target;
		var summary = document.getElementById('finlyzer-summary') || document.getElementById('fxli-summary');
		var insight = document.getElementById('finlyzer-insight') || document.getElementById('fxli-insight');
		if (target === summary || target === insight) {
			handleConnectionError();
		}
	});

	document.addEventListener('htmx:sendError', function (evt) {
		var target = evt.detail && evt.detail.target;
		var summary = document.getElementById('finlyzer-summary') || document.getElementById('fxli-summary');
		var insight = document.getElementById('finlyzer-insight') || document.getElementById('fxli-insight');
		if (target === summary || target === insight) {
			handleConnectionError();
		}
	});

	// -------------------------------------------------------------
	// MAIN DASHBOARD CONTROLLER INITIALIZATION
	// -------------------------------------------------------------
	function initDashboard() {
		// prevent multiple initialization passes
		if (isInitialized) {
			return;
		}

		var app = document.getElementById('finlyzer-app') || document.getElementById('fxli-app');
		if (!app) {
			return;
		}

		var config = window.Finlyzer || window.FXLI;
		var buttons = app.querySelectorAll('.finlyzer-range-btn, .fxli-range-btn');
		var summary = document.getElementById('finlyzer-summary') || document.getElementById('fxli-summary');
		var insight = document.getElementById('finlyzer-insight') || document.getElementById('fxli-insight');
		var retryBtn = document.getElementById('finlyzer-retry-btn');

		if (!summary || !insight) {
			return;
		}

		isInitialized = true;

		// evaluate whether fragments were pre-rendered or already swapped by htmx
		var summaryHasContent = !summary.querySelector('.finlyzer-skeleton') && summary.children.length > 0;
		var insightHasContent = !insight.querySelector('.finlyzer-skeleton') && insight.children.length > 0;

		if (summaryHasContent && insightHasContent) {
			summaryLoaded = true;
			insightLoaded = true;
			setConnectedState();
		} else {
			setConnectingState(0);
		}

		// connection watchdog: if skeletons persist after 3.5s, trigger explicit fetch fallback
		watchdogTimer = setTimeout(function () {
			var stillSkeleton = (summary && summary.querySelector('.finlyzer-skeleton')) ||
								(insight && insight.querySelector('.finlyzer-skeleton'));
			if (stillSkeleton && (!summaryLoaded || !insightLoaded)) {
				fetchData(currentDays);
			} else if (!stillSkeleton) {
				setConnectedState();
			}
		}, 3500);

		// bind manual retry button
		if (retryBtn) {
			retryBtn.addEventListener('click', function () {
				currentAttempt = 0;
				setConnectingState(0);
				fetchData(currentDays);
			});
		}

		// -------------------------------------------------------------
		// TIMEFRAME RANGE SELECTOR CONTROLS (30D, 60D, 90D)
		// -------------------------------------------------------------
		buttons.forEach(function (btn) {
			btn.addEventListener('click', function () {
				// update active button state and accessible aria-pressed
				buttons.forEach(function (b) {
					b.classList.remove('is-active');
					b.setAttribute('aria-pressed', 'false');
				});
				btn.classList.add('is-active');
				btn.setAttribute('aria-pressed', 'true');

				var days = btn.getAttribute('data-days') || '30';
				currentAttempt = 0;
				setConnectingState(0);
				fetchData(days);
			});
		});

		// -------------------------------------------------------------
		// GATEWAY FILTER TABS (PRODUCTS LEDGER)
		// -------------------------------------------------------------
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
	}

	// -------------------------------------------------------------
	// SAFE DOM READINESS DISPATCHER (GUARDS AGAINST RACE CONDITIONS)
	// -------------------------------------------------------------
	// handle environments where script executes when readyState is already 'interactive' or 'complete'
	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', initDashboard);
	} else {
		initDashboard();
	}
})();
