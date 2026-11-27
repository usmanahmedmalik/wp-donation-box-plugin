# Pledgly Donations

An open-source WordPress plugin for one-time and monthly donations using Stripe Checkout. No WooCommerce, Composer dependencies, or frontend build step required.

## Features

- Responsive two-step donation form: one-time/monthly, suggested/custom amounts, first and last name, email, optional phone, and consent.
- Elementor-compatible `[donation_box]` shortcode.
- Configurable colors, title, description, suggested amounts, and EUR/USD/GBP currency. Default palette: blue `#0071DC`, yellow `#FFC221`, navy `#031F42`, text `#252525`, muted gray `#696969`.
- Separate test/live secret keys, webhook signing secrets, and copyable webhook URLs.
- Stripe-hosted Checkout and Customer Portal. Card details never enter WordPress.
- Signed webhooks, timestamp tolerance, duplicate delivery protection, serialized processing, renewal tracking, failed payments, full/partial refunds, and current-state reconciliation for delayed events.
- Subscriber account created only after a confirmed successful donation; existing accounts matched by email without changing their profile or role. WordPress sends a password setup email. Payment never logs a visitor into an existing account.
- `[donation_box_account]` for authenticated donation history and payment/subscription management.
- Admin overview, payments, subscriptions, donors, mode/status/email filters, pagination, Stripe dashboard links, and streamed CSV exports.
- Translation-ready strings and RTL-friendly layout.

## Installation

Requires WordPress 6.6+, PHP 8.1+, and MySQL/MariaDB with advisory lock support (`GET_LOCK`).

1. Run `python3 scripts/build.py` to create `dist/pledgly-donations.zip`.
2. In WordPress, open **Plugins → Add New Plugin → Upload Plugin**, upload that ZIP, and activate **Pledgly Donations**.
3. Open **Pledgly Donations → Settings**.
4. Set title, text, currency, suggested amounts, and colors.
5. Create a donation page containing `[donation_box]`. In Elementor use the **Shortcode** widget.
6. Create an account page containing `[donation_box_account]` and link to it from your website menu.

Alternatively, place the repository folder at `wp-content/plugins/pledgly-donations` and activate it. This repository does not automatically modify another local WordPress installation.

## Stripe settings

Start in **Test** mode. In **Pledgly Donations → Settings**, each mode has:

| Field | What to use |
| --- | --- |
| Secret API key | Stripe secret key beginning `sk_test_…` or `sk_live_…` |
| Webhook signing secret | Endpoint signing secret beginning `whsec_…` |
| Webhook URL | Generated read-only URL to copy into Stripe, such as `https://example.com/wp-json/donation-box/v1/webhook/test` |

The webhook URL is the server-to-server callback endpoint; it is generated from WordPress's current URL, including plain-permalink support. Donor return URLs are generated from the page containing the form and validated to stay on the same origin. No manually configured callback URL or publishable key is needed for hosted Checkout.

Checkout obtains a fresh form nonce using an uncached `POST /wp-json/donation-box/v1/checkout-token` immediately before submission. Both token and checkout requests omit WordPress cookies so logged-in testers use the same anonymous identity as other donors. Portal requests still use authenticated cookies and `X-WP-Nonce`. Keep checkout-token and checkout POST responses out of CDN/server caches. After upgrading, purge the page/CDN/asset cache once so the updated JavaScript is served. Browser-console warnings report failed HTTP status and error code without logging donor data, tokens or Stripe secrets. A `nonce` error means form-token verification failed before contacting Stripe; `configuration` means the mode's Stripe credentials are incomplete.

Secret values are never re-rendered into settings forms. Blank inputs keep the saved values; use the clear checkbox to remove one. Saved settings are not exposed through REST or autoloaded. They are stored in the WordPress database, so production installations may prefer constants in `wp-config.php`:

```php
define('DONATION_BOX_TEST_KEY', 'sk_test_…');
define('DONATION_BOX_TEST_WEBHOOK', 'whsec_…');
define('DONATION_BOX_LIVE_KEY', 'sk_live_…');
define('DONATION_BOX_LIVE_WEBHOOK', 'whsec_…');
```

A defined constant takes precedence and its field is disabled in the settings screen. Do not commit real credentials.

### Webhook setup

In Stripe, create a webhook destination using the matching test/live URL. Select **API version `2024-06-20`** (the version pinned by this integration), and subscribe to:

```text
checkout.session.completed
checkout.session.async_payment_succeeded
checkout.session.async_payment_failed
checkout.session.expired
invoice.paid
invoice.payment_failed
customer.subscription.created
customer.subscription.updated
customer.subscription.deleted
charge.refunded
```

