<?php
require __DIR__ . '/bootstrap.php';
if (in_array('--temporary-webhook', $argv, true)) { require __DIR__ . '/routing.php'; }
$count = 0;
function check(bool $condition, string $message): void { global $count; ++$count; if (!$condition) { throw new RuntimeException('FAIL: ' . $message); } }
function reset_state(): void {
    $GLOBALS['wpdb'] = new TestDB(); $GLOBALS['users'] = []; $GLOBALS['stripe'] = []; $GLOBALS['notifications'] = []; $GLOBALS['uuid'] = 0;
    $GLOBALS['http_calls'] = []; $GLOBALS['transients'] = [];
    $GLOBALS['options'] = ['donation_box_settings' => ['test_key' => 'sk_test_abc', 'test_webhook' => 'whsec_secret']];
}
function donation(string $ref = 'ref', string $frequency = 'once'): void {
    Donation_Box_Store::insert(['reference' => $ref, 'mode' => 'test', 'email' => 'usman@example.test', 'first_name' => 'Usman', 'last_name' => 'Malik', 'amount' => 2500, 'currency' => 'eur', 'frequency' => $frequency, 'created_at' => '2026-11-16 10:00:00']);
}
function event(string $id, string $type, array $object, string $mode = 'test', ?bool $live = null) {
    $body = json_encode(['id' => $id, 'type' => $type, 'livemode' => $live ?? ($mode === 'live'), 'data' => ['object' => $object]]);
    $time = time(); $sig = hash_hmac('sha256', $time . '.' . $body, 'whsec_secret');
    if (in_array('--temporary-webhook', $GLOBALS['argv'], true)) {
        return routed_webhook($body, 't=' . $time . ',v1=' . $sig, '/sprocess?mode=live');
    }
    return Donation_Box_API::webhook(new WP_REST_Request(['mode' => $mode], ['stripe-signature' => 't=' . $time . ',v1=' . $sig], $body));
}
reset_state();
foreach (['1' => 100, '25' => 2500, '12.3' => 1230, '12,34' => 1234, '99999.99' => 9999999] as $value => $expected) { check(Donation_Box_Stripe::amount($value) === $expected, 'exact cents ' . $value); }
foreach (['0', '-1', '1.001', '1e3', '100000', '', [], '25<script>'] as $value) { check(is_wp_error(Donation_Box_Stripe::amount($value)), 'reject invalid amount'); }
$time = time(); $payload = '{"id":"evt_1"}'; $sig = hash_hmac('sha256', $time . '.' . $payload, 'whsec_secret');
check(Donation_Box_Stripe::signature($payload, "t=$time,v1=bad,v1=$sig", 'whsec_secret'), 'rotated webhook signature');
check(!Donation_Box_Stripe::signature($payload . ' ', "t=$time,v1=$sig", 'whsec_secret'), 'reject tampered body');
check(!Donation_Box_Stripe::signature($payload, "t=$time,v1=$sig", 'whsec_secret', $time + 301), 'reject stale signature');
check(!Donation_Box_Stripe::signature($payload, "t=$time,v1=$sig", ''), 'reject unconfigured webhook');
check(Donation_Box_Settings::get()['color_primary'] === '#0071DC', 'ABAM primary');
$sanitized = Donation_Box_Settings::sanitize(['color_primary' => '#123456', 'color_text' => 'red;position:fixed', 'test_key' => '', 'amounts' => '15,bad,25']);
check($sanitized['color_primary'] === '#123456', 'custom color');
check($sanitized['color_text'] === '#252525', 'reject color injection');
check($sanitized['test_key'] === 'sk_test_abc', 'blank credential retains saved secret');
check($sanitized['amounts'] === '15,25', 'invalid suggested amounts removed');
check(Donation_Box_Settings::sanitize(['test_key' => 'sk_live_wrong'])['test_key'] === 'sk_test_abc', 'mode mismatch key rejected');
check(Donation_Box_Settings::sanitize(['clear_test_key' => 1])['test_key'] === '', 'explicit clear secret');
check(Donation_Box_API::return_url('https://attacker.test/donate') === 'https://example.test/', 'cross-origin return rejected');
check(Donation_Box_API::return_url('http://example.test/donate') === 'https://example.test/', 'insecure return rejected');
check(Donation_Box_API::return_url('https://example.test:8443/donate') === 'https://example.test/', 'cross-port return rejected');
check(Donation_Box_API::return_url('https://example.test/donate?donation_box=received#x') === 'https://example.test/donate', 'clean own-origin return');
check(Donation_Box_Admin::csv_cell('=IMPORTXML("url")')[0] === "'", 'CSV formula escaped');
check(Donation_Box_Admin::csv_cell("\t+123")[0] === "'", 'CSV whitespace formula escaped');
check(Donation_Box_Admin::csv_cell('normal') === 'normal', 'CSV regular text preserved');
// Public checkout validates before issuing a Stripe API call.
$token = Donation_Box_API::checkout_token();
check($token->data['nonce'] === 'valid' && $token->status === 200, 'public checkout token generated');
check(str_contains($token->headers['Cache-Control'], 'no-store'), 'checkout token response cannot be cached');
$GLOBALS['nonce_value'] = 'fresh';
check(Donation_Box_API::checkout_token()->data['nonce'] === 'fresh', 'checkout token regenerated per request');
unset($GLOBALS['nonce_value']);
check($GLOBALS['http_calls'] === [], 'checkout token never contacts Stripe');
$bad = Donation_Box_API::checkout(new WP_REST_Request([], [], '{}'));
check(is_wp_error($bad) && $bad->data['status'] === 403, 'checkout nonce required');
$input = ['amount' => '25.00', 'email' => 'usman@example.test', 'first_name' => 'Usman', 'last_name' => 'Malik', 'frequency' => 'monthly', 'consent' => 1, 'return_url' => 'https://example.test/donate'];
foreach (['email', 'amount', 'return_url', 'frequency'] as $field) {
    $invalid = $input; $invalid[$field] = [];
    $result = Donation_Box_API::checkout(new WP_REST_Request([], ['x-donation-box-nonce' => 'valid'], json_encode($invalid)));
    check(is_wp_error($result) && $result->data['status'] === 400, 'reject nested JSON field ' . $field);
}
$GLOBALS['stripe']['checkout/sessions'] = ['id' => 'cs_monthly', 'url' => 'https://checkout.stripe.com/c/pay/session'];
$checkout = Donation_Box_API::checkout(new WP_REST_Request([], ['x-donation-box-nonce' => 'valid'], json_encode($input)));
check($checkout instanceof WP_REST_Response, 'monthly checkout created');
check(count($GLOBALS['users']) === 0, 'checkout does not create donor account');
$params = []; parse_str($GLOBALS['http_calls'][0][1]['body'], $params);
check($params['mode'] === 'subscription' && $params['line_items'][0]['price_data']['recurring']['interval'] === 'month', 'recurring price payload');
check(isset($params['subscription_data']['metadata']['donation_box_reference']), 'subscription attribution metadata');
check($GLOBALS['http_calls'][0][1]['headers']['Stripe-Version'] === '2024-06-20', 'pinned Stripe API');
// One-time confirmation, deduplication and refund ordering.
reset_state(); donation();
$session = ['id' => 'cs_one', 'metadata' => ['donation_box_reference' => 'ref'], 'customer' => 'cus_one', 'mode' => 'payment', 'payment_intent' => 'pi_one', 'amount_total' => 2500, 'currency' => 'eur', 'payment_status' => 'paid', 'status' => 'complete'];
$GLOBALS['stripe']['checkout/sessions/cs_one'] = $session;
$GLOBALS['stripe']['payment_intents/pi_one'] = ['latest_charge' => 'ch_one'];
$GLOBALS['stripe']['charges/ch_one'] = ['id' => 'ch_one', 'amount_refunded' => 0, 'refunded' => false, 'payment_intent' => 'pi_one'];
check(!is_wp_error(event('evt_paid', 'checkout.session.completed', $session)), 'successful signed webhook');
$row = Donation_Box_Store::find('ref');
check($row->status === 'paid' && $row->amount === 2500, 'paid donation persisted');
check($row->user_id === 1 && $GLOBALS['users'][1]->role === 'subscriber', 'least-privilege donor linked');
check(count($GLOBALS['notifications']) === 1, 'one welcome notification');
check(!is_wp_error(event('evt_paid', 'checkout.session.completed', $session)), 'duplicate event acknowledged');
check(count($GLOBALS['users']) === 1 && count($GLOBALS['notifications']) === 1, 'duplicates do not recreate user');
$GLOBALS['stripe']['charges/ch_one']['amount_refunded'] = 500;
event('evt_refund', 'charge.refunded', $GLOBALS['stripe']['charges/ch_one']);
check(Donation_Box_Store::find('ref')->status === 'partially_refunded' && Donation_Box_Store::find('ref')->refunded_amount === 500, 'partial refund stored');
event('evt_late', 'checkout.session.completed', $session);
check(Donation_Box_Store::find('ref')->status === 'partially_refunded', 'late paid event cannot undo refund');
check(is_wp_error(event('evt_mode', 'checkout.session.completed', $session, 'test', true)), 'wrong-mode event rejected');
// Unpaid/failed sessions never grant accounts.
reset_state(); donation(); $session['payment_status'] = 'unpaid';
$GLOBALS['stripe']['checkout/sessions/cs_one'] = $session;
event('evt_pending', 'checkout.session.completed', $session);
check(Donation_Box_Store::find('ref')->status === 'pending' && count($GLOBALS['users']) === 0, 'delayed payment remains pending');
event('evt_failed', 'checkout.session.async_payment_failed', $session);
check(Donation_Box_Store::find('ref')->status === 'failed' && count($GLOBALS['users']) === 0, 'failed payment does not create user');
// Initial invoice arrives before Checkout; renewal and out-of-order subscription events.
reset_state(); donation('monthly', 'monthly');
$sub = ['id' => 'sub_one', 'metadata' => ['donation_box_reference' => 'monthly'], 'customer' => 'cus_monthly', 'status' => 'active', 'current_period_end' => 1800000000, 'items' => ['data' => [['price' => ['unit_amount' => 2500, 'currency' => 'eur']]]]];
$GLOBALS['stripe']['subscriptions/sub_one'] = $sub;
$invoice = ['id' => 'in_first', 'subscription' => 'sub_one', 'customer' => 'cus_monthly', 'status' => 'paid', 'currency' => 'eur', 'total' => 2500, 'amount_paid' => 2500, 'billing_reason' => 'subscription_create', 'created' => 1794816000, 'status_transitions' => ['paid_at' => 1794816010]];
$GLOBALS['stripe']['invoices/in_first'] = $invoice;
check(!is_wp_error(event('evt_initial_invoice', 'invoice.paid', $invoice)), 'invoice before checkout accepted');
check(Donation_Box_Store::find('monthly')->status === 'paid', 'first invoice fills pending row');
check($GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM wp_donation_box_payments') === 1, 'first invoice counted once');
$invoice['id'] = 'in_renewal'; $invoice['billing_reason'] = 'subscription_cycle';
$GLOBALS['stripe']['invoices/in_renewal'] = $invoice;
event('evt_renewal', 'invoice.paid', $invoice); event('evt_renewal_second_id', 'invoice.paid', $invoice);
check($GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM wp_donation_box_payments') === 2, 'renewals have idempotent invoice reference');
check(count($GLOBALS['users']) === 1, 'renewals reuse account');
$invoice['id'] = 'in_failed'; $invoice['status'] = 'open';
$GLOBALS['stripe']['invoices/in_failed'] = $invoice;
event('evt_invoice_failed', 'invoice.payment_failed', $invoice);
check(Donation_Box_Store::find('in_failed')->status === 'failed', 'failed renewal visible');
$GLOBALS['stripe']['invoices/in_failed']['status'] = 'paid';
event('evt_invoice_recovered', 'invoice.paid', $invoice);
check(Donation_Box_Store::find('in_failed')->status === 'paid', 'failed renewal recovers on retry');
$GLOBALS['stripe']['subscriptions/sub_one']['status'] = 'canceled';
event('evt_stale_subscription', 'customer.subscription.updated', $sub);
check($GLOBALS['wpdb']->get_var("SELECT status FROM wp_donation_box_subscriptions WHERE stripe_id='sub_one'") === 'canceled', 'stale subscription event uses current Stripe state');
// Portal authorization stays tied to current user and successful payment.
$GLOBALS['current_user'] = 2;
check(is_wp_error(Donation_Box_API::portal(new WP_REST_Request(['customer_id' => 'cus_monthly']))), 'portal refuses another donor customer');
$GLOBALS['current_user'] = 1;
$GLOBALS['stripe']['billing_portal/sessions'] = ['url' => 'https://billing.stripe.com/p/session/test'];
check(isset(Donation_Box_API::portal(new WP_REST_Request(['customer_id' => 'cus_monthly', 'return_url' => 'https://example.test/account']))['url']), 'owner can open portal');
// Retriable processing errors must not acknowledge an event.
$result = event('evt_error', 'invoice.paid', ['id' => 'in_missing', 'subscription' => 'sub_one']);
check(is_wp_error($result) && $result->data['status'] === 500, 'processing failure returns retry status');
check(!$GLOBALS['wpdb']->get_var("SELECT event_id FROM wp_donation_box_events WHERE event_id='evt_error'"), 'failed event not marked processed');
echo "Passed $count assertions.\n";
