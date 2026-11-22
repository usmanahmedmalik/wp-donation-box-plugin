<?php
// Exercise the actual admin query templates with the SQLite WordPress boundary.
require __DIR__ . '/render.php';
function absint($value) { return abs((int) $value); }
function wp_nonce_url($url, $action) { return $url; }
function admin_url($path) { return 'https://example.test/wp-admin/' . $path; }
function wp_kses_post($html) { return $html; }
function paginate_links($args) { $GLOBALS['pagination'] = $args; return ''; }
function get_edit_user_link($id) { return 'https://example.test/wp-admin/user-edit.php?user_id=' . $id; }
function render_report(string $method): string {
    ob_start();
    Donation_Box_Admin::$method();
    return ob_get_clean();
}

$GLOBALS['wpdb'] = new TestDB();
$GLOBALS['options'] = ['donation_box_settings' => ['mode' => 'test']];
foreach ([['test', 'paid', 'alice@example.test'], ['test', 'failed', 'bob@example.test'], ['live', 'paid', 'live@example.test']] as [$mode, $status, $email]) {
    Donation_Box_Store::insert([
        'reference' => $email, 'mode' => $mode, 'status' => $status,
        'email' => $email, 'amount' => 2500, 'currency' => 'eur',
        'frequency' => 'once', 'created_at' => '2026-11-22 10:00:00',
    ]);
}
$_GET = [];
$html = render_report('payments');
verify(str_contains($html, 'alice@example.test') && str_contains($html, 'bob@example.test'), 'unfiltered test reporting includes successful and failed records');
verify(!str_contains($html, 'live@example.test'), 'test reports exclude live payments');
$_GET = ['mode' => 'live', 'status' => 'paid'];
$html = render_report('payments');
verify(str_contains($html, 'live@example.test') && !str_contains($html, 'alice@example.test'), 'live and status filters use bound values');
$_GET = ['status' => 'paid', 'search' => 'alice'];
$html = render_report('payments');
verify(str_contains($html, 'alice@example.test') && !str_contains($html, 'bob@example.test'), 'combined status and email filtering');
$_GET = ['search' => "' OR 1=1 --"];
verify(str_contains(render_report('payments'), 'No records found.'), 'SQL-like input cannot broaden report results');
$_GET = ['mode' => ['live'], 'status' => ['paid'], 'search' => ['alice'], 'paged' => ['2']];
$html = render_report('payments');
verify(str_contains($html, 'alice@example.test') && !str_contains($html, 'live@example.test'), 'nested URL inputs safely use defaults');
$_GET = [];
$html = render_report('donors');
verify(str_contains($html, 'alice@example.test') && !str_contains($html, 'bob@example.test'), 'donor aggregation includes only confirmed donors');
verify(str_contains(render_report('dashboard'), 'EUR 25.00'), 'dashboard totals remain environment-specific');
try {
    Donation_Box_Store::table('payments; DROP TABLE users');
    verify(false, 'unknown table rejected');
} catch (InvalidArgumentException $e) {
    verify(true, 'unknown table rejected');
}
$_GET = [];
echo "Passed reporting and input regression checks.\n";
