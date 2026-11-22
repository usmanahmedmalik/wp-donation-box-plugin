<?php
if (!defined('ABSPATH')) { exit; }
final class Donation_Box_Stripe {
    // Pin response shape, including invoice.subscription and subscription.current_period_end.
    public const API_VERSION = '2024-06-20';
    public static function amount($value) {
        if (!is_scalar($value) || !preg_match('/^\d{1,5}(?:[.,]\d{1,2})?$/D', (string) $value)) {
            return new WP_Error('amount', __('Enter an amount with at most two decimal places.', 'donation-box'), ['status' => 400]);
        }
        $parts = explode('.', str_replace(',', '.', (string) $value));
        $cents = (int) $parts[0] * 100 + (int) str_pad($parts[1] ?? '', 2, '0');
        if ($cents < 100 || $cents > 9999999) { return new WP_Error('amount', __('Choose an amount between 1 and 99,999.99.', 'donation-box'), ['status' => 400]); }
        return $cents;
    }
    public static function request(string $mode, string $path, array $data = [], string $method = 'POST', string $idempotency = ''): array {
        $key = Donation_Box_Settings::secret($mode);
        if (!$key) { throw new RuntimeException('Stripe is not configured'); }
        $headers = ['Authorization' => 'Bearer ' . $key, 'Stripe-Version' => self::API_VERSION];
        if ($idempotency) { $headers['Idempotency-Key'] = $idempotency; }
        $args = ['method' => $method, 'timeout' => 25, 'redirection' => 0, 'headers' => $headers];
        if ($method !== 'GET') { $args['body'] = http_build_query($data, '', '&'); $args['headers']['Content-Type'] = 'application/x-www-form-urlencoded'; }
        $response = wp_remote_request('https://api.stripe.com/v1/' . $path, $args);
        if (is_wp_error($response)) { throw new RuntimeException('Stripe connection failed'); }
        $body = json_decode(wp_remote_retrieve_body($response), true);
        if (wp_remote_retrieve_response_code($response) >= 300 || !is_array($body) || isset($body['error'])) { throw new RuntimeException('Stripe request failed'); }
        return $body;
    }
    public static function signature(string $payload, string $header, string $secret, ?int $now = null): bool {
        if (!$secret) { return false; }
        $timestamp = null; $signatures = [];
        foreach (explode(',', $header) as $part) {
            $pair = explode('=', trim($part), 2);
            if (count($pair) !== 2) { continue; }
            if ($pair[0] === 't' && ctype_digit($pair[1])) { $timestamp = (int) $pair[1]; }
            if ($pair[0] === 'v1') { $signatures[] = $pair[1]; }
        }
        if (!$timestamp || abs(($now ?? time()) - $timestamp) > 300) { return false; }
        $expected = hash_hmac('sha256', $timestamp . '.' . $payload, $secret);
        foreach ($signatures as $signature) { if (hash_equals($expected, $signature)) { return true; } }
        return false;
    }
    public static function url(array $response, string $host): string {
        $url = $response['url'] ?? '';
        if (wp_parse_url($url, PHP_URL_SCHEME) !== 'https' || wp_parse_url($url, PHP_URL_HOST) !== $host) { throw new RuntimeException('Invalid Stripe redirect'); }
        return $url;
    }
}
