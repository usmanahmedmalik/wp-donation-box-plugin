<?php
require __DIR__ . '/bootstrap.php';
define('DONATION_BOX_FILE', dirname(__DIR__) . '/donation-box.php');
function esc_attr($value) { return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8'); }
function esc_html($value) { return esc_attr($value); }
function esc_url($value) { return esc_attr($value); }
function esc_html__($value, $domain = '') { return esc_html($value); }
function esc_html_e($value, $domain = '') { echo esc_html($value); }
function esc_attr_e($value, $domain = '') { echo esc_attr($value); }
function wp_enqueue_style(...$args) {}
function wp_enqueue_script(...$args) {}
function wp_localize_script($handle, $name, $data) { $GLOBALS['localized'][$name] = $data; }
function wp_unique_id($prefix = '') { static $id = 0; return $prefix . ++$id; }
function wp_get_current_user() { return (object) ['first_name' => '', 'last_name' => '', 'user_email' => '']; }
function rest_url($path) { return 'https://example.test/wp-json/' . $path; }
function number_format_i18n($value, $decimals) { return number_format($value, $decimals); }
function get_privacy_policy_url() { return 'https://example.test/privacy'; }
function current_user_can($capability) { return true; }
function settings_errors(...$args) {}
function settings_fields($group) { echo '<input type="hidden" name="option_page" value="' . esc_attr($group) . '">'; }
function selected($current, $value, $display = true) { $out = $current === $value ? 'selected="selected"' : ''; if ($display) { echo $out; } return $out; }
function submit_button() { echo '<button type="submit">Save</button>'; }
function is_user_logged_in() { return false; }
function wp_login_url($url) { return 'https://example.test/wp-login.php?redirect_to=' . urlencode($url); }
function get_permalink() { return 'https://example.test/donor-account'; }
require __DIR__ . '/../includes/class-frontend.php';
$checks = 0;
function verify(bool $condition, string $description): void { global $checks; ++$checks; if (!$condition) { throw new RuntimeException('FAIL: ' . $description); } }
function document(string $html): DOMXPath {
    $dom = new DOMDocument(); libxml_use_internal_errors(true); $dom->loadHTML('<?xml encoding="utf-8" ?>' . $html); libxml_clear_errors(); return new DOMXPath($dom);
}
$GLOBALS['options'] = ['donation_box_settings' => ['test_key' => 'sk_test_do_not_render', 'test_webhook' => 'whsec_do_not_render']];
$one = Donation_Box_Frontend::form(); $two = Donation_Box_Frontend::form(); $xpath = document($one . $two);
verify($xpath->query('//form')->length === 2, 'multiple independent donation forms render');
verify($xpath->query('//form[@data-token-endpoint="https://example.test/wp-json/donation-box/v1/checkout-token"]')->length === 2, 'forms fetch tokens outside the page cache');
verify($xpath->query('//form[@data-nonce]')->length === 0, 'checkout markup does not cache user-bound nonces');
$ids = []; foreach ($xpath->query('//*[@id]') as $node) { $ids[] = $node->getAttribute('id'); }
verify(count($ids) === count(array_unique($ids)), 'multiple shortcodes have unique field IDs');
foreach ($xpath->query('//label[@for]') as $label) { verify($xpath->query('//*[@id="' . $label->getAttribute('for') . '"]')->length === 1, 'label associated with one input'); }
verify($xpath->query('//*[@data-step="2" and @hidden]')->length === 2, 'details hidden until continue');
verify($xpath->query('//*[@data-step="2"]//input[not(@disabled)]')->length === 0, 'hidden required fields do not block first step');
verify($xpath->query('//button[@data-amount]')->length === 12, 'ABAM suggested amounts on both forms');
verify($xpath->query('//input[@name="amount" and @min="1" and @step="0.01"]')->length === 2, 'decimal input constraints');
verify(str_contains($one, '--dbox-primary:#0071DC'), 'ABAM primary in form');
verify(str_contains($one, '--dbox-secondary:#FFC221'), 'ABAM accent in form');
verify(str_contains($one, 'Test mode'), 'test mode visible');
verify(!str_contains($one, 'sk_test_do_not_render') && !str_contains($one, 'whsec_do_not_render'), 'public markup contains no Stripe secrets');
verify(isset($GLOBALS['localized']['DonationBoxI18n']['connection']), 'JavaScript errors are translatable');
$GLOBALS['options']['donation_box_settings']['title'] = '<script>alert(1)</script>';
verify(!str_contains(Donation_Box_Frontend::form(), '<script>alert'), 'configured title escaped');
$GLOBALS['options']['donation_box_settings']['color_primary'] = '#123456';
verify(str_contains(Donation_Box_Frontend::form(), '--dbox-primary:#123456'), 'configured color used');
$GLOBALS['options']['donation_box_settings']['mode'] = 'live';
verify(!str_contains(Donation_Box_Frontend::form(), 'Test mode'), 'live form has no test badge');
$GLOBALS['options']['donation_box_settings']['mode'] = 'test';
$GLOBALS['options']['donation_box_settings']['title'] = 'Make a difference';
ob_start(); Donation_Box_Admin::settings(); $settings = ob_get_clean(); $admin = document($settings);
verify(!str_contains($settings, 'sk_test_do_not_render') && !str_contains($settings, 'whsec_do_not_render'), 'admin never re-renders saved credentials');
verify($admin->query('//input[@type="password" and @value=""]')->length === 4, 'four empty test/live secret fields');
verify($admin->query('//input[@type="color"]')->length === 9, 'nine configurable palette colors');
verify(str_contains($settings, 'webhook/test') && str_contains($settings, 'webhook/live'), 'separate generated webhook URLs');
verify($admin->query('//input[@readonly]')->length === 2, 'webhook URL fields are read-only');
verify($admin->query('//input[@readonly and @value="https://abamcentre.nl/sprocess"]')->length === 0, 'temporary testing destination is not displayed');
verify(!str_contains($settings, 'Temporary testing webhook URL'), 'temporary webhook field label removed');
verify(str_contains(Donation_Box_Frontend::account(), 'wp-login.php'), 'guest donor account requires login');
if (in_array('--preview', $argv, true)) {
    $css = file_get_contents(__DIR__ . '/../assets/donation-box.css');
    $js = file_get_contents(__DIR__ . '/../assets/donation-box.js');
    $config = json_encode($GLOBALS['localized']['DonationBoxI18n']);
    file_put_contents('/tmp/donation-box-preview.html', '<!doctype html><html lang="en"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Donation Box preview</title><style>body{margin:0;background:#f7f8fa;padding:20px}' . $css . '</style><body>' . $one . '<script>window.DonationBoxI18n=' . $config . ';</script><script>' . $js . '</script></body></html>');
    echo "Preview: /tmp/donation-box-preview.html\n";
}
echo "Passed $checks render assertions.\n";
