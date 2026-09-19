=== Finlyzer — FX Loss & Margin Insights for WooCommerce ===
Contributors: finlyzer
Tags: woocommerce, currency, fx, forex, payments, stripe, paypal, analytics
Requires at least: 6.4
Tested up to: 6.7
Requires PHP: 8.1
Stable tag: 1.11.0
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
* **Automated Test Order Generator**: Built-in CLI and admin dashboard quick generator for sample cross-border orders across Stripe, PayPal, and Klarna.
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
No. Finlyzer reads your existing WooCommerce order history and computes gateway conversion spreads locally or via your private serverless worker without requiring any external account.

= Will Finlyzer slow down customer checkout? =
Never. Finlyzer only reads completed order data and does not run during checkout.

= Which payment processors are analyzed? =
Finlyzer recognizes spread fee models for PayPal (3.8%), Stripe (2.2%), Klarna (3.0%), WooPayments (2.2%), Adyen (1.5%), Mollie (2.5%), Square (2.8%), Direct Wire/BACS (0.0%), Cash on Delivery (0.0%), and generic card processors (2.5%).

== Changelog ==

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
