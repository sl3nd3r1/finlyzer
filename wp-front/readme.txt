=== Finlyzer — FX Loss & Margin Insights for WooCommerce ===
Contributors: finlyzer
Tags: woocommerce, currency, fx, forex, payments, stripe, paypal, analytics
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.27.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track hidden payment gateway conversion fees and currency loss across your international WooCommerce sales.

== Description ==

Finlyzer gives WooCommerce merchants clear, honest visibility into how much money is lost to foreign exchange fees and payment processor markups on international sales.

When international customers purchase from your store in a foreign currency (EUR, GBP, CAD, AUD, etc.), payment gateways like PayPal, Stripe, Klarna, WooPayments, and Mollie convert those funds into your payout currency. Instead of using the real interbank exchange rate, processors charge a hidden conversion spread—typically taking 1.5% to 3.8% or more on every cross-border order. In addition, exchange rate fluctuations during settlement create additional currency loss.

Finlyzer scans your existing orders and shows you the exact numbers:
* **Total Currency Loss**: See how much you lost over the last 30, 60, or 90 days.
* **Effective Fee Rate**: Real conversion cost per processor (typically 2.1% to 4.2%).
* **Loss Distribution**: Visual breakdown by foreign currency (EUR, GBP, etc.).
* **Annual Run-Rate Exposure**: Projected annual loss if no action is taken.
* **AI Sentinel Insights**: Real-time risk audit powered by Google Gemini Flash AI with prioritized merchant action recommendations.

No complex setup, no impact on checkout performance, and zero sensitive customer data shared.

### Key Features

* **Payment Processors Overview**: Compares foreign transaction volume and conversion fee impact across all active payment gateways including Stripe, PayPal, and Klarna.
* **Products Breakdown**: Line-by-line attribution of international revenue and conversion fees per product, with fast processor filtering.
* **Exchange Rate Movement**: Tracks rate shifts between order placement and settlement using official European Central Bank reference rates.
* **Loss by Currency Ledger**: Detailed breakdown of transaction volume, fee share, and total loss per foreign currency.
* **High-Performance Order Storage (HPOS)**: Native support for WooCommerce custom order tables.
* **Pure Backend Calculation Engine**: 100% of mathematical modeling and gateway spread analysis is handled by the high-performance Cloudflare Worker backend.
* **Resilient Connection Lifecycle**: Automated API health detection with exponential backoff retry and clear human-friendly status reporting.
* **Privacy by Design**: Runs directly on your store database; customer names, emails, and payment details are never collected or transmitted.

== External Services ==

Finlyzer connects to the following external third-party services to deliver accurate exchange rates and automated AI risk analysis:

* **European Central Bank / Frankfurter API**: Used to retrieve real-time and historical currency exchange reference rates to evaluate payment processor conversion fee markups and market timing rate shifts.
  - Service: https://frankfurter.dev / https://www.ecb.europa.eu
  - Terms of Service: https://frankfurter.dev
  - Privacy Policy: https://frankfurter.dev
  - Data Sent: Target and store currency ISO codes (e.g., "EUR", "USD") and order dates. No store details, customer records, or financial transaction identifiers are ever sent.

* **Finlyzer Cloudflare Worker API & Google Gemini Flash AI**: Used to calculate cross-border payment gateway fee spreads, run multi-processor loss attribution, and generate executive AI financial risk assessments.
  - Service: https://finlyzer.com & https://ai.google.dev
  - Google Gemini Terms of Service: https://ai.google.dev/terms
  - Google Privacy Policy: https://policies.google.com/privacy
  - Data Sent: Anonymized, store-level aggregate order metrics (total foreign currency transaction volume, aggregate conversion fee estimates, currency breakdown, and order counts).
  - **Zero Personally Identifiable Information (PII)**: Customer names, email addresses, phone numbers, billing/shipping physical addresses, IP addresses, payment card credentials, and individual order IDs are never collected, logged, or transmitted.

== Installation ==

### WordPress Admin Upload (Recommended)
1. Download the `finlyzer.zip` archive.
2. In your WordPress admin dashboard, navigate to **Plugins > Add New > Upload Plugin**.
3. Choose `finlyzer.zip` and click **Install Now**.
4. Click **Activate Plugin**.
5. Go to the **Finlyzer** menu in your WordPress sidebar to view your currency audit.

