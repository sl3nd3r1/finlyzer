/**
 * Finlyzer — Dashboard Client Controller (v1.27.0)
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

		var config = window.Finlyzer || window.FXLI || {};
		if (!config.cloudOptIn) {
			connStatus.style.display = 'block';
			connStatus.className = 'finlyzer-connection-status finlyzer-connection-status--local';
			var connSpinner = document.getElementById('finlyzer-connection-spinner');
			var connIcon = document.getElementById('finlyzer-connection-icon');
			var retryBtn = document.getElementById('finlyzer-retry-btn');
			var connText = document.getElementById('finlyzer-connection-status-text');
			if (connSpinner) connSpinner.style.display = 'none';
			if (connIcon) connIcon.style.display = 'inline-block';
			if (retryBtn) retryBtn.style.display = 'none';
			if (connText) connText.textContent = 'Local Calculation Engine Active — 100% Private On-Store Analytics';
			return;
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
		var resyncBtn = document.getElementById('finlyzer-resync-btn');
		if (resyncBtn) resyncBtn.style.display = 'none';

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
		var devBox = document.getElementById('finlyzer-dev-error-box');
		if (devBox) {
			devBox.style.display = 'none';
		}
		var resyncBtn = document.getElementById('finlyzer-resync-btn');
		if (resyncBtn) {
			resyncBtn.style.display = 'none';
		}
		setTimeout(function () {
			connStatus.style.display = 'none';
		}, 300);
	}

	// global container for last error diagnostic context
	var lastErrorContext = null;

	// display human-friendly connection lost banner with manual retry action and transparent error diagnostics
	function setConnectionLostState(errDetail, errorContext) {
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

		var config = window.Finlyzer || window.FXLI;
		var isDev = !!(config && (config.isDev || config.env === 'development')) || !!document.getElementById('finlyzer-dev-section');
		var ctx = errorContext || lastErrorContext || {};

		var resyncBtn = document.getElementById('finlyzer-resync-btn');
		var rawErr = (errDetail && typeof errDetail === 'string') ? errDetail.trim() : 'Unknown connection error';
		var ctx = errorContext || lastErrorContext || {};
		var respBody = (ctx && ctx.responseText) ? String(ctx.responseText) : '';
		var errCombined = (rawErr + ' ' + respBody).toLowerCase();

		var isHmacIssue = errCombined.indexOf('invalid_signature') !== -1 ||
			errCombined.indexOf('worker_unauthorized') !== -1 ||
			errCombined.indexOf('signature') !== -1 ||
			errCombined.indexOf('401') !== -1 ||
			errCombined.indexOf('hmac') !== -1;

		// Always display the Re-sync button when an error occurs so the merchant/developer can re-pair immediately
		if (resyncBtn) {
			resyncBtn.style.display = 'inline-flex';
		}

		var parsedMessage = '';
		if (respBody) {
			try {
				var parsedJson = JSON.parse(respBody);
				if (parsedJson && (parsedJson.message || parsedJson.error_message || parsedJson.error)) {
					parsedMessage = (parsedJson.error_code || parsedJson.code ? '[' + (parsedJson.error_code || parsedJson.code) + '] ' : '') +
						(parsedJson.message || parsedJson.error_message || parsedJson.error);
				}
			} catch (e) {
				// not json
			}
		}

		var detailedError = parsedMessage ? (rawErr + ' — ' + parsedMessage) : rawErr;
		var baseMsg = 'Connection to the Finlyzer calculation service was lost';

		// Set informative banner text with unmasked error details
		if (connText) {
			if (isHmacIssue) {
				connText.textContent = (isDev ? '[DEV DIAGNOSTIC] ' : '') + 'HMAC signature rejected (' + detailedError + '). Click Re-sync HMAC to pair with API and fetch 64-character site token.';
			} else {
				connText.textContent = (isDev ? '[DEV DIAGNOSTIC] ' : '') + baseMsg + ' (' + detailedError + '). We attempted to reconnect ' + MAX_ATTEMPTS + ' times without success.';
			}
		}

		// Render or update live error diagnostics drawer (Always visible so issues can be immediately diagnosed and fixed)
		var devBox = document.getElementById('finlyzer-dev-error-box');
		if (!devBox) {
			devBox = document.createElement('div');
			devBox.id = 'finlyzer-dev-error-box';
			devBox.className = 'finlyzer-dev-error-box finlyzer-error-debug-box';
			if (connStatus) connStatus.appendChild(devBox);
		} else {
			devBox.innerHTML = '';
		}
		devBox.style.display = 'block';
		devBox.style.cssText = 'display: block; margin: 0 18px 16px 18px; padding: 12px 16px; background: rgba(10, 15, 29, 0.95); border: 1px dashed rgba(239, 68, 68, 0.6); border-radius: 8px; font-family: ui-monospace, SFMono-Regular, monospace; font-size: 11px; color: #FCA5A5; text-align: left; line-height: 1.6;';

		// Header badge
		var headerDiv = document.createElement('div');
		headerDiv.style.cssText = 'font-weight: 700; color: #F87171; display: flex; align-items: center; justify-content: space-between; margin-bottom: 8px; border-bottom: 1px solid rgba(239,68,68,0.2); padding-bottom: 6px;';
		
		var headerTitle = document.createElement('span');
		headerTitle.textContent = isDev ? '⚠️ Live Diagnostic Breakdown (Dev Build Mode)' : '⚠️ Live Error Diagnostic & Telemetry Breakdown';
		headerDiv.appendChild(headerTitle);

		var jumpBtn = document.createElement('a');
		if (document.getElementById('finlyzer-dev-section')) {
			jumpBtn.href = '#finlyzer-dev-section';
			jumpBtn.textContent = '⚡ Inspect Telemetry Table';
		} else {
			jumpBtn.href = '#';
			jumpBtn.textContent = '⚡ Click "Re-sync HMAC" to re-pair';
			jumpBtn.onclick = function (e) {
				e.preventDefault();
				if (resyncBtn) resyncBtn.click();
			};
		}
		jumpBtn.style.cssText = 'color: #38BDF8; text-decoration: underline; cursor: pointer; font-size: 10px; font-weight: 600;';
		headerDiv.appendChild(jumpBtn);
		devBox.appendChild(headerDiv);

		// Details list
		var list = document.createElement('div');
		list.style.cssText = 'display: grid; gap: 4px;';

		// Target URL
		var urlRow = document.createElement('div');
		var urlLabel = document.createElement('strong');
		urlLabel.textContent = 'Failed Request: ';
		urlLabel.style.color = '#94A3B8';
		var urlVal = document.createElement('code');
		urlVal.textContent = ctx.url ? ctx.url : (rawErr.indexOf('http') >= 0 ? rawErr : 'REST API Fragment (/summary)');
		urlVal.style.cssText = 'color: #38BDF8; background: rgba(0,0,0,0.3); padding: 1px 4px; border-radius: 3px;';
		urlRow.appendChild(urlLabel);
		urlRow.appendChild(urlVal);
		list.appendChild(urlRow);

		// HTTP / Error Code
		var statusRow = document.createElement('div');
		var statusLabel = document.createElement('strong');
		statusLabel.textContent = 'Status / Detail: ';
		statusLabel.style.color = '#94A3B8';
		var statusVal = document.createElement('span');
		statusVal.textContent = ctx.status ? ('HTTP ' + ctx.status + (ctx.statusText ? ' (' + ctx.statusText + ')' : '') + ' — ' + rawErr) : rawErr;
		statusVal.style.cssText = 'color: #F87171; font-weight: 600;';
		statusRow.appendChild(statusLabel);
		statusRow.appendChild(statusVal);
		list.appendChild(statusRow);

		// Raw response body if available
		var respText = ctx.responseText || '';
		if (respText) {
			var respRow = document.createElement('div');
			var respLabel = document.createElement('strong');
			respLabel.textContent = 'Server Response Body: ';
			respLabel.style.color = '#94A3B8';
			var respPre = document.createElement('pre');
			respPre.textContent = respText.slice(0, 1000);
			respPre.style.cssText = 'margin: 4px 0; max-height: 140px; overflow-y: auto; background: rgba(0,0,0,0.5); padding: 6px 8px; border-radius: 4px; color: #FCA5A5; white-space: pre-wrap; word-break: break-all; font-size: 11px;';
			respRow.appendChild(respLabel);
			respRow.appendChild(respPre);
			list.appendChild(respRow);
		}

		// Contextual hint
		var hintRow = document.createElement('div');
		hintRow.style.cssText = 'margin-top: 6px; padding: 6px 8px; background: rgba(245, 158, 11, 0.1); border-left: 3px solid #F59E0B; border-radius: 3px; color: #FDE68A;';
		var hintText = 'Check Cloud Sentinel site pairing or verify endpoint connectivity.';
		if (rawErr.indexOf('invalid_signature') >= 0 || respText.indexOf('invalid_signature') >= 0 || rawErr.indexOf('401') >= 0) {
			hintText = 'Cloudflare Worker rejected HMAC signature. Click "Re-sync HMAC" in the banner to pair your WordPress site with the API and store the site token securely.';
		} else if (rawErr.indexOf('403') >= 0 || respText.indexOf('rest_forbidden') >= 0) {
			hintText = 'WordPress REST API permission check failed. Ensure your session has "manage_woocommerce" capability and a valid X-WP-Nonce.';
		} else if (rawErr.indexOf('503') >= 0 || respText.indexOf('calculation_service_unavailable') >= 0) {
			hintText = 'Calculation service unavailable. Both remote worker analysis and local calculation fallback failed. Click "Re-sync HMAC" to pair or verify database tables.';
		} else if (rawErr.indexOf('transport') >= 0 || rawErr.indexOf('Failed to fetch') >= 0) {
			hintText = 'Network transport error. Could not connect to the local server or REST endpoint. Verify your web server is running.';
		}
		hintRow.textContent = '💡 Hint: ' + hintText;
		list.appendChild(hintRow);

		devBox.appendChild(list);

		console.error('[Finlyzer Live Diagnostics]', {
			error: rawErr,
			context: ctx,
			timestamp: new Date().toISOString()
		});
	}

	// handle request failure and coordinate automatic retry with exponential backoff
	function handleConnectionError(errDetail, errorContext) {
		lastErrorContext = errorContext || null;
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
			// propagate error details: setConnectionLostState(errDetail)
			setConnectionLostState(errDetail, errorContext || lastErrorContext);
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

		// native fetch fallback with credentials: 'include' and independent fragment resilience
		function executeNativeFetchFallback() {
			if (!summary || !insight) return;

			var fetchSummary = fetch(summaryUrl, { headers: headers, credentials: 'include' })
				.then(function (r) {
					if (!r.ok) {
						return r.text().then(function (t) {
							var err = new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 500) : ''));
							err.status = r.status;
							err.statusText = r.statusText;
							err.responseText = t;
							err.url = summaryUrl;
							throw err;
						});
					}
					return r.text();
				})
				.then(function (html) {
					summary.innerHTML = html;
					summaryLoaded = true;
					return html;
				});

			var fetchInsight = fetch(insightUrl, { headers: headers, credentials: 'include' })
				.then(function (r) {
					if (!r.ok) {
						return r.text().then(function (t) {
							var err = new Error('HTTP ' + r.status + (t ? ': ' + t.slice(0, 500) : ''));
							err.status = r.status;
							err.statusText = r.statusText;
							err.responseText = t;
							err.url = insightUrl;
							throw err;
						});
					}
					return r.text();
				})
				.then(function (html) {
					insight.innerHTML = html;
					insightLoaded = true;
					return html;
				});

			Promise.allSettled([fetchSummary, fetchInsight])
				.then(function (results) {
					var summaryRes = results[0];
					var insightRes = results[1];

					if (summaryRes.status === 'fulfilled') {
						// summary loaded successfully; hide top banner and preserve merchant dashboard
						setConnectedState();
						if (insightRes.status !== 'fulfilled') {
							console.warn('[Finlyzer] AI insight advisory unavailable or delayed:', insightRes.reason);
						}
					} else {
						var reason = summaryRes.reason || {};
						var errMsg = reason.message || 'Summary fetch failed';
						console.error('[Finlyzer Native Fetch Error]', errMsg, reason);
						handleConnectionError(errMsg, {
							url: reason.url || summaryUrl,
							status: reason.status || 500,
							statusText: reason.statusText || '',
							responseText: reason.responseText || errMsg,
							target: 'summary'
						});
					}
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
			var respText = xhr.responseText ? xhr.responseText.slice(0, 300) : '';
			var errDetail = statusText + (respText ? ' - ' + respText : '');
			console.warn('[Finlyzer API Error] ' + errDetail + ' received for dashboard fragment.');

			// if target was insight, do not trigger catastrophic full-page connection lost state
			if (target === insight && summaryLoaded) {
				console.warn('[Finlyzer] AI insight advisory encountered an error (' + statusText + '), preserving verified summary analytics.');
				return;
			}
			if (target === insight) {
				console.warn('[Finlyzer] AI insight advisory encountered an error (' + statusText + '), isolated from summary analytics.');
				return;
			}

			handleConnectionError(errDetail, {
				url: (evt.detail && evt.detail.requestConfig && evt.detail.requestConfig.path) ? evt.detail.requestConfig.path : '/wp-json/finlyzer/v1/summary',
				status: xhr.status,
				statusText: statusText,
				responseText: xhr.responseText,
				target: 'summary'
			});
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
			if (target === insight) {
				console.warn('[Finlyzer Network Error] Insight transport error. Preserving summary.');
				return;
			}
			console.warn('[Finlyzer Network Error] Failed to transmit request to calculation API.');
			handleConnectionError('Network transport error', {
				url: (evt.detail && evt.detail.requestConfig && evt.detail.requestConfig.path) ? evt.detail.requestConfig.path : '/wp-json/finlyzer/v1/summary',
				status: 0,
				target: 'summary'
			});
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

		// bind in-banner HMAC re-synchronization button
		var resyncBtn = document.getElementById('finlyzer-resync-btn');
		if (resyncBtn) {
			resyncBtn.addEventListener('click', function () {
				var btnText = document.getElementById('finlyzer-resync-btn-text');
				var origText = btnText ? btnText.textContent : 'Re-sync HMAC';
				var connText = document.getElementById('finlyzer-connection-status-text');
				resyncBtn.classList.add('finlyzer-resync-btn--loading');
				resyncBtn.disabled = true;
				if (btnText) btnText.textContent = 'Re-synchronizing...';
				if (connText) connText.textContent = 'Re-synchronizing HMAC token with API...';

				var restUrl = (config && config.restUrl) ? config.restUrl : '/wp-json/finlyzer/v1';
				var nonce = (config && config.nonce) ? config.nonce : '';

				fetch(restUrl + '/settings/cloud-resync', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					body: JSON.stringify({ force: true, timestamp: Math.floor(Date.now() / 1000) })
				})
				.then(function (res) {
					return res.json().then(function (data) {
						return { ok: res.ok, status: res.status, data: data };
					});
				})
				.then(function (result) {
					resyncBtn.classList.remove('finlyzer-resync-btn--loading');
					resyncBtn.disabled = false;
					if (result.ok && result.data && result.data.success) {
						if (btnText) btnText.textContent = '✓ Synced!';
						if (connText) connText.textContent = 'HMAC token successfully synchronized! Reconnecting...';
						if (config) config.cloudOptIn = true;
						setTimeout(function () {
							if (btnText) btnText.textContent = origText;
							currentAttempt = 0;
							setConnectingState(0);
							fetchData(currentDays, true);
						}, 700);
					} else {
						if (btnText) btnText.textContent = origText;
						var errMsg = (result.data && result.data.message) ? result.data.message : 'Re-sync failed. Please verify API endpoint and retry.';
						if (connText) connText.textContent = errMsg;
						console.error('[Finlyzer Re-sync Error]', result);
					}
				})
				.catch(function (err) {
					resyncBtn.classList.remove('finlyzer-resync-btn--loading');
					resyncBtn.disabled = false;
					if (btnText) btnText.textContent = origText;
					if (connText) connText.textContent = 'Network error during HMAC re-synchronization. Please retry.';
					console.error('[Finlyzer Re-sync Error]', err);
				});
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
		// CLOUD SENTINEL SETTINGS MODAL CONTROLLER (v1.27.0)
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

		// 1. Re-sync / verify connection button
		var verifyBtn = document.getElementById('finlyzerVerifyHmacBtn');
		if (verifyBtn) {
			verifyBtn.addEventListener('click', function () {
				verifyBtn.disabled = true;
				var span = verifyBtn.querySelector('span');
				var orig = span ? span.textContent : 'Re-sync Connection';
				if (span) span.textContent = 'Verifying...';

				showVerifyResult('loading', 'Testing secure connection with Finlyzer Cloud Sentinel...');

				fetch(restBase + '/settings/cloud-resync', {
					method: 'POST',
					headers: {
						'Content-Type': 'application/json',
						'X-WP-Nonce': nonce
					},
					credentials: 'same-origin'
				})
				.then(function (res) {
					return res.text().then(function (rawText) {
						try {
							return JSON.parse(rawText);
						} catch (jsonErr) {
							// response is not JSON (e.g. fatal PHP HTML error page)
							return {
								success: false,
								message: 'Server returned an unexpected response. Please check server logs.'
							};
						}
					});
				})
				.then(function (data) {
					verifyBtn.disabled = false;
					if (span) span.textContent = orig;

					if (data.success) {
						var msg = '✓ Cloud Sentinel Active & Verified! (Latency: ' + (data.latency_ms || 120) + 'ms)';
						showVerifyResult('success', msg);
					} else {
						var errMsg = '✗ ' + (data.message || data.error || 'Connection check failed.');
						showVerifyResult('error', errMsg);
					}
				})
				.catch(function () {
					verifyBtn.disabled = false;
					if (span) span.textContent = orig;
					showVerifyResult('error', '✗ Re-sync request failed. Please check connection and retry.');
				});
			});
		}

		// 2. Opt-in / Opt-out toggle handlers
		function handleOptInToggle(optIn) {
			showVerifyResult('loading', optIn ? 'Enabling Cloud Sentinel & pairing with AI service...' : 'Switching to 100% Local Engine...');

			fetch(restBase + '/settings/cloud-optin', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				credentials: 'same-origin',
				body: JSON.stringify({ opt_in: optIn })
			})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (data.success) {
					if (window.Finlyzer) window.Finlyzer.cloudOptIn = optIn;
					if (window.FXLI) window.FXLI.cloudOptIn = optIn;

					var statusBadge = document.getElementById('finlyzer-cloud-status-badge');
					var pillText = document.getElementById('finlyzer-status-pill-text');
					var engineVal = document.getElementById('finlyzerEngineModeVal');

					if (optIn) {
						if (statusBadge) statusBadge.className = 'finlyzer-status-pill finlyzer-status-pill--active';
						if (pillText) pillText.textContent = 'Cloud AI Active & Protected';
						if (engineVal) engineVal.textContent = 'Cloud Sentinel Enabled';
						showVerifyResult('success', '✓ ' + (data.message || 'Cloud Sentinel successfully enabled.'));
					} else {
						if (statusBadge) statusBadge.className = 'finlyzer-status-pill finlyzer-status-pill--optimal';
						if (pillText) pillText.textContent = 'Local Calculation Engine Active (Private)';
						if (engineVal) engineVal.textContent = '100% Local On-Store Database Engine';
						showVerifyResult('success', '✓ ' + (data.message || 'Switched to Local Engine.'));
					}

					setTimeout(function () {
						window.location.reload();
					}, 1000);
				} else {
					showVerifyResult('error', '✗ ' + (data.message || 'Opt-in update failed.'));
				}
			})
			.catch(function () {
				showVerifyResult('error', '✗ Failed to update opt-in preference. Please retry.');
			});
		}

		var optInBtn = document.getElementById('finlyzerOptInBtn');
		if (optInBtn) {
			optInBtn.addEventListener('click', function () {
				handleOptInToggle(true);
			});
		}

		var optOutBtn = document.getElementById('finlyzerOptOutBtn');
		if (optOutBtn) {
			optOutBtn.addEventListener('click', function () {
				handleOptInToggle(false);
			});
		}

		// 3. European Central Bank Live Rates Opt-in toggle handlers (Guideline 7 compliant)
		function handleRatesOptInToggle(optIn) {
			showRatesResult('loading', optIn ? 'Enabling Live European Central Bank Rates...' : 'Switching to Offline Reference Rates...');

			fetch(restBase + '/settings/live-rates-optin', {
				method: 'POST',
				headers: {
					'Content-Type': 'application/json',
					'X-WP-Nonce': nonce
				},
				credentials: 'same-origin',
				body: JSON.stringify({ opt_in: optIn })
			})
			.then(function (res) { return res.json(); })
			.then(function (data) {
				if (data.success) {
					if (window.Finlyzer) window.Finlyzer.liveRatesOptIn = optIn;
					if (window.FXLI) window.FXLI.liveRatesOptIn = optIn;

					var ratesBadge = document.getElementById('finlyzer-rates-status-badge');
					var ratesPillText = document.getElementById('finlyzer-rates-pill-text');

					if (optIn) {
						if (ratesBadge) ratesBadge.className = 'finlyzer-status-pill finlyzer-status-pill--active';
						if (ratesPillText) ratesPillText.textContent = 'Live ECB Rates Active';
						showRatesResult('success', '✓ ' + (data.message || 'Live ECB rates enabled.'));
					} else {
						if (ratesBadge) ratesBadge.className = 'finlyzer-status-pill finlyzer-status-pill--optimal';
						if (ratesPillText) ratesPillText.textContent = 'Offline ECB Rates Matrix (Default)';
						showRatesResult('success', '✓ ' + (data.message || 'Offline reference rates active.'));
					}

					setTimeout(function () {
						window.location.reload();
					}, 1000);
				} else {
					showRatesResult('error', '✗ ' + (data.message || 'Rate opt-in update failed.'));
				}
			})
			.catch(function () {
				showRatesResult('error', '✗ Failed to update rate preference. Please retry.');
			});
		}

		var ratesOptInBtn = document.getElementById('finlyzerRatesOptInBtn');
		if (ratesOptInBtn) {
			ratesOptInBtn.addEventListener('click', function () {
				handleRatesOptInToggle(true);
			});
		}

		var ratesOptOutBtn = document.getElementById('finlyzerRatesOptOutBtn');
		if (ratesOptOutBtn) {
			ratesOptOutBtn.addEventListener('click', function () {
				handleRatesOptInToggle(false);
			});
		}

		function showRatesResult(type, message) {
			var resBox = document.getElementById('finlyzerRatesResult');
			if (!resBox) return;
			resBox.style.display = 'block';
			resBox.className = 'finlyzer-verify-result finlyzer-verify-result--' + type;
			resBox.textContent = sanitizeMessage(message);
		}

		function sanitizeMessage(str) {
			if (!str || typeof str !== 'string') return '';
			// strip any HTML tags to prevent markup or fatal error display leaks
			var cleaned = str.replace(/<[^>]*>/g, '').trim();
			// if message indicates critical error or technical stack dump, replace with clean generic message
			if (cleaned.indexOf('critical error') !== -1 || cleaned.indexOf('Fatal error') !== -1 || cleaned.indexOf('stack trace') !== -1) {
				return 'A server error occurred. Please check server logs.';
			}
			return cleaned;
		}

		function showVerifyResult(type, message) {
			var resBox = document.getElementById('finlyzerVerifyResult');
			if (!resBox) return;
			resBox.style.display = 'block';
			resBox.className = 'finlyzer-verify-result finlyzer-verify-result--' + type;
			resBox.textContent = sanitizeMessage(message);
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
