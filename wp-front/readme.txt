=== Finlyzer — FX Loss & Margin Insights for WooCommerce ===
Contributors: finlyzer, raygens
Tags: woocommerce, currency, fx, payments, conversion fees
Requires at least: 6.4
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 1.0.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Track hidden payment gateway conversion fees, currency loss, and protect international WooCommerce profit margins. 100% Free.

== Description ==

Finlyzer gives WooCommerce merchants clear, honest visibility into how much revenue is lost to foreign exchange fees and payment processor markups on international sales. It is **100% Free** with no hidden fees, no subscriptions, and no credit card required.

### 🔒 100% Privacy by Design — Zero Sensitive Information

Merchants trust Finlyzer because of its privacy architecture:
* **No Sensitive Information Needed**: Finlyzer **never** requests, accesses, stores, logs, or transmits sensitive customer or financial data.
* **Zero Personally Identifiable Information (PII)**: Customer names, email addresses, phone numbers, billing/shipping physical addresses, IP addresses, credit card numbers, and individual order IDs are **never collected, saved, or sent anywhere**.
* **Runs Directly on Your Store Database**: All calculations operate strictly on store-level numeric order totals (e.g., total sales volume, order counts, currency codes) and reference exchange rates.
* **Zero Checkout Impact**: Runs purely in the WordPress admin dashboard for completed orders. Your checkout flow, storefront speed, and buyer experience remain 100% untouched.

### Why Every International WooCommerce Store Needs Finlyzer

When international shoppers purchase from your store in foreign currencies (EUR, GBP, CAD, AUD, JPY, etc.), payment processors like PayPal, Stripe, Klarna, WooPayments, Mollie, and Adyen convert those funds into your payout currency. 

Instead of using true interbank market exchange rates, payment gateways charge hidden conversion spreads—typically pocketing **1.5% to 4.2% or more** on every cross-border order. On top of gateway spreads, foreign exchange rate fluctuations during settlement create additional currency loss.

Finlyzer inspects your store sales data and reveals the exact numbers:
* **Total Currency Loss**: View exact currency loss across the last 30, 60, or 90 days.
* **Effective Fee Rate**: Reveal true conversion spreads per processor (Stripe, PayPal, Klarna, etc.).
* **Loss Distribution by Currency**: Visual breakdown showing which currencies erode margins most.
* **Annual Run-Rate Exposure**: Projected yearly loss if gateway markup is left unaddressed.
* **AI Sentinel Insights**: Real-time risk audit powered by Google Gemini Flash AI providing prioritized, actionable recommendations to protect merchant profits.

### Key Features for WooCommerce Merchants

* **Payment Gateway Fee Comparison**: Compares cross-border transaction volume and conversion fee impact across Stripe, PayPal, Klarna, WooPayments, and more.
* **Multi-Currency Revenue Ledger**: Detailed breakdown of transaction volume, fee share, and currency loss per foreign currency.
* **Products Breakdown**: Line-by-line attribution of international revenue and conversion fees per product, with fast processor filtering.
* **Exchange Rate Movement**: Tracks rate shifts between order placement and settlement using global market reference rates.
* **High-Performance Order Storage (HPOS)**: Built-in native support for WooCommerce custom order tables (`wc_orders`) and traditional post meta.
* **Lightweight & Fast**: Pure server-to-server analytics engine with cached responses and asynchronous telemetry.
* **100% Free**: Full access to all financial analytics with zero paywalls.

### Developer & Maintainer

Finlyzer is developed and maintained by [RayGens](https://www.linkedin.com/in/ebrahimrazmahang) (GitHub: [sl3nd3r1](https://github.com/sl3nd3r1)).

== External Services ==

Finlyzer connects to the following external third-party services to deliver accurate exchange rates and automated AI risk analysis:

* **Global Market Reference Rates / Frankfurter API**: Used to retrieve real-time and historical currency exchange reference rates to evaluate payment processor conversion fee markups and market timing rate shifts.
  - Service: https://frankfurter.dev / https://www.ecb.europa.eu
  - Terms of Service: https://frankfurter.dev
  - Privacy Policy: https://frankfurter.dev
  - Data Sent: Target and store currency ISO codes (e.g., "EUR", "USD") and order dates. No store details, customer records, or financial transaction identifiers are ever sent.

* **Finlyzer Cloudflare Worker API & Google Gemini Flash AI**: Used to calculate cross-border payment gateway fee spreads, run multi-processor loss attribution, and generate executive AI financial risk assessments.
  - Service: https://ai.google.dev
  - Google Gemini Terms of Service: https://ai.google.dev/terms
  - Google Privacy Policy: https://policies.google.com/privacy
  - Data Sent: Anonymized, store-level aggregate order metrics (total foreign currency transaction volume, aggregate conversion fee estimates, currency breakdown, and order counts).
  - **Zero Personally Identifiable Information (PII)**: Customer names, email addresses, phone numbers, billing/shipping physical addresses, IP addresses, payment card credentials, and individual order IDs are never collected, logged, or transmitted.

== Installation ==

### WordPress Admin Upload
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

= Is Finlyzer really free? =
Yes, Finlyzer is 100% free with no hidden charges, trial periods, or credit card requirements.

= Does Finlyzer access sensitive customer data? =
No, never. Finlyzer adheres to strict privacy-by-design standards. The plugin never requests, accesses, stores, or transmits sensitive customer information. There is Zero Personally Identifiable Information (PII) collected or sent. Customer names, emails, addresses, payment card numbers, and individual order numbers are never touched.

= Will Finlyzer slow down customer checkout? =
Never. Finlyzer operates only in the WordPress admin dashboard for completed orders. It does not execute scripts or styles on your customer-facing checkout page.

= Does Finlyzer require an API key to display conversion fees? =
No. Finlyzer reads your existing WooCommerce order history and computes gateway conversion spreads without requiring any external account setup.

= Which payment processors are analyzed? =
Finlyzer recognizes conversion spread models for PayPal, Stripe, Klarna, WooPayments, Adyen, Mollie, Square, Direct Wire/BACS, Cash on Delivery, and standard credit card gateways.

== Changelog ==

= 1.0.0 =
* Initial release: complete payment gateway conversion fee auditing, FX currency loss calculations, multi-currency ledger, HPOS compatibility, zero-PII privacy architecture, and AI-driven profit margin recommendations.