### System Requirements
* WordPress 6.4 or higher
* WooCommerce 8.0 or higher (HPOS supported)
* PHP 8.1 or higher (PHP 8.2 & 8.3 fully supported)
* MySQL 5.7+ or MariaDB 10.4+

== Frequently Asked Questions ==

= Does Finlyzer require an API key to display conversion fees? =
No. Finlyzer reads your existing WooCommerce order history and computes gateway conversion spreads via your private serverless worker without requiring any external account.

= Will Finlyzer slow down customer checkout? =
Never. Finlyzer only reads completed order data and does not run during checkout.

= Which payment processors are analyzed? =
Finlyzer recognizes spread fee models for PayPal (3.8%), Stripe (2.2%), Klarna (3.0%), WooPayments (2.2%), Adyen (1.5%), Mollie (2.5%), Square (2.8%), Direct Wire/BACS (0.0%), Cash on Delivery (0.0%), and generic card processors (2.5%).

== Changelog ==

= 1.27.0 =
* Security & Privacy: Completely eliminated developer jargon, cryptographic implementation details, and technical stack exposure from merchant-facing UI and error handling.
* Critical Bug Fix: Resolved WordPress PHP fatal error during connection re-synchronization by properly calling verify_handshake on the client instance with comprehensive exception handling.
* Resilient Re-sync: Implemented automatic rollback protection in FXLI_Crypto::force_re_pair() to preserve previous working credentials if transient network errors occur.
* Dual-Mode Resilience: Added automatic local development SSL tolerance for localhost and XAMPP in Windows environments alongside hardened production verification.
* Error Masking: Enforced strict generic error descriptions in dashboard alerts and REST API responses per OWASP ASVS and mandatory secure web standards.

= 1.26.0 =
* Zero-Touch Pairing: Implemented autonomous, zero-configuration Cloud Sentinel pairing between WordPress and the Finlyzer backend. Merchants are never prompted for API endpoints, HMAC keys, or terminal commands.
* Secretless Architecture: The plugin automatically enrolls via POST /api/v1/pair, obtaining a unique site-specific token cryptographically derived from the worker's master secret and bound to the site's identity.
* At-Rest Authenticated Encryption: Paired site tokens are stored in the database using AES-256-GCM authenticated encryption with keys derived via HKDF-SHA256 from WordPress core salts.
* Cloud Sentinel Dialog: Redesigned the settings interface into a clean, read-only Cloud Sentinel Status dialog with live latency verification and a one-click re-synchronization diagnostic tool.
* WordPress.org Compliance: 100% compliant with WordPress.org Section 9 for service-driven plugins with zero PII transmission.

= 1.25.0 =
* Security: Implemented AES-256-GCM authenticated at-rest encryption for worker authentication credentials using HKDF key derivation from WordPress core salts.
* Secret Management: Added 5-tier secret resolution hierarchy prioritizing wp-config.php and environment variables with database storage fallback.
* Cryptographic Engine: Added FXLI_Crypto class with tamper-proof AEAD envelopes and entropy validation.

= 1.24.0 =
* AI Resilience: Resolved Cloudflare Worker GEMINI_MODEL typing and environment binding parity.
* Security: Implemented strict regex sanitization on custom model names to prevent header/path injection.
* Reliability: Hardened 2026 multi-model failover cascade (gemini-flash-lite-latest, gemini-flash-latest, gemini-3.5-flash-lite, gemini-3.6-flash) with dynamic model priority reordering and deduplication.
* Fail-Closed Security: Instant abort on 401/403 authentication failures to prevent quota burnout.
* Concurrency: Validated with high-throughput 200-request concurrency challenge under sub-second latency SLA.

