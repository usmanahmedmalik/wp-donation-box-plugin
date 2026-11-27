<?php
require __DIR__ . '/bootstrap.php';
$GLOBALS['actions'] = []; $GLOBALS['shortcodes'] = []; $GLOBALS['registered_assets'] = [];
function add_action($hook, $callback, ...$args) { $GLOBALS['actions'][$hook][] = $callback; }
function register_activation_hook($file, $callback) { $GLOBALS['activation'] = $callback; }
function plugins_url($path, $file) { return 'https://example.test/wp-content/plugins/pledgly-donations/' . $path; }
function add_shortcode($tag, $callback) { $GLOBALS['shortcodes'][$tag] = $callback; }
function wp_register_style($handle, ...$args) { $GLOBALS['registered_assets']['style'][] = $handle; }
function wp_register_script($handle, ...$args) { $GLOBALS['registered_assets']['script'][] = $handle; }
// Deliberately no enqueue, HTTP, email, login or site-settings mutation stubs here.
$component = 'value from another plugin';
ob_start();
require __DIR__ . '/../pledgly-donations.php';
$GLOBALS['wpdb'] = new TestDB();
$GLOBALS['options'] = ['donation_box_db_version' => DONATION_BOX_VERSION, 'blogname' => 'Original site', 'default_role' => 'administrator'];
$GLOBALS['users'] = [(object) ['ID' => 1, 'user_email' => 'existing@example.test', 'role' => 'administrator']];
$GLOBALS['http_calls'] = []; $GLOBALS['notifications'] = [];
$before_options = $GLOBALS['options']; $before_users = $GLOBALS['users'];
foreach ($GLOBALS['actions']['plugins_loaded'] as $callback) { $callback(); }
foreach ($GLOBALS['actions']['wp_enqueue_scripts'] as $callback) { $callback(); }
$output = ob_get_clean();
$count = 0;
function verify_isolation($ok, $message) { global $count; ++$count; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); } }
verify_isolation($output === '', 'loading plugin emits no content');
verify_isolation($component === 'value from another plugin', 'bootstrap preserves surrounding PHP variables');
verify_isolation($GLOBALS['options'] === $before_options, 'ordinary page load leaves site options unchanged');
verify_isolation($GLOBALS['users'] === $before_users, 'ordinary page load leaves existing users unchanged');
verify_isolation($GLOBALS['http_calls'] === [], 'ordinary page load sends no external requests');
verify_isolation($GLOBALS['notifications'] === [], 'ordinary page load sends no emails');
verify_isolation($GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM wp_donation_box_payments') === 0, 'ordinary page load does not create donations');
verify_isolation(array_keys($GLOBALS['shortcodes']) === ['donation_box', 'donation_box_account'], 'only plugin shortcodes registered');
verify_isolation($GLOBALS['registered_assets'] === ['style' => ['donation-box'], 'script' => ['donation-box']], 'assets registered without enqueue on unrelated pages');
$allowed_hooks = ['plugins_loaded', 'parse_request', 'rest_api_init', 'wp_enqueue_scripts', 'admin_menu', 'admin_init', 'admin_post_donation_box_export'];
verify_isolation(array_diff(array_keys($GLOBALS['actions']), $allowed_hooks) === [], 'no unexpected global hooks');
$css = file_get_contents(__DIR__ . '/../assets/donation-box.css');
preg_match_all('/(?:^|[{}])([^{}]+)\{/', $css, $matches);
foreach ($matches[1] as $rule) {
    if (str_starts_with(trim($rule), '@')) { continue; }
    foreach (explode(',', $rule) as $selector) {
        verify_isolation(str_starts_with(trim($selector), '.donation-box'), 'CSS selector stays inside donation component: ' . $selector);
    }
}
$js = file_get_contents(__DIR__ . '/../assets/donation-box.js');
preg_match_all('/document\.querySelectorAll\([\'"]([^\'"]+)/', $js, $matches);
foreach ($matches[1] as $selector) { verify_isolation(str_starts_with($selector, '.donation-box '), 'script selects only plugin elements'); }
// Linking a confirmed payment to an existing user must preserve their role/profile.
Donation_Box_Store::insert(['reference' => 'existing', 'mode' => 'test', 'email' => 'existing@example.test', 'first_name' => 'Submitted', 'last_name' => 'Name', 'amount' => 100, 'currency' => 'eur', 'frequency' => 'once', 'status' => 'paid', 'created_at' => '2026-11-16 10:00:00']);
Donation_Box_Store::link_user(Donation_Box_Store::find('existing'));
verify_isolation($GLOBALS['users'] === $before_users, 'existing donor role and profile preserved');
verify_isolation($GLOBALS['notifications'] === [], 'existing donor receives no registration email');
verify_isolation(Donation_Box_Store::find('existing')->user_id === 1, 'existing donor linked only in plugin payment table');
echo "Passed $count isolation assertions.\n";
