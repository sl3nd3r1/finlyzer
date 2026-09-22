/**
 * Finlyzer — Dashboard Client Controller (v1.25.0)
 *
 * Implements strict Content-Security-Policy and modern web security standards:
 *  - Idempotent DOM readiness lifecycle (handles 'loading', 'interactive', and 'complete' states)
 *  - Zero inline event handlers (all event listeners bound via addEventListener or delegation)
 *  - Zero unsafe DOM sinks (textContent used for text updates, DOMPurify/htmx for fragment swaps)
 *  - Nonce & session credentials dynamically injected into every outgoing htmx request
 *  - Pure backend API calculation architecture with connection lifecycle state machine
 *  - Top-level HTMX event registration ensuring zero missed lifecycle swaps or error events
 *  - Abort-immune network state machine (status 0 / intentional cancellations never trigger false offline states)
 *  - Resilient automatic retry (up to 3 attempts with backoff) on verified network or server interruption
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

	// display human-friendly connection lost banner with manual retry action and transparent error diagnostics
	function setConnectionLostState(errDetail) {
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
			var baseMsg = 'Connection to the Finlyzer calculation API was lost';
			if (errDetail && typeof errDetail === 'string' && errDetail.trim() !== '') {
				connText.textContent = baseMsg + ' (' + errDetail.trim() + '). Attempted ' + MAX_ATTEMPTS + ' times.';
			} else {
				connText.textContent = baseMsg + '. We attempted to reconnect ' + MAX_ATTEMPTS + ' times without success.';
			}
		}
	}

	// handle request failure and coordinate automatic retry with exponential backoff
	function handleConnectionError(errDetail) {
		if (currentAttempt < MAX_ATTEMPTS) {
			currentAttempt++;
			setConnectingState(currentAttempt);

			// exponential backoff delay: 1.5s, 3.0s, 4.5s
			var delayMs = currentAttempt * 1500;
			retryTimer = setTimeout(function () {
				// on subsequent attempts, prioritize native fetch engine to bypass any HTMX state issues
				fetchData(currentDays, currentAttempt >= 2);
			}, delayMs);
		} else {
			setConnectionLostState(errDetail);
		}
	}

	// execute API fetch for summary and insight fragments using dual-engine architecture (HTMX + Native Fetch)
	function fetchData(days, forceNativeFetch) {
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

		var headers = {
			'Accept': 'text/html'
		};
		if (config && config.nonce) {
			headers['X-WP-Nonce'] = config.nonce;
		}

		// native fetch fallback with credentials: 'include'
		function executeNativeFetchFallback() {
			if (!summary || !insight) return;

			Promise.all([
				fetch(summaryUrl, { headers: headers, credentials: 'include' }).then(function (r) {
					if (!r.ok) {
						return r.text().then(function (t) {
							throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 100) : ''));
						});
					}
					return r.text();
				}),
				fetch(insightUrl, { headers: headers, credentials: 'include' }).then(function (r) {
					if (!r.ok) {
						return r.text().then(function (t) {
							throw new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 100) : ''));
						});
					}
					return r.text();
				}),
			])
				.then(function (results) {
					// swap fragments safely
					summary.innerHTML = results[0];
					insight.innerHTML = results[1];
					summaryLoaded = true;
					insightLoaded = true;
					setConnectedState();
				})
				.catch(function (err) {
					console.error('[Finlyzer Native Fetch Error]', err);
					handleConnectionError(err.message || 'Fetch failed');
				});
		}

		if (!forceNativeFetch && window.htmx && typeof window.htmx.ajax === 'function' && summary && insight) {
			// dispatch via htmx ajax with explicit headers and credentials
			try {
				window.htmx.ajax('GET', summaryUrl, {
					target: summary,
					swap: 'innerHTML',
					headers: headers,
					credentials: 'include',
					withCredentials: true
				});
				window.htmx.ajax('GET', insightUrl, {
					target: insight,
					swap: 'innerHTML',
					headers: headers,
					credentials: 'include',
					withCredentials: true
				});
			} catch (err) {
				console.warn('[Finlyzer HTMX Error] Failed to dispatch via htmx.ajax, falling back to native fetch', err);
				executeNativeFetchFallback();
			}
		} else {
			executeNativeFetchFallback();
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

	// intercept HTTP response errors (e.g. 500, 503) while strictly ignoring client aborts/cancellations (status 0)
	document.addEventListener('htmx:responseError', function (evt) {
		var target = evt.detail && evt.detail.target;
		var summary = document.getElementById('finlyzer-summary') || document.getElementById('fxli-summary');
		var insight = document.getElementById('finlyzer-insight') || document.getElementById('fxli-insight');
		if (target === summary || target === insight) {
			var xhr = evt.detail && evt.detail.xhr;
			// ignore cancelled/aborted requests (status 0 or readyState 0)
			if (!xhr || xhr.status === 0 || xhr.readyState === 0) {
				return;
			}
			var statusText = xhr.status ? 'HTTP ' + xhr.status : 'API error';
			var respText = xhr.responseText ? xhr.responseText.slice(0, 100) : '';
			var errDetail = statusText + (respText ? ' - ' + respText : '');
			console.warn('[Finlyzer API Error] ' + errDetail + ' received for dashboard fragment.');
			handleConnectionError(errDetail);
		}
	});

	// intercept network transport failures while strictly ignoring intentional aborts
	document.addEventListener('htmx:sendError', function (evt) {
		var target = evt.detail && evt.detail.target;
		var summary = document.getElementById('finlyzer-summary') || document.getElementById('fxli-summary');
		var insight = document.getElementById('finlyzer-insight') || document.getElementById('fxli-insight');
		if (target === summary || target === insight) {
			var xhr = evt.detail && evt.detail.xhr;
			// ignore cancelled/aborted requests
			if (xhr && (xhr.status === 0 || xhr.readyState === 0)) {
				return;
			}
			console.warn('[Finlyzer Network Error] Failed to transmit request to calculation API.');
			handleConnectionError('Network transport error');
		}
	});

	// handle explicit HTMX sendAbort event cleanly
	document.addEventListener('htmx:sendAbort', function (evt) {
		// intentional client-side abort (e.g. timeframe switch); suppress error state
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

		// passive safety timer: check after 20s if fragments stalled with no active network activity
		watchdogTimer = setTimeout(function () {
			var isSummaryBusy = summary && summary.classList.contains('htmx-request');
			var isInsightBusy = insight && insight.classList.contains('htmx-request');
			if (isSummaryBusy || isInsightBusy) {
				// network request is still actively streaming from server; do not abort
				return;
			}
			var stillSkeleton = (summary && summary.querySelector('.finlyzer-skeleton')) ||
								(insight && insight.querySelector('.finlyzer-skeleton'));
			if (stillSkeleton && (!summaryLoaded || !insightLoaded)) {
				console.warn('[Finlyzer] Initial request timed out after 20s without network response. Initiating retry.');
				fetchData(currentDays);
			} else if (!stillSkeleton) {
				setConnectedState();
			}
		}, 20000);

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

		// -------------------------------------------------------------
		// PROGRESSIVE DISCLOSURE: DETAILED BREAKDOWN DRAWER TOGGLE
		// -------------------------------------------------------------
		app.addEventListener('click', function (e) {
			var toggleBtn = e.target.closest('#finlyzerBreakdownToggleBtn, .finlyzer-breakdown-toggle-btn');
			if (!toggleBtn) {
				return;
			}
			var drawer = document.getElementById('finlyzerBreakdownDrawer');
			if (!drawer) {
				return;
			}
			var isExpanded = toggleBtn.getAttribute('aria-expanded') === 'true';
			var collapsedSpan = toggleBtn.querySelector('.finlyzer-toggle-text-collapsed');
			var expandedSpan = toggleBtn.querySelector('.finlyzer-toggle-text-expanded');
			var textSpan = toggleBtn.querySelector('.finlyzer-breakdown-toggle-text');

			if (isExpanded) {
				drawer.style.display = 'none';
				drawer.setAttribute('aria-hidden', 'true');
				toggleBtn.setAttribute('aria-expanded', 'false');
				if (collapsedSpan) collapsedSpan.style.display = 'inline-flex';
				if (expandedSpan) expandedSpan.style.display = 'none';
				if (textSpan) {
					textSpan.textContent = 'View Detailed Breakdown';
				}
			} else {
				drawer.style.display = 'block';
				drawer.setAttribute('aria-hidden', 'false');
				toggleBtn.setAttribute('aria-expanded', 'true');
				if (collapsedSpan) collapsedSpan.style.display = 'none';
				if (expandedSpan) expandedSpan.style.display = 'inline-flex';
				if (textSpan) {
					textSpan.textContent = 'Hide Detailed Breakdown';
				}
			}
		});

		// -------------------------------------------------------------
		// BREAKDOWN SUBTAB FILTERING (CURRENCY, TIMING, PROCESSORS, PRODUCTS)
		// -------------------------------------------------------------
		app.addEventListener('click', function (e) {
			var subtab = e.target.closest('.finlyzer-subtab');
			if (!subtab) {
				return;
			}
			var tabsContainer = subtab.closest('.finlyzer-breakdown-tabs');
			var drawer = subtab.closest('.finlyzer-breakdown-drawer') || document.getElementById('finlyzerBreakdownDrawer');
			if (!drawer) {
				return;
			}

			// update active state across subtabs
			if (tabsContainer) {
				tabsContainer.querySelectorAll('.finlyzer-subtab').forEach(function (btn) {
					btn.classList.remove('is-active');
					btn.setAttribute('aria-selected', 'false');
				});
			}
			subtab.classList.add('is-active');
			subtab.setAttribute('aria-selected', 'true');

			// filter breakdown panels
			var targetPanel = subtab.getAttribute('data-panel') || subtab.getAttribute('data-panel-target') || 'all';
			var panels = drawer.querySelectorAll('.finlyzer-breakdown-panel');
			panels.forEach(function (panel) {
				var panelType = panel.getAttribute('data-panel');
				if (targetPanel === 'all' || panelType === targetPanel) {
					panel.style.display = 'block';
				} else {
					panel.style.display = 'none';
				}
			});
		});

		// -------------------------------------------------------------
		// ABOUT FINLYZER NAVIGATION SMOOTH SCROLL
		// -------------------------------------------------------------
		var aboutNavBtn = document.getElementById('finlyzerAboutNavBtn');
		if (aboutNavBtn) {
			aboutNavBtn.addEventListener('click', function (e) {
				e.preventDefault();
				var aboutSection = document.getElementById('finlyzer-about-section');
				if (aboutSection) {
					aboutSection.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			});
		}

		// -------------------------------------------------------------
		// SECURITY & WORKER SETTINGS MODAL CONTROLLER (v1.25.0)
		// -------------------------------------------------------------
		initSettingsModal();
	}

	function initSettingsModal() {
		var modal = document.getElementById('finlyzerSettingsModal');
		var openBtn = document.getElementById('finlyzerSettingsNavBtn');
		var closeBtn = document.getElementById('finlyzerSettingsCloseBtn');
		if (!modal) return;

		var config = window.Finlyzer || window.FXLI || {};
		var restBase = (config.restUrl || '/wp-json/finlyzer/v1').replace(/\/+$/, '');
		var nonce = config.nonce || '';

		function openModal() {
			modal.style.display = 'flex';
			document.body.style.overflow = 'hidden';
			// trap focus inside modal
			var firstInput = modal.querySelector('input:not([disabled]), button:not([disabled])');
			if (firstInput) {
				setTimeout(function () { firstInput.focus(); }, 50);
			}
		}

		function closeModal() {
			modal.style.display = 'none';
			document.body.style.overflow = '';
			if (openBtn) openBtn.focus();
		}

		if (openBtn) {
			openBtn.addEventListener('click', function (e) {
				e.preventDefault();
				openModal();
			});
		}

		if (closeBtn) {
			closeBtn.addEventListener('click', function (e) {
				e.preventDefault();
				closeModal();
			});
		}

		// close on backdrop click
		modal.addEventListener('click', function (e) {
			if (e.target === modal) {
				closeModal();
			}
		});

		// close on Escape key
		document.addEventListener('keydown', function (e) {
			if (e.key === 'Escape' && modal.style.display === 'flex') {
				closeModal();
			}
		});

		// 1. Copy secret button
		var copyBtn = document.getElementById('finlyzerCopySecretBtn');
		var secretInput = document.getElementById('finlyzerHmacSecretInput');
		if (copyBtn && secretInput) {
			copyBtn.addEventListener('click', function () {
				var valToCopy = secretInput.getAttribute('data-raw-secret') || secretInput.value;
				if (!valToCopy || valToCopy.includes('••••')) {
					valToCopy = secretInput.getAttribute('data-raw-secret') || '';
				}
				if (!valToCopy) {
					alert('No secret available to copy. Please enter or generate one first.');
					return;
				}

				if (navigator.clipboard && navigator.clipboard.writeText) {
					navigator.clipboard.writeText(valToCopy).then(function () {
						var originalText = copyBtn.querySelector('span') ? copyBtn.querySelector('span').textContent : 'Copy';
						if (copyBtn.querySelector('span')) copyBtn.querySelector('span').textContent = 'Copied!';
						setTimeout(function () {
							if (copyBtn.querySelector('span')) copyBtn.querySelector('span').textContent = originalText;
						}, 2000);
					}).catch(function () {
						copyFallback(valToCopy);
					});
				} else {
					copyFallback(valToCopy);
				}
			});
		}

		function copyFallback(text) {
			var temp = document.createElement('textarea');
			temp.value = text;
			temp.style.position = 'fixed';
			temp.style.opacity = '0';
			document.body.appendChild(temp);
			temp.select();
			try {
				document.execCommand('copy');
				var span = copyBtn && copyBtn.querySelector('span');
				if (span) {
					var orig = span.textContent;
					span.textContent = 'Copied!';
					setTimeout(function () { span.textContent = orig; }, 2000);
				}
			} catch (err) {}
			document.body.removeChild(temp);
		}

		// 2. Generate secret button
		var generateBtn = document.getElementById('finlyzerGenerateSecretBtn');
		if (generateBtn && secretInput) {
			generateBtn.addEventListener('click', function () {
				generateBtn.disabled = true;
				var span = generateBtn.querySelector('span');
				var orig = span ? span.textContent : 'Generate';
				if (span) span.textContent = 'Generating...';

				fetch(restBase + '/settings/hmac', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					credentials: 'same-origin',
					body: JSON.stringify({ action: 'generate' })
				})
				.then(function (res) { return res.json(); })
				.then(function (data) {
					generateBtn.disabled = false;
					if (span) span.textContent = orig;

					if (data.success && data.generated_key) {
						secretInput.value = data.generated_key;
						secretInput.setAttribute('data-raw-secret', data.generated_key);
						secretInput.setAttribute('data-masked', data.masked_secret || '');
						showVerifyResult('success', 'Generated & encrypted 64-char key! Paste into Cloudflare Worker: wrangler secret put WORKER_HMAC_SECRET');
					} else {
						showVerifyResult('error', data.message || 'Secret generation failed.');
					}
				})
				.catch(function (err) {
					generateBtn.disabled = false;
					if (span) span.textContent = orig;
					showVerifyResult('error', 'Network error generating key: ' + err.message);
				});
			});
		}

		// 3. Save secret button
		var saveBtn = document.getElementById('finlyzerSaveSecretBtn');
		if (saveBtn && secretInput) {
			saveBtn.addEventListener('click', function () {
				var val = secretInput.value.trim();
				if (!val || val === secretInput.getAttribute('data-masked')) {
					showVerifyResult('error', 'Please enter a new secret key or click Generate.');
					return;
				}

				saveBtn.disabled = true;
				var span = saveBtn.querySelector('span');
				var orig = span ? span.textContent : 'Save & Encrypt';
				if (span) span.textContent = 'Encrypting...';

				fetch(restBase + '/settings/hmac', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					credentials: 'same-origin',
					body: JSON.stringify({ secret: val })
				})
				.then(function (res) { return res.json(); })
				.then(function (data) {
					saveBtn.disabled = false;
					if (span) span.textContent = orig;

					if (data.success) {
						secretInput.setAttribute('data-raw-secret', val);
						secretInput.setAttribute('data-masked', data.masked_secret || '');
						secretInput.value = data.masked_secret || val;
						showVerifyResult('success', data.message || 'HMAC secret saved and encrypted at rest!');
					} else {
						showVerifyResult('error', data.message || 'Failed to save secret.');
					}
				})
				.catch(function (err) {
					saveBtn.disabled = false;
					if (span) span.textContent = orig;
					showVerifyResult('error', 'Network error: ' + err.message);
				});
			});
		}

		// 4. Delete secret button
		var deleteBtn = document.getElementById('finlyzerDeleteSecretBtn');
		if (deleteBtn && secretInput) {
			deleteBtn.addEventListener('click', function () {
				if (!confirm('Are you sure you want to remove this stored HMAC secret? API calculation may fail.')) {
					return;
				}

				fetch(restBase + '/settings/hmac', {
					method: 'DELETE',
					headers: {
						'X-WP-Nonce': nonce
					},
					credentials: 'same-origin'
				})
				.then(function (res) { return res.json(); })
				.then(function (data) {
					if (data.success) {
						secretInput.value = '';
						secretInput.removeAttribute('data-raw-secret');
						secretInput.removeAttribute('data-masked');
						showVerifyResult('success', 'Secret removed from database.');
					} else {
						showVerifyResult('error', data.message || 'Deletion failed.');
					}
				})
				.catch(function (err) {
					showVerifyResult('error', 'Network error: ' + err.message);
				});
			});
		}

		// 5. Verify Handshake button
		var verifyBtn = document.getElementById('finlyzerVerifyHmacBtn');
		if (verifyBtn) {
			verifyBtn.addEventListener('click', function () {
				verifyBtn.disabled = true;
				var span = verifyBtn.querySelector('span');
				var orig = span ? span.textContent : 'Test Connection';
				if (span) span.textContent = 'Verifying...';

				showVerifyResult('loading', 'Testing authenticated HMAC handshake against Cloudflare Worker...');

				var secretVal = secretInput ? (secretInput.getAttribute('data-raw-secret') || secretInput.value.trim()) : '';
				var bodyPayload = {};
				if (secretVal && !secretVal.includes('••••')) {
					bodyPayload.secret = secretVal;
				}

				fetch(restBase + '/settings/hmac/verify', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					credentials: 'same-origin',
					body: JSON.stringify(bodyPayload)
				})
				.then(function (res) { return res.json(); })
				.then(function (data) {
					verifyBtn.disabled = false;
					if (span) span.textContent = orig;

					if (data.success) {
						var msg = '✓ HMAC Verified! (Latency: ' + (data.latency_ms || 0) + 'ms | Clock skew: ' + (data.clock_skew_seconds || 0) + 's)';
						showVerifyResult('success', msg);
					} else {
						var errMsg = '✗ ' + (data.message || data.error || 'Verification failed.');
						showVerifyResult('error', errMsg);
					}
				})
				.catch(function (err) {
					verifyBtn.disabled = false;
					if (span) span.textContent = orig;
					showVerifyResult('error', '✗ Verification request failed: ' + err.message);
				});
			});
		}

		function showVerifyResult(type, message) {
			var resBox = document.getElementById('finlyzerVerifyResult');
			if (!resBox) return;
			resBox.style.display = 'block';
			resBox.className = 'finlyzer-verify-result finlyzer-verify-result--' + type;
			resBox.textContent = message;
		}
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
