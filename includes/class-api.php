<?php
if (!defined('ABSPATH')) { exit; }
final class Donation_Box_API {
    public static function boot(): void {
        add_action('parse_request', [self::class, 'temporary_test_webhook'], 0);
        add_action('rest_api_init', static function () {
            register_rest_route('donation-box/v1', '/checkout-token', ['methods' => 'POST', 'callback' => [self::class, 'checkout_token'], 'permission_callback' => '__return_true']);
            register_rest_route('donation-box/v1', '/checkout', ['methods' => 'POST', 'callback' => [self::class, 'checkout'], 'permission_callback' => '__return_true']);
            register_rest_route('donation-box/v1', '/webhook/(?P<mode>test|live)', ['methods' => 'POST', 'callback' => [self::class, 'webhook'], 'permission_callback' => '__return_true']);
            register_rest_route('donation-box/v1', '/portal', ['methods' => 'POST', 'callback' => [self::class, 'portal'], 'permission_callback' => static function () { return is_user_logged_in(); }]);
        });
    }
    public static function temporary_test_webhook($wp): void {
        if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST' || wp_parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) !== '/sprocess') { return; }
        // Core's rest_api_loaded runs next (priority 10), preserving the raw body,
        // Stripe-Signature header and REST responses. No rewrite rules to flush.
        // Always use test mode, regardless of form settings or query parameters.
        $wp->query_vars['rest_route'] = '/donation-box/v1/webhook/test';
    }
    public static function checkout_token(): WP_REST_Response {
        // This public form deliberately uses anonymous requests, including when
        // the visitor has a wp-admin session. Fetch outside the HTML page cache.
        $response = new WP_REST_Response(['nonce' => wp_create_nonce('donation_box_checkout')]);
        $response->header('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0');
        $response->header('Pragma', 'no-cache');
        return $response;
    }
    public static function checkout(WP_REST_Request $request) {
        if (!wp_verify_nonce($request->get_header('X-Donation-Box-Nonce'), 'donation_box_checkout')) {
            return new WP_Error('nonce', __('Refresh the page and try again.', 'donation-box'), ['status' => 403]);
        }
        $input = $request->get_json_params();
        if (!is_array($input) || !empty($input['website'])) { return new WP_Error('input', __('Unable to start checkout.', 'donation-box'), ['status' => 400]); }
        foreach (['amount', 'email', 'first_name', 'last_name', 'phone', 'frequency', 'return_url', 'website'] as $field) {
            if (isset($input[$field]) && !is_string($input[$field]) && !($field === 'amount' && is_numeric($input[$field]))) {
                return new WP_Error('input', __('Unable to start checkout.', 'donation-box'), ['status' => 400]);
            }
        }
        // Use the direct peer address; never trust a visitor-supplied forwarding header.
        $bucket = 'donation_box_rate_' . hash_hmac('sha256', $_SERVER['REMOTE_ADDR'] ?? 'unknown', wp_salt('nonce'));
        $attempts = (int) get_transient($bucket);
        if ($attempts >= 10) { return new WP_Error('rate_limit', __('Please wait a few minutes before trying again.', 'donation-box'), ['status' => 429]); }
        set_transient($bucket, $attempts + 1, 10 * MINUTE_IN_SECONDS);
        $amount = Donation_Box_Stripe::amount($input['amount'] ?? '');
        if (is_wp_error($amount)) { return $amount; }
        $email = sanitize_email($input['email'] ?? '');
        $first = sanitize_text_field($input['first_name'] ?? '');
        $last = sanitize_text_field($input['last_name'] ?? '');
        $phone = sanitize_text_field($input['phone'] ?? '');
        $frequency = $input['frequency'] ?? '';
        if (!is_email($email) || strlen($email) > 191 || !$first || !$last || strlen($first) > 100 || strlen($last) > 100 || strlen($phone) > 50 || !in_array($frequency, ['once', 'monthly'], true) || empty($input['consent'])) {
            return new WP_Error('input', __('Check your name, email and consent.', 'donation-box'), ['status' => 400]);
        }
        $settings = Donation_Box_Settings::get(); $mode = $settings['mode'];
        if (!Donation_Box_Settings::secret($mode) || !Donation_Box_Settings::secret($mode, 'webhook')) {
            return new WP_Error('configuration', __('Donations are not available yet. Please contact the site administrator.', 'donation-box'), ['status' => 503]);
        }
        if ($mode === 'live' && !is_ssl()) { return new WP_Error('https', __('Secure HTTPS is required for live donations.', 'donation-box'), ['status' => 503]); }
        $reference = wp_generate_uuid4(); $id = 0;
        try {
            $id = Donation_Box_Store::insert([
                'reference' => $reference, 'mode' => $mode, 'email' => $email,
                'first_name' => $first, 'last_name' => $last, 'phone' => $phone,
                'amount' => $amount, 'currency' => $settings['currency'], 'frequency' => $frequency,
                'created_at' => current_time('mysql', true),
            ]);
            $return = self::return_url($input['return_url'] ?? '');
            $metadata = ['donation_box_reference' => $reference];
            $price = ['currency' => $settings['currency'], 'unit_amount' => $amount, 'product_data' => ['name' => $settings['title']]];
            if ($frequency === 'monthly') { $price['recurring'] = ['interval' => 'month']; }
            $params = [
                'mode' => $frequency === 'monthly' ? 'subscription' : 'payment',
                'customer_email' => $email, 'client_reference_id' => $reference, 'metadata' => $metadata,
                'line_items' => [['quantity' => 1, 'price_data' => $price]],
                'success_url' => add_query_arg('donation_box', 'received', $return),
                'cancel_url' => add_query_arg('donation_box', 'cancelled', $return),
            ];
            if ($frequency === 'monthly') { $params['subscription_data'] = ['metadata' => $metadata]; }
            else { $params['customer_creation'] = 'always'; $params['payment_intent_data'] = ['metadata' => $metadata]; }
            $session = Donation_Box_Stripe::request($mode, 'checkout/sessions', $params, 'POST', $reference);
            $url = Donation_Box_Stripe::url($session, 'checkout.stripe.com');
            Donation_Box_Store::update($id, ['session_id' => $session['id']]);
            return new WP_REST_Response(['url' => $url], 200);
        } catch (Throwable $e) {
            // Do not log donor details, keys, or raw Stripe responses.
            if ($id) { Donation_Box_Store::update($id, ['status' => 'checkout_error']); }
            return new WP_Error('stripe', __('Unable to connect to Stripe. Please try again later.', 'donation-box'), ['status' => 502]);
        }
    }
    public static function return_url(string $value): string {
        $home = home_url('/');
        $parts = wp_parse_url($value); $base = wp_parse_url($home);
        if (!is_array($parts) || isset($parts['user']) || isset($parts['pass']) || ($parts['host'] ?? '') !== ($base['host'] ?? '') || ($parts['scheme'] ?? '') !== ($base['scheme'] ?? '') || ($parts['port'] ?? null) !== ($base['port'] ?? null)) { return $home; }
        return remove_query_arg(['donation_box', 'session_id'], strtok($value, '#'));
    }
    public static function portal(WP_REST_Request $request) {
        global $wpdb;
        // WP REST authenticates the logged-in cookie with X-WP-Nonce.
        $mode = Donation_Box_Settings::get()['mode'];
        $customer = sanitize_text_field($request->get_param('customer_id') ?? '');
        $owned = $wpdb->get_var($wpdb->prepare('SELECT customer_id FROM ' . Donation_Box_Store::table('payments') . " WHERE user_id = %d AND mode = %s AND status IN ('paid','refunded','partially_refunded') AND customer_id = %s LIMIT 1", get_current_user_id(), $mode, $customer));
        if (!$owned) { return new WP_Error('customer', __('No matching donor account.', 'donation-box'), ['status' => 404]); }
        try {
            $session = Donation_Box_Stripe::request($mode, 'billing_portal/sessions', ['customer' => $owned, 'return_url' => self::return_url((string) $request->get_param('return_url'))]);
            return ['url' => Donation_Box_Stripe::url($session, 'billing.stripe.com')];
        } catch (Throwable $e) { return new WP_Error('stripe', __('The donor portal is unavailable. Please contact the site administrator.', 'donation-box'), ['status' => 502]); }
    }
    public static function webhook(WP_REST_Request $request) {
        global $wpdb;
        // Bind the signing secret and processing mode to the matched route,
        // never to query/JSON parameters which REST otherwise prioritizes.
        $mode = $request->get_url_params()['mode']; $payload = $request->get_body();
        if (strlen($payload) > 1048576 || !Donation_Box_Stripe::signature($payload, $request->get_header('stripe-signature'), Donation_Box_Settings::secret($mode, 'webhook'))) {
            return new WP_Error('signature', 'Invalid signature', ['status' => 400]);
        }
        $event = json_decode($payload, true);
        if (!is_array($event) || empty($event['id']) || !isset($event['livemode'], $event['data']['object'], $event['type']) || (bool) $event['livemode'] !== ($mode === 'live')) {
            return new WP_Error('event', 'Invalid event or mode', ['status' => 400]);
        }
        // Serialize handlers across workers; a crashed worker releases its connection lock.
        $lock = 'dbox_' . md5(Donation_Box_Store::table('events'));
        if ((int) $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, 0)', $lock)) !== 1) {
            return new WP_Error('busy', 'Retry later', ['status' => 503]);
        }
        try {
            $table = Donation_Box_Store::table('events');
            if ($wpdb->get_var($wpdb->prepare("SELECT event_id FROM $table WHERE event_id = %s", $event['id']))) { return ['received' => true]; }
            self::handle($event['type'], $event['data']['object'], $mode);
            if (!$wpdb->insert($table, ['event_id' => $event['id'], 'processed_at' => current_time('mysql', true)])) { throw new RuntimeException('Event persistence failed'); }
            return ['received' => true];
        } catch (Throwable $e) {
            return new WP_Error('processing', 'Processing failed; retry delivery', ['status' => 500]);
        } finally { $wpdb->get_var($wpdb->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
    public static function handle(string $type, array $object, string $mode): void {
        if (in_array($type, ['checkout.session.completed', 'checkout.session.async_payment_succeeded', 'checkout.session.async_payment_failed', 'checkout.session.expired'], true)) {
            $row = Donation_Box_Store::find($object['metadata']['donation_box_reference'] ?? '');
            if (!$row || $row->mode !== $mode) { return; }
            // Fetch current state so stale failed/expired events cannot undo a success.
            $session = Donation_Box_Stripe::request($mode, 'checkout/sessions/' . rawurlencode($object['id']), [], 'GET');
            if (($session['metadata']['donation_box_reference'] ?? '') !== $row->reference) { throw new RuntimeException('Session reference mismatch'); }
            $data = ['session_id' => $session['id'], 'customer_id' => $session['customer'] ?? '', 'subscription_id' => $session['subscription'] ?? ''];
            if ($row->frequency === 'once') {
                if ((int) ($session['amount_total'] ?? 0) !== (int) $row->amount || ($session['currency'] ?? '') !== $row->currency) { throw new RuntimeException('Payment amount mismatch'); }
                if (($session['payment_status'] ?? '') === 'paid') {
                    $data += self::settled_status($session['payment_intent'] ?? '', $mode) + ['stripe_id' => $session['payment_intent'] ?? '', 'paid_at' => $row->paid_at ?: gmdate('Y-m-d H:i:s')];
                } elseif (!in_array($row->status, ['paid', 'refunded', 'partially_refunded'], true)) {
                    $data['status'] = ($session['status'] ?? '') === 'expired' ? 'expired' : ($type === 'checkout.session.async_payment_failed' ? 'failed' : 'pending');
                }
            }
            Donation_Box_Store::update((int) $row->id, $data);
            Donation_Box_Store::link_user(Donation_Box_Store::find($row->reference));
            if (!empty($session['subscription'])) { self::subscription($session['subscription'], $mode); }
        } elseif (in_array($type, ['invoice.paid', 'invoice.payment_failed'], true)) {
            $sid = $object['subscription'] ?? $object['parent']['subscription_details']['subscription'] ?? '';
            if (!$sid) { return; }
            $subscription = self::subscription($sid, $mode);
            if (!$subscription) { return; }
            $template = Donation_Box_Store::find($subscription['metadata']['donation_box_reference']);
            $invoice = Donation_Box_Stripe::request($mode, 'invoices/' . rawurlencode($object['id']), [], 'GET');
            if (($invoice['currency'] ?? '') !== $template->currency || (int) ($invoice['total'] ?? -1) !== (int) $template->amount) { throw new RuntimeException('Invoice amount mismatch'); }
            $paid = ($invoice['status'] ?? '') === 'paid';
            // A subscription's first invoice fills the pending checkout row. Renewals have their own unique reference.
            $ref = ($invoice['billing_reason'] ?? '') === 'subscription_create' ? $template->reference : $invoice['id'];
            $row = Donation_Box_Store::find($ref);
            $data = ['customer_id' => $invoice['customer'], 'subscription_id' => $sid, 'stripe_id' => $invoice['id']];
            if ($paid) { $data += self::settled_status($invoice['payment_intent'] ?? '', $mode) + ['paid_at' => gmdate('Y-m-d H:i:s', $invoice['status_transitions']['paid_at'] ?? time())]; }
            elseif (!$row || !in_array($row->status, ['paid', 'refunded', 'partially_refunded'], true)) { $data['status'] = 'failed'; }
            if ($row) { Donation_Box_Store::update((int) $row->id, $data); }
            else {
                Donation_Box_Store::insert($data + [
                    'reference' => $ref, 'mode' => $mode, 'email' => $template->email,
                    'first_name' => $template->first_name, 'last_name' => $template->last_name,
                    'phone' => $template->phone, 'amount' => $template->amount, 'currency' => $template->currency,
                    'frequency' => 'monthly', 'created_at' => gmdate('Y-m-d H:i:s', $invoice['created']),
                ]);
            }
            Donation_Box_Store::link_user(Donation_Box_Store::find($ref));
        } elseif (in_array($type, ['customer.subscription.created', 'customer.subscription.updated', 'customer.subscription.deleted'], true)) {
            // Ignore unrelated subscriptions before issuing API requests.
            if (!empty($object['metadata']['donation_box_reference'])) { self::subscription($object['id'], $mode); }
        } elseif ($type === 'charge.refunded') {
            $intent = $object['payment_intent'] ?? '';
            if (!$intent) { return; }
            $charge = Donation_Box_Stripe::request($mode, 'charges/' . rawurlencode($object['id']), [], 'GET');
            $stripe_id = $charge['invoice'] ?? $intent;
            global $wpdb;
            $status = !empty($charge['refunded']) ? 'refunded' : 'partially_refunded';
            if ($wpdb->query($wpdb->prepare('UPDATE ' . Donation_Box_Store::table('payments') . ' SET status = %s, refunded_amount = %d WHERE stripe_id = %s AND mode = %s', $status, (int) ($charge['amount_refunded'] ?? 0), $stripe_id, $mode)) === false) { throw new RuntimeException('Refund persistence failed'); }
        }
    }
    private static function settled_status(string $intent_id, string $mode): array {
        if (!$intent_id) { return ['status' => 'paid', 'refunded_amount' => 0]; }
        $intent = Donation_Box_Stripe::request($mode, 'payment_intents/' . rawurlencode($intent_id), [], 'GET');
        if (empty($intent['latest_charge'])) { return ['status' => 'paid', 'refunded_amount' => 0]; }
        $charge = Donation_Box_Stripe::request($mode, 'charges/' . rawurlencode($intent['latest_charge']), [], 'GET');
        $refunded = (int) ($charge['amount_refunded'] ?? 0);
        return ['status' => !empty($charge['refunded']) ? 'refunded' : ($refunded > 0 ? 'partially_refunded' : 'paid'), 'refunded_amount' => $refunded];
    }
    private static function subscription(string $id, string $mode): ?array {
        global $wpdb;
        $sub = Donation_Box_Stripe::request($mode, 'subscriptions/' . rawurlencode($id), [], 'GET');
        $row = Donation_Box_Store::find($sub['metadata']['donation_box_reference'] ?? '');
        if (!$row || $row->mode !== $mode || $row->frequency !== 'monthly') { return null; }
        $item = $sub['items']['data'][0] ?? [];
        $data = [
            'stripe_id' => $id, 'mode' => $mode, 'reference' => $row->reference,
            'customer_id' => $sub['customer'], 'email' => $row->email,
            'amount' => $item['price']['unit_amount'] ?? $row->amount, 'currency' => $item['price']['currency'] ?? $row->currency,
            'status' => $sub['status'], 'cancel_at_period_end' => (int) ($sub['cancel_at_period_end'] ?? false),
            'period_end' => $sub['current_period_end'] ?? $item['current_period_end'] ?? 0,
            'updated_at' => current_time('mysql', true),
        ];
        $existing = $wpdb->get_var($wpdb->prepare('SELECT id FROM ' . Donation_Box_Store::table('subscriptions') . ' WHERE stripe_id = %s', $id));
        $result = $existing ? $wpdb->update(Donation_Box_Store::table('subscriptions'), $data, ['id' => $existing]) : $wpdb->insert(Donation_Box_Store::table('subscriptions'), $data);
        if ($result === false) { throw new RuntimeException('Subscription persistence failed'); }
        Donation_Box_Store::update((int) $row->id, ['customer_id' => $sub['customer'], 'subscription_id' => $id]);
        return $sub;
    }
}
