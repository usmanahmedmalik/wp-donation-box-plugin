<div align="center">

# Donation Box

### Giving made simple. Built for WordPress.

One-time and monthly donations, secure Stripe Checkout, and donor management in your WordPress dashboard.

[![Plugin checks](https://github.com/usmanahmedmalik/wp-donation-box-plugin/actions/workflows/checks.yml/badge.svg)](https://github.com/usmanahmedmalik/wp-donation-box-plugin/actions/workflows/checks.yml)
![WordPress](https://img.shields.io/badge/WordPress-6.6%2B-21759b)
![PHP](https://img.shields.io/badge/PHP-8.1%2B-777bb4)
[![License: GPL v2 or later](https://img.shields.io/badge/License-GPL_v2_or_later-blue.svg)](LICENSE)

[Getting started](#getting-started) · [Stripe setup](docs/SETUP.md#stripe-settings) · [Documentation](docs/SETUP.md) · [Report an issue](https://github.com/usmanahmedmalik/wp-donation-box-plugin/issues)

</div>

---

Donation Box helps charities, community organisations, and independent projects accept donations on their own WordPress websites. Add a shortcode, connect Stripe, and manage donations without installing an online store.

**No WooCommerce dependency. No Composer installation. No frontend build step.** Stripe processing and Billing fees may apply; this plugin adds no platform fee.

## What you can do

| For donors | For administrators |
| --- | --- |
| Give once or support your work monthly | Track payments, subscriptions, and donors |
| Choose a suggested amount or enter their own | Filter by environment, status, and email |
| Pay through Stripe-hosted Checkout | Export filtered records as CSV |
| View their donations after signing in | Customise colours, amounts, and donation text |
| Manage subscriptions through Stripe Customer Portal | Configure separate test and live credentials |

### Made to fit your site

- Responsive, two-step donation form with first name, last name, email, optional phone, and consent.
- Shortcodes that work in the WordPress editor and Elementor's Shortcode widget.
- Configurable palette, heading, description, and suggested amounts.
- EUR, USD, and GBP configuration; payment-method availability depends on currency and Stripe eligibility.
- Translation-ready interface, bundled Dutch translation, and RTL-friendly layout.

### Payments with a clear record

- Stripe-hosted payment collection: card details never pass through WordPress.
- Signed webhooks confirm successful payments and keep subscription and refund records updated.
- Duplicate-event protection and handling for delayed and out-of-order events.
- A subscriber account is created after the first confirmed successful payment for a new email address.
- Existing users keep their password, profile, and permissions; donations never automatically log someone into an account.
- Private donation history and authenticated access to Stripe Customer Portal.

## Getting started

**Requirements:** WordPress 6.6+, PHP 8.1+, MySQL/MariaDB with `GET_LOCK` support, and a Stripe account. Live checkout requires HTTPS.

1. Build an installable ZIP with `python3 scripts/build.py`.
2. Upload `dist/donation-box.zip` through **Plugins → Add New Plugin → Upload Plugin** and activate it.
3. Open **Donation Box → Settings** and configure your donation text, amounts, and colours.
4. Add your Stripe **test secret API key** and **test webhook signing secret**.
5. Register the displayed test webhook URL in Stripe and enable the events listed in settings.
6. Add the donation shortcode to a page:

   ```text
   [donation_box]
   ```

7. Add a donor account page:

   ```text
   [donation_box_account]
   ```

For a source installation, place the plugin in `wp-content/plugins/donation-box/`. The repository name and installable plugin folder name differ intentionally.

## Connect Stripe

| Setting | Test mode | Live mode |
| --- | --- | --- |
| Secret API key | `sk_test_…` | `sk_live_…` |
| Webhook signing secret | The test endpoint's `whsec_…` | The live endpoint's `whsec_…` |
| Webhook path | `/wp-json/donation-box/v1/webhook/test` | `/wp-json/donation-box/v1/webhook/live` |

The plugin uses hosted Checkout and does not require a publishable key. Webhook destinations must use API version **2024-06-20**, matching this integration.

Enable suitable payment methods in Stripe and configure Customer Portal before offering donor subscription management. iDEAL and SEPA availability depends on the account, currency, payment flow, and integration settings; enabling a method does not guarantee it appears in every checkout.

**[Read the complete Stripe setup guide →](docs/SETUP.md#stripe-settings)**

## Security, privacy, and operations

Admin reporting and exports require WordPress administrator capabilities. Donor history and portal access require authentication and record ownership. Checkout includes validation, a fresh form token, a honeypot, and basic rate limiting.

Payment records are stored in plugin-owned WordPress tables. Credentials can be configured in settings or supplied through `wp-config.php` constants. Never commit real keys or donor data.

Deactivation and removal preserve records and saved settings. Define a retention policy for your organisation. Test payments can create WordPress subscriber accounts and trigger registration emails and hooks, so use a staging site and email capture when testing.

Read [data and operations](docs/SETUP.md#data-and-operations) for limitations. This version does not include automated data erasure/export integration, network-wide multisite activation, disputes, or admin refund actions. Issue refunds through Stripe. Recurring amounts should not be changed externally.

## Development and verification

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

PHP tests require the SQLite PDO and DOM extensions. Tests use WordPress boundary doubles and mocked Stripe responses; they do not make real payments or prove compatibility with every WordPress installation. GitHub Actions runs the suite on PHP 8.1 and 8.4.

Before accepting real donations, complete the [sandbox test checklist](docs/SETUP.md#test-checklist-before-live-use). This project is not yet listed or approved on WordPress.org and has not undergone an independent security audit.

## Contributing and support

Bug reports, translations, documentation improvements, and focused pull requests are welcome. Include your WordPress/PHP/plugin versions and steps to reproduce. Remove credentials and personal data from screenshots and logs.

For a potential security vulnerability, use [GitHub private vulnerability reporting](https://github.com/usmanahmedmalik/wp-donation-box-plugin/security/advisories/new) if available; otherwise contact the maintainer privately. Do not post exploit details or secrets in a public issue.

## WordPress.org distribution

GitHub hosts development. WordPress.org has a separate review and release process, and uses `readme.txt` for the public listing.

**[Submission and release checklist →](docs/WORDPRESS-ORG.md)**

## License

Copyright Usman Ahmed Malik. Released under [GPL-2.0-or-later](LICENSE). You may use, study, modify, and redistribute the plugin under that license.
