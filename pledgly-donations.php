<?php
/**
 * Plugin Name: Pledgly Donations
 * Plugin URI: https://github.com/usmanahmedmalik/wp-donation-box-plugin
 * Description: One-time and monthly donations with Stripe Checkout, donor accounts and payment reporting.
 * Version: 0.1.6
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * Author: Usman Ahmed Malik
 * License: GPL-2.0-or-later
 * Text Domain: pledgly-donations
 */
if (!defined('ABSPATH')) { exit; }
define('DONATION_BOX_VERSION', '0.1.6');
define('DONATION_BOX_FILE', __FILE__);
(static function () {
    foreach (['settings', 'store', 'stripe', 'api', 'frontend', 'admin'] as $component) {
        require_once __DIR__ . '/includes/class-' . $component . '.php';
    }
})();
register_activation_hook(__FILE__, ['Donation_Box_Store', 'install']);
add_action('plugins_loaded', static function () {
    if (get_option('donation_box_db_version') !== DONATION_BOX_VERSION) { Donation_Box_Store::install(); }
    Donation_Box_API::boot();
    Donation_Box_Frontend::boot();
    Donation_Box_Admin::boot();
});