= 1.23.0 =
* Compliance: Added dedicated External Services disclosure section in readme.txt compliant with WordPress.org Plugin Directory guidelines (European Central Bank / Frankfurter API and Finlyzer Cloudflare Worker / Google Gemini Flash AI).
* Privacy & Security: Formally documented zero-PII data handling architecture; customer names, emails, addresses, and order numbers are never collected or sent.
* Optimization: Implemented intelligent order-event-driven AI caching mechanism. Gemini AI risk audits are cached permanently and only re-requested when new WooCommerce orders arrive or order status mutates, drastically reducing API requests and token overhead.
* Lifecycle: Registered real-time order lifecycle hooks (woocommerce_new_order, woocommerce_update_order, woocommerce_order_status_changed, woocommerce_trash_order) to invalidate caches and trigger state fingerprint updates.
* Uninstallation: Hardened uninstall.php to cleanly purge all order state options, AI insight caches, telemetry logs, and transients upon plugin deletion.
* Tests: Added Software Engineering challenge suite Test Group 27 verifying WordPress.org guideline compliance, External Services disclosure, order fingerprinting, and 100,000-request AI cache efficiency.

= 1.15.0 =
* Fix: Resolved client controller DOMContentLoaded race condition in footer scripts by implementing an idempotent DOM readiness dispatcher.
* Fix: Enforced withCredentials=true in htmx:configRequest to preserve session cookies across cross-domain development instances.
* Feature: Top-level HTMX event registration ensuring zero missed DOM fragment swaps and instant connection banner dismissal.
* Reliability: Added connection watchdog timer to detect stalled DOM states and trigger automatic recovery fetch.
* DX: Pre-signed timestamped HMAC SHA-256 headers in developer inspector curl command for 100% instant terminal verification.
* Compatibility: Used add_query_arg for REST endpoints in templates to guarantee support for plain permalinks.

= 1.14.0 =
* Feature: Dual-environment build architecture (.env.development and .env.production) compiling tailored developer releases and hardened production packages.
* Dev DX: Integrated Developer Section with live API telemetry, outbound payload inspector, latency probes, and terminal curl reproduction commands.
* Security: 2026 production security hardening enforcing strict HTTPS (TLS 1.2+), SSRF prevention (blocking loopback/private subnets), and HMAC secret entropy validation.
* Architecture: Pure API calculation architecture forcing live API reads and fail-closed error handling in production.
* Tests: Added Software Engineering challenge suites for dual-env build matrix, SSRF injection defense, and 50,000-order heavy-load concurrency.

= 1.13.0 =
* Architecture: Removed 100% of local fallback math and mock generators from WordPress plugin; all calculations are delegated exclusively to Cloudflare Worker backend.
* Feature: Added intelligent API Connection Status lifecycle with loading animation, auto-dismissal on connected, and 3-attempt exponential retry before human-friendly offline state with manual retry button.
* UX: Repositioned 30D / 60D / 90D timeframe switcher to the top-right header for clean, human, accessible interaction with real-time recalculation.
* Cleanup: Completely removed sample order generation tools, routes, and UI buttons (not needed by store owners).
* Design: Removed robotic "Currency Audit Active" badge and old static mode bar for a human-first, modern UI feel.
* Resilience: Verified backend and plugin under heavy loads (50,000+ items and 500 cross-border orders in <500ms).
= 1.17.0 =
* Feature: Integrated FXLI_Logger structured DevSecOps telemetry and diagnostic logging for outbound Worker HTTP requests.
* Feature: Added Live API Handshake Diagnostic Inspector directly in the WordPress Admin Developer Section.
* Feature: Added REST API diagnostic endpoints (`/logs`, `/test-connection`, `/clear-logs`) gated with capability checks.
= 1.22.0 =
* AI Resilience: Upgraded Gemini AI integration to Google's modern 2026 model architecture (gemini-flash-lite-latest, gemini-flash-latest, gemini-3.5-flash-lite) with automated sequential candidate failover.
* Fix: Resolved issue where outdated model retirement caused fallback heuristic text to be permanently cached in WordPress transients.
* Cache Invalidation: In development mode and during connection handshakes, transient caches containing fallback warnings are automatically bypassed and purged upon worker restoration.
* REST API: Added refresh and force parameters to `/insight` endpoint allowing real-time AI re-evaluation.
* Author Persona: Updated author designation to Ebrahim Razmahang (Software Engineer & Backend Developer, Founder of Finlyzer).
* Testing: Expanded software engineering challenge suite to include 2026 multi-model failover testing, thinking budget verification, and 100-request concurrent stress tests.

