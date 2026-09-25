# Finlyzer — FX Loss & Margin Insights for WooCommerce

[![WordPress](https://img.shields.io/badge/WordPress-6.4%2B-blue.svg)](https://wordpress.org/plugins/)
[![WooCommerce](https://img.shields.io/badge/WooCommerce-8.0%2B-purple.svg)](https://woocommerce.com/)
[![PHP](https://img.shields.io/badge/PHP-8.1%2B-777BB4.svg)](https://www.php.net/)
[![License: GPL v2+](https://img.shields.io/badge/License-GPLv2%2B-green.svg)](https://www.gnu.org/licenses/gpl-2.0.html)

**Finlyzer** gives WooCommerce store owners clear, honest visibility into how much revenue is lost to foreign exchange fees and payment processor conversion spreads on international sales. **100% Free** with zero hidden fees.

Developed and maintained by [Ebrahim Razmahang](https://www.linkedin.com/in/ebrahimrazmahang).

---

## 🔒 100% Local-First by Default — Zero Telemetry & Privacy by Design

* **Runs 100% Locally on Your Store Database**: All currency loss math, gateway fee spread analysis, and multi-currency ledgers are calculated directly on your own server. Zero external network calls are made out-of-the-box.
* **Zero Personally Identifiable Information (PII)**: Customer names, email addresses, phone numbers, physical addresses, IP addresses, payment card details, and individual order IDs are **never collected, saved, or transmitted**.
* **Zero Checkout Impact**: Runs purely in the WordPress admin dashboard for completed orders. Your storefront speed and customer checkout flow remain completely untouched.
* **Lightweight & Fast**: Powered by indexed MySQL aggregation queries that aggregate tens of thousands of orders in under 30 milliseconds with negligible RAM and CPU usage.

---

## 🚀 Key Features

* **Real-time FX Loss Auditing**: Detects hidden payment processor conversion markups (typically 1.5% to 4.2%+) across PayPal, Stripe, Klarna, WooPayments, Mollie, Adyen, and more.
* **Comprehensive Multi-Currency Ledger**: Breaks down transaction volume and conversion spread loss across foreign currencies (EUR, GBP, CAD, AUD, JPY, CHF, etc.).
* **Product-Level Gateway Attribution**: Identifies which international products are suffering the heaviest margin drag.
* **Optional Cloud Sentinel & AI Insights (100% Opt-In)**: Merchants can optionally enable Cloud AI in Settings to receive automated margin risk audits powered by Google Gemini.

---

## 📦 System Requirements

* **WordPress**: 6.4 or higher
* **WooCommerce**: 8.0 or higher (HPOS compatible)
* **PHP**: 8.1 or higher (PHP 8.2 and 8.3 fully supported)
* **MySQL**: 5.7+ or MariaDB 10.4+

---

## 🛠 Project Structure

* [`wp-front/`](wp-front/) — Core WordPress plugin source code:
  * `finlyzer.php` — Plugin bootstrap, lifecycle hooks, and HPOS declarations
  * `readme.txt` — WordPress.org standard directory documentation
  * `license.txt` — GNU General Public License v2 text
  * `includes/` — Object-oriented architecture:
    * `class-fxli-order-analyzer.php` — Local-first indexed calculation engine
    * `class-fxli-crypto.php` — Authenticated AEAD encryption (AES-256-GCM)
    * `class-fxli-env.php` — Environment profile resolution and opt-in enforcement
    * `class-fxli-security.php` — Nonce validation and capability checks
    * `class-fxli-installer.php` — Database schema creation and cleanup
    * `class-fxli-gemini-client.php` — AI client with offline heuristic fallback
    * `class-fxli-rest-api.php` — Hardened REST endpoints
    * `class-fxli-admin-page.php` — Screen registration and asset enqueuing
  * `templates/` — Clean, escaped admin dashboard views and partials
  * `assets/` — CSS, JavaScript, icons, and vendor scripts
  * `tests/` — Automated challenge test suite verifying 900+ architectural invariants

---

## 💻 Developer & Production Builds

Install dependencies and run tests:

```bash
cd wp-front
npm install
npm test
```

Compile the production distribution package for WordPress.org:

```bash
npm run build:prod
```

This generates `wp-front/dist/production/finlyzer.zip`.

---

## 📄 License

This plugin is licensed under the [GNU General Public License v2.0 or later](wp-front/license.txt).
