=== Donation Box ===
Contributors: usmanahmedmalik
Tags: donations, stripe, recurring donations, fundraising
Requires at least: 6.6
Tested up to: 7.1
Requires PHP: 8.1
Stable tag: 0.1.5
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

One-time and monthly donations with Stripe Checkout, configurable colors, donor accounts, and payment reporting.

== Description ==
Donation Box brings one-time and monthly donations to your WordPress site through Stripe Checkout. Designed for charities, community organisations and independent projects, it includes a responsive donation form and an admin dashboard without requiring WooCommerce.

= Features =
* One-time and monthly donations with suggested or custom amounts.
* A responsive two-step form with configurable colours, heading and description.
* Shortcodes for the WordPress editor and Elementor Shortcode widget.
* Stripe-hosted Checkout and authenticated Customer Portal access.
* Separate test/live credentials, webhook URLs and reporting.
* Payments, subscriptions, donors, filters and CSV export.
* WordPress subscriber accounts created after confirmed successful payments.
* Signed webhooks, duplicate-event protection, renewal and refund tracking.
* EUR, USD and GBP settings, translation-ready strings and bundled Dutch.

Use [donation_box] for the donation form and [donation_box_account] for authenticated donor history. Configure Donation Box > Settings before accepting payments. Payment-method availability depends on Stripe eligibility, currency and payment flow. Stripe processing and Billing fees may apply; the plugin adds no platform fee.

= External service: Stripe =
This plugin uses Stripe to create Checkout Sessions and Customer Portal sessions and to retrieve payment, invoice, subscription and refund information. It sends donation amounts, currency, donor email, donation references and configured donation titles when creating checkout sessions. When a signed event arrives, it retrieves relevant Stripe records to confirm payment and update the local records. Portal access sends the customer's Stripe ID and a return URL.

First name, last name and optional phone are stored in WordPress. Card details are collected by Stripe Checkout, not by WordPress. A Stripe account is required.
Stripe terms: https://stripe.com/legal/ssa
Stripe privacy policy: https://stripe.com/privacy

= Data retention =
Deactivation and removal preserve donation records and saved settings. Configure your organisation's privacy notice and retention policy. This version does not automate personal-data export or erasure. Test-mode payments can create WordPress users and trigger registration emails and hooks; test on a staging site.

Source and documentation: https://github.com/usmanahmedmalik/wp-donation-box-plugin

== Installation ==
1. Upload donation-box.zip through Plugins > Add New Plugin, or place the donation-box folder in wp-content/plugins.
2. Activate Donation Box and open Donation Box > Settings.
3. Configure the donation form, currency, amounts and colours.
4. Add the Stripe test secret API key and webhook signing secret. Register the displayed test endpoint in Stripe using API version 2024-06-20 and the events listed in settings.
5. Add [donation_box] to a donation page and [donation_box_account] to an account page.
6. Enable and configure Stripe Customer Portal for donor self-service.
7. Test payment, subscription and webhook flows before adding live credentials and switching to Live mode. HTTPS is required for live checkout.

Requires MySQL/MariaDB with GET_LOCK support. See the repository documentation for complete setup and operational limitations.

== Frequently Asked Questions ==
= Does Donation Box require WooCommerce? =
No. Donation Box runs independently of WooCommerce.

= Does it support Elementor? =
Yes. Place [donation_box] in Elementor's Shortcode widget.

= What credentials do I need? =
A Stripe secret API key and the matching webhook endpoint signing secret for each environment. Hosted Checkout does not require a publishable key in plugin settings.

= Can donors manage their subscriptions? =
Authenticated donors can access Stripe Customer Portal from their account page after you configure the portal in Stripe.

= When is a donor's WordPress account created? =
After the first confirmed successful donation for a new email. An existing account is linked without changing its password or permissions. Payment does not sign the visitor into an account.

= Where do refunds happen? =
Issue refunds in Stripe. Supported webhook events update the local donation records. Admin refund actions and disputes are not included in this version.

= Does uninstalling delete donation data? =
No. Records and saved settings are retained. Removing the plugin also does not cancel subscriptions at Stripe.

== Changelog ==
= 0.1.5 =
Prepare SQL identifiers and filter values, sanitise URL/server input, document read-only queries, add translator context and update compatibility metadata. Settings and donation records are preserved.


= 0.1.4 =
Removed the temporary testing webhook URL field from settings. Use the standard test and live webhook URLs.
= 0.1.3 =
Fetch fresh anonymous checkout tokens outside the page cache to fix logged-in tester and expired cached-form nonce failures. Add safe browser-console HTTP status/error-code diagnostics.

= 0.1.2 =
Temporary POST /sprocess alias for the signed test-mode Stripe webhook; existing REST endpoints remain available. No permalink resaving required.

= 0.1.1 =
Tighter CSS and JavaScript scoping; preserve surrounding PHP variables during bootstrap; add isolation regression checks.

= 0.1.0 =
Initial release.