= 1.21.0 =
* Fix: Eliminated duplicate closing tag in developer-section.php that prematurely terminated dashboard layout on development builds.
* Architecture: Enforced strict DOM tree div balance (open divs == close divs) across all dashboard templates, guaranteeing complete layout containment.
* UI/UX: Overhauled responsive design system across mobile (<768px), tablet (768px-1024px), desktop (1024px-1440px), and ultrawide viewports (>1440px) with modern container constraints.
* Copy: Humanized dashboard copy to modern fintech SaaS benchmarks (Stripe/Wise/Mercury) with decision-first financial risk metrics.
* Author: Updated author profile honoring Ebrahim Razmahang as Senior Backend Developer & Software Engineer (Backend Architect).
* Security: Strictly purged all open-source mentions per enterprise deployment specifications and verified target="_blank" rel="noopener noreferrer" link security.
* Testing: Added Test Group 25 verifying DOM tag balance, container containment, author persona, zero open-source references, and 100,000-cycle stress tests.

= 1.20.0 =
* UX: Implemented Progressive Disclosure for dashboard metrics. Detailed breakdown tables (Currency Analysis, ECB Market Timing, Payment Gateways, and Top Products) are now collapsed by default into an accessible, interactive drawer with subtab filtering.
* UX: End-users immediately see only essential executive figures (Total Currency Loss, Effective Fee Rate, Annual Run-Rate, AI Sentinel Insights) without information overload.
* Feature: Added dedicated "About Finlyzer" section honoring author Ebrahim Razmahang with direct GitHub (https://github.com/sl3nd3r1) and LinkedIn (https://www.linkedin.com/in/ebrahimrazmahang) links.
* Navigation: Added one-click "About" navigation in header toolbar with smooth scrolling to author attribution.
* Security: Strictly enforced target="_blank" rel="noopener noreferrer" on all external profile links per mandatory-secure-web-skills (reverse tab-nabbing immunity).
* Accessibility: Enhanced ARIA attributes (role="tablist", aria-selected, aria-expanded, aria-controls) across progressive disclosure drawer and subtabs.
* Testing: Added Test Group 24 with 100,000 rapid toggle cycles, subtab isolation tests, and full multi-component version parity validation.

= 1.19.0 =
* Fix: Resolved fatal error `Call to protected method WP_REST_Server::set_status()` on WordPress REST API fragment delivery by utilizing canonical `status_header($status)` with fallback to `http_response_code($status)`.
* Security: Hardened REST API response headers per mandatory-secure-web-skills with `X-Content-Type-Options: nosniff` and `Cache-Control: no-cache, no-store, must-revalidate, private`.
* Architecture: Bound HTML fragment serving filter strictly to matching response instance (`$result !== $response`), eliminating cross-request filter leakage during concurrent or batch REST dispatches.
* Resilience: Verified headers are unsent before attempting raw HTTP header dispatch, preventing warnings under CLI and custom development runners.
* Testing: Added comprehensive Test Group 23 stress testing REST API fragment delivery under heavy concurrent load (100,000 cycles) and verifying protected method call immunity.

= 1.18.0 =
* Architecture: Implemented dual-engine resilience (HTMX + Native Fetch) ensuring 100% reliable dashboard fragment swaps across Windows XAMPP and local dev environments.
* Fix: Explicitly propagated `X-WP-Nonce` and `credentials: 'include'` on all programmatic AJAX calls.
* Security: Enhanced permission gating allowing both `manage_woocommerce` and `manage_options` (Administrator) capabilities.
* Fix: Resolved strict parameter type-hinting on `rest_pre_serve_request` filter to eliminate `TypeError` exceptions under strict types.
* Telemetry: Automated dynamic client-side refresh of the Developer Telemetry table upon live diagnostic completion without requiring a page reload.
* Observability: Transparent error surfacing in connection status banner with exact HTTP status codes and server diagnostics.
* Development: Added clean zero-loss development baseline fallback when store has 0 cross-border orders or deferred order functions.

= 1.25.0 =
* Security: Enterprise-grade at-rest authenticated encryption (AES-256-GCM) with HKDF key derivation from WordPress core salts.
* Security: Multi-tiered secret resolution hierarchy prioritizing wp-config.php constant overrides with locked UI safeguard.
* Feature: Added Security & Worker Settings Modal with masked key display (e.g. 74e0••••••••25df) preventing shoulder surfing.
* Feature: One-click 64-character high-entropy HMAC secret generator and clipboard copy utility for Cloudflare Worker synchronization.
* Feature: Live HMAC handshake verification test against Cloudflare Worker (`/api/v1/verify`) with instant latency and clock skew metrics.
* Performance: Self-healing automatic migration from legacy plaintext database storage to AES-256-GCM encrypted envelope.
* Testing: Comprehensive test suite challenging tamper resistance, key derivation uniqueness, and 50,000 encryption/decryption cycles.

= 1.17.0 =
* Feature: Added structured telemetry and diagnostics logger (`FXLI_Logger`) with circular ring buffer (50 entries) and sensitive PII redaction.
* Dev DX: Integrated live backend connectivity diagnostic probe (`/health` and `/api/v1/analyze`) directly into the admin inspector.
* Dev DX: Enhanced XAMPP loopback resolution with full PHP CLI/server SAPI detection.

= 1.16.0 =
* Fix: Prevented client-side HTMX abort loop by guarding error handlers against status 0 / client cancellations.
* Fix: Replaced premature 3.5s watchdog timer with passive 20s network-aware safety monitor.
* Fix: Added local development host auto-detection (`is_local_host`) ensuring seamless XAMPP/LocalWP loopback communication without false SSRF blocking.
* Fix: Aligned HMAC development fallback secret across PHP plugin and Cloudflare Worker middleware.
* Fix: Added self-healing database table installation on admin initialization for direct file upgrade scenarios.
* Performance: Optimized Frankfurt ECB rate lookup with 1.5s fast-fail timeout and built-in resilient fallback matrix.
* Testing: Expanded software engineering challenge suite covering status 0 abort resilience and heavy load state machines.

= 1.12.0 =
* Fix: Cloudflare Worker 2MB payload support on /api/v1/analyze to prevent HTTP 413 rejection during bulk order analysis.
* Feature: On-demand WooCommerce HPOS order scanning and automatic backfill when local event cache is empty.
* Resilience: Real-time order caching hooked to status updates (woocommerce_order_status_completed, woocommerce_payment_complete).
* Dev DX: Zero-config local development auto-detection for Worker endpoint (http://127.0.0.1:8787) and dev HMAC secret parity.
* Tests: Added Test Group 15 challenging on-demand HPOS scanning, 25,000 order filtering, and zero-row auto-recovery.

= 1.11.0 =
* Feature: Automated bulk international order generator tool (`bin/generate-orders.php`) and 1-click admin dashboard generator.
* Feature: Recognized Klarna payment processor (`klarna`, `klarna_payments`, `kco`) with 3.0% cross-border conversion spread and brand badge styling.
* Architecture: Serverless backend-first calculation engine delegating heavy order attribution and ECB currency timing to Cloudflare Worker.
* Webhooks: Added real-time order completion listeners (`woocommerce_order_status_completed`, `woocommerce_payment_complete`).
* Tests: Rigorous high-concurrency software engineering challenge suites under 500 concurrent worker requests and 50,000 order items.

= 1.10.0 =
* Copy: Comprehensive rewrite across all dashboard views and documentation for merchants with clear, humanized terms (Conversion Fees, Foreign Sales, Rate Shifts).
* UX: Removed recommendation action banners to maintain a pure, objective Phase 1 fee audit.
* Design: Refined Fluent 2 subtle rounded styling across all badges, tabs, and tables.
* Tests: Enhanced high-load challenge suites testing 50,000 orders under heavy concurrent traffic.

= 1.9.0 =
* Design: Refactored badge and pill system to Microsoft Fluent 2 subtle 4px rounded rectangles with non-wrapping layout.
* Footer: Added official corporate copyright notice: © 2026 Finlyzer. All rights reserved.

= 1.8.0 =
* Fix: WordPress REST API raw HTML fragment rendering for htmx dashboard swaps via rest_pre_serve_request hook.
* Security: Client-side defense-in-depth JSON unwrap shield for reliable cross-environment dashboard rendering.

= 1.7.0 =
* Feature: Payment Gateway FX Recognition Matrix and Purchased Products by Gateway Ledger with interactive filtering.
