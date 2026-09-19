# Finlyzer — FX Loss Insights (Phase 1 Production Blueprint)

Translation of the "Hedgehog" Phase 1 wedge from the [Cinkciarz.pl case study](https://www.loot-drop.io/startup/2257-cinkciarzpl) into an enterprise-ready WooCommerce production architecture: **WordPress/WooCommerce plugin ("Finlyzer"), htmx frontend, Gemini 2.0 Flash AI Risk Sentinel, Cloudflare Workers serverless backend.**

> **Origin & Business Strategy**: In the Cinkciarz.pl / Conotoxia collapse, billions in volume were undermined by opaque financial operations, while e-commerce merchants quietly lost 2-4% on cross-border payments. The rebuild pivot ("Hedgehog") begins with a zero-money-movement Phase 1 wedge: a free analytics dashboard that exposes hidden FX spread losses and sounds the alarm on capital erosion, building merchant trust before Phase 2+ treasury services.

---

## 1. System Architecture

| Concern | Production Specification | Architectural Rationale |
|---|---|---|
| **Plugin Identity** | **Finlyzer** (v1.6.0) | High-performance, read-only analytics & AI exposure sentinel |
| **Frontend UI** | Modern Chic Fintech Ledger | Obsidian/slate palette (`#090D16`), glowing severity badges, proportional multi-currency loss distribution bars, tabular mono figures |
| **Interactivity** | Vendored `htmx` (v2.0.3) | Zero external CDN requests, no SPA build overhead, full CSP compatibility, no clash with WordPress React/jQuery |
| **Order Storage** | WooCommerce HPOS Verified | High-Performance Order Storage compatible; no legacy post table dependencies |
| **Database Indexing** | Composite Covering Index | `KEY order_date_currency (order_date, order_currency)` on `wp_fxli_fx_events` enables $O(\log N)$ aggregations over 50,000+ orders |
| **AI Sentinel** | Gemini 2.0 Flash via Cloudflare Worker | Urgent financial warning tone; Gemini API key is isolated inside Cloudflare Worker secrets |
| **Fallback Resilience** | Autonomous Heuristic Warning Engine | Immediate, data-backed financial exposure warning if the Worker is offline or unconfigured |
| **Data Boundary** | Zero PII Transmission | Only aggregated numbers (currency code, counts, totals) are transmitted over HMAC-signed HTTP |

```
┌─────────────────┐   hx-get (cookie + nonce)   ┌───────────────────────┐   HMAC-SHA256 (300s)   ┌────────────────────────┐   API Key   ┌────────────┐
│   Merchant's    │ ──────────────────────────▶ │    Finlyzer REST      │ ─────────────────────▶ │   Cloudflare Worker    │ ──────────▶ │   Gemini   │
│   Browser       │                             │   (finlyzer/v1/*)     │                        │   (Isolated Secrets)   │             │   2.0      │
│  (wp-admin)     │ ◀────────────────────────── │  Scans HPOS Orders    │ ◀───────────────────── │   Risk Warning Prompt  │ ◀────────── │   Flash    │
└─────────────────┘       HTML Fragments        └───────────────────────┘     JSON Insight       └────────────────────────┘  JSON Text  └────────────┘
```

---

## 2. Directory Layout & Deliverables

```
frontend/wp-front/
├── finlyzer.php                      # Bootstrap, HPOS declaration, lifecycle hooks (v1.0.0)
├── uninstall.php                     # Complete DB & transient teardown on plugin deletion
├── wp-config-snippet.php             # Production environment constants
├── includes/
│   ├── class-fxli-security.php       # Capability checks, REST nonce verification, HMAC signing, prompt sanitization
│   ├── class-fxli-installer.php      # Custom table installer with composite covering index (order_date, order_currency)
│   ├── class-fxli-order-analyzer.php # HPOS-safe order scanner, transient-cached aggregations, run-rate projections
│   ├── class-fxli-gemini-client.php  # HMAC worker client + autonomous heuristic warning fallback engine
│   ├── class-fxli-rest-api.php       # Gated REST endpoints (/summary & /insight), strict argument validation
│   └── class-fxli-admin-page.php     # Menu registration, script localization, CSP-safe asset enqueuing
├── templates/
│   ├── dashboard.php                 # Chic shell, live sentinel badge, period selectors
│   └── partials/
│       ├── summary-cards.php         # Hero loss figure, severity pills, run-rate cards, distribution bar, ledger
│       └── insight-note.php          # AI Risk Sentinel alert box with warning icon & strategic remediation
├── assets/
│   ├── css/dashboard.css             # Obsidian/slate design system, tabular numbers, shimmer skeletons
│   └── js/dashboard.js               # htmx:configRequest nonce injector, tab switcher (100% CSP compliant)
└── tests/
    ├── challenge-suite.js            # Node.js stress testing (25,000 orders), boundary & injection challenge
    └── test-order-analyzer.php       # PHPUnit/WP test compatibility assertions
```

---

## 3. Recommended Cloudflare Worker AI Warning Prompt

When deploying your Cloudflare Worker on Cloudflare Workers, update `buildPrompt(summary)` in your worker's `src/index.js` to enforce the requested warning tone:

```javascript
/**
 * Cloudflare Worker AI Warning Prompt Builder.
 * Instructs Gemini 2.0 Flash to act as an uncompromising financial risk auditor.
 */
function buildPrompt(summary) {
    const lines = summary.by_currency.map(
        (row) => `- ${row.currency}: ${row.orders} orders, ~${row.loss} ${summary.store_currency} spread loss`
    );

    return [
        'You are an uncompromising senior financial risk auditor issuing an urgent capital erosion warning to an e-commerce merchant.',
        `Store base currency: ${summary.store_currency}. Audit window: ${summary.period_days} days.`,
        `Total estimated FX/spread leakage: ${summary.total_loss} ${summary.store_currency} across ${summary.order_count} cross-border transactions.`,
        'Currency exposure breakdown:',
        ...lines,
        '',
        'Instructions:',
        '1. Tone: Direct, alarming yet professional financial warning. Highlight that this silent margin drain directly erodes net profits.',
        '2. Identify the single biggest exposure driver and project the compounding risk.',
        '3. State one concrete, immediate strategic remediation action (e.g., enable local currency settlement or multi-currency pricing).',
        '4. Length: Strictly 2-3 sentences. No pleasantries, no markdown, no bullet points, no greetings.',
    ].join('\n');
}
```

---

## 4. Security & Compliance Checklist (`/mandatory-secure-web-skills`)

- [x] **Zero Client-Side Secrets**: Gemini API keys and HMAC secrets exist only in Cloudflare Worker secrets or `wp-config.php`.
- [x] **Strict REST Gating**: All endpoints enforce `is_user_logged_in()`, `current_user_can('manage_woocommerce')`, and `wp_verify_nonce($nonce, 'wp_rest')`.
- [x] **Defense-in-Depth Output Escaping**: All dynamic data in templates is escaped with `esc_html()`, `esc_attr()`, and `wp_kses_post()`.
- [x] **SQL Injection Immunity**: All queries execute through `$wpdb->prepare()` with strongly typed placeholders.
- [x] **Strict CSP Compatibility**: Zero inline `on*` script handlers; all event bindings use `addEventListener` and `htmx:configRequest`.
- [x] **Prompt Injection Defense**: `FXLI_Security::sanitize_prompt_scalar()` enforces strict regex character allow-lists and length caps.
- [x] **Heavy-Load Protection**: Composite covering database index `(order_date, order_currency)` and 5-minute transient caching prevent database thrashing.