Copy that destination's signing secret into the matching mode's settings. The two webhook endpoints keep working independently of the mode currently shown on the donor form. Keep live credentials available while live subscriptions remain active, even when switching the form to test mode.

Enable the **Customer Portal** in Stripe and allow payment-method changes and cancellation. Keep subscription price/quantity changes disabled: recurring donations are fixed to the amount chosen at checkout. Configure available payment methods and Stripe receipt emails in the Stripe Dashboard. Monthly checkout offers only payment methods eligible for recurring payments.

### Local development

If your local WordPress environment blocks external HTTP requests, allow `api.stripe.com` in `WP_ACCESSIBLE_HOSTS`. Keep local email capture enabled while testing. The plugin never overrides site-wide network or email restrictions.

Stripe cannot deliver to a `.test` hostname directly. With the Stripe CLI installed and authenticated, forward sandbox events:

```sh
stripe listen --forward-to http://example.test/wp-json/donation-box/v1/webhook/test
```

Use the `whsec_…` printed by `stripe listen` in the plugin's test settings while forwarding. This differs from the Dashboard destination secret. The listener must remain running. Password setup and receipt emails need working mail delivery (or a local mail catcher); the plugin cannot bypass blocked mail.

### Test checklist before live use

- One-time successful card payment, declined card, and cancelled Checkout.
- Monthly first payment, renewal, failed renewal and recovery, cancellation through the portal.
- Delayed payment if enabled; no donor account before payment confirmation.
- Existing donor email and a new email; only a new account receives a password setup email.
- Webhook retries, full/partial refunds, admin totals and CSV exports.
- Donor login, private donation history, and Customer Portal access.
- Desktop/mobile form, custom colors, and the Dutch locale.

The success return page deliberately says confirmation is pending. Only a verified webhook marks a payment successful. Shared page caches should exclude donation/account pages or have a lifetime below WordPress nonce expiry. Exclude REST checkout/portal requests from caching as well.

## Data and operations

Plugin-owned tables use the current site's WordPress prefix: `donation_box_payments`, `donation_box_subscriptions`, and `donation_box_events`. Records include donor details, amounts in integer cents, environment, Stripe references, timestamps, and statuses. No card data is stored. Donors can see only records linked to their authenticated WordPress user; portal sessions require both authentication and ownership of a confirmed customer record. Admin functions and exports require `manage_options`.

An existing email matches its WordPress account after payment; it does not verify the payer's ownership of that email or grant login access. Donors must authenticate through WordPress separately. Test-mode payments also create real subscriber accounts and trigger standard WordPress registration hooks for new donor emails. Existing registration, welcome-email or membership plugins may react to those hooks.

A new donation creates a fresh Stripe customer to avoid exposing an existing customer's billing details to someone entering their email address; the account page supports separate portal buttons when multiple customer records exist.

Public checkout uses a WordPress nonce, honeypot, server validation and a basic transient-backed limit of ten attempts per direct IP per ten-minute window. These are basic abuse controls, not a replacement for edge rate limits or bot protection on a public high-traffic site. IP buckets are hashed with the WordPress salt; raw IP addresses are not stored by this plugin.

Deactivation and uninstall preserve financial records and credentials. Set an appropriate privacy policy and retention policy for your organization. This initial version does not implement automated erasure, WordPress personal-data export integration, multi-site network activation, donations made outside this plugin, disputes, or administrative payment/refund actions. Make refunds in Stripe. Totals show confirmed amounts less recorded refunds, separately per currency and environment; they do not subtract Stripe fees.

Amounts and currencies on recurring invoices must match the original recurring donation. Unexpected invoice totals cause a retryable webhook error for investigation rather than recording an incorrect amount. The integration does not support taxes, promotional discounts, metered prices or externally edited subscription amounts.

## Development

```sh
php tests/run.php
php tests/render.php
php tests/reporting.php
php tests/isolation.php
php tests/routing.php
php tests/run.php --temporary-webhook
node --check assets/donation-box.js
node tests/frontend.js
python3 scripts/build.py
```

The regression suite uses a small WordPress boundary double backed by SQLite and mocked Stripe responses; it never connects to Stripe or sends mail. It covers exact money parsing, webhook signatures and retries, duplicate/out-of-order events, initial invoices arriving before Checkout, renewals, refunds, donor creation, portal ownership, settings sanitization and CSV formula protection. The render checks inspect generated HTML and settings without a WordPress database. Isolation checks also verify that ordinary page loads do not change site options or users, send mail, call Stripe, or enqueue frontend assets; all CSS and document-level JavaScript selectors are confined to the donation component. Run the manual checklist on a real WordPress sandbox before accepting live donations.


License: GPL-2.0-or-later.
