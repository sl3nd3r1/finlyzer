=== Finlyzer — FX Loss & Margin Insights for WooCommerce ===
Contributors: finlyzer
Tags: woocommerce, currency, fx, forex, payments, stripe, paypal, analytics
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.14.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track hidden payment gateway conversion fees and currency loss across your international WooCommerce sales.

== Description ==

Finlyzer gives WooCommerce merchants clear, honest visibility into how much money is lost to foreign exchange fees and payment processor markups on international sales.

When international customers purchase from your store in a foreign currency (EUR, GBP, CAD, AUD, etc.), payment gateways like PayPal, Stripe, Klarna, WooPayments, and Mollie convert those funds into your payout currency. Instead of using the real interbank exchange rate, processors charge a hidden conversion spread—typically taking 1.5% to 3.8% or more on every cross-border order. In addition, exchange rate fluctuations during settlement create additional currency loss.

Finlyzer scans your existing orders and shows you the exact numbers:
* **Total Currency Loss**: See how much you lost over the last 30, 60, or 90 days.
* **Loss by Payment Processor**: See order count, foreign sales, and exact fees taken by PayPal, Stripe, Klarna, WooPayments, and more.
* **Top Affected Products**: Pinpoint which catalog items generated foreign sales and how much fee was deducted from each sale.
* **Exchange Rate Shift by Market**: Compare order rates against live European Central Bank reference rates for your active currencies.
* **Loss by Currency**: See which currencies drive your sales and which cost you the most in conversion fees.

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
