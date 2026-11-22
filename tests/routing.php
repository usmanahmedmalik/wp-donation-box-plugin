<?php
require_once __DIR__ . '/bootstrap.php';
// WordPress hook/REST boundaries. No rewrite APIs or permalink refresh stubs:
// the alias must work with an empty parsed request, including a plain-permalink 404.
function add_action($hook, $callback, $priority = 10, ...$args) { $GLOBALS['route_hooks'][$hook][$priority][] = $callback; }
function register_rest_route($namespace, $route, $args) { $GLOBALS['route_registry']['/' . $namespace . $route] = $args; }
function routing_hook(string $hook, ...$args): void {
    $callbacks = $GLOBALS['route_hooks'][$hook] ?? []; ksort($callbacks);
    foreach ($callbacks as $group) { foreach ($group as $callback) { $callback(...$args); } }
}
Donation_Box_API::boot();
routing_hook('rest_api_init');
function routed_webhook(string $body, string $signature, string $uri = '/sprocess', string $method = 'POST', string $rest_route = '/unrelated') {
    $_SERVER['REQUEST_URI'] = $uri; $_SERVER['REQUEST_METHOD'] = $method;
    $wp = (object) ['query_vars' => ['error' => '404', 'rest_route' => $rest_route]];
    routing_hook('parse_request', $wp);
    // Model core rest_api_loaded: dispatch the parsed REST route using the
    // unchanged raw body and header. Core prioritizes query/body over URL params
    // for get_param()/ArrayAccess, so the handler must explicitly use URL params.
    foreach ($GLOBALS['route_registry'] as $route => $args) {
        if (!preg_match('#^' . $route . '$#D', $wp->query_vars['rest_route'], $match)) { continue; }
        if ($args['methods'] !== $method) { return new WP_Error('method', '', ['status' => 405]); }
        parse_str(parse_url($uri, PHP_URL_QUERY) ?? '', $params);
        $url_params = array_filter($match, 'is_string', ARRAY_FILTER_USE_KEY);
        $request = new WP_REST_Request($params + $url_params, ['stripe-signature' => $signature], $body);
        $request->url_params = $url_params;
        return ($args['callback'])($request);
    }
    return new WP_Error('no_route', '', ['status' => 404]);
}
if (realpath($_SERVER['SCRIPT_FILENAME']) !== __FILE__) { return; }
$count = 0;
function route_check(bool $ok, string $message): void { global $count; ++$count; if (!$ok) { throw new RuntimeException('FAIL: ' . $message); } }
route_check(isset($GLOBALS['route_hooks']['parse_request'][0]), 'alias registered before core REST dispatch at priority 10');
route_check(count($GLOBALS['route_registry']) === 4, 'original REST routes retained alongside checkout-token');
foreach ([['GET', '/sprocess'], ['HEAD', '/sprocess'], ['OPTIONS', '/sprocess'], ['POST', '/sprocess/'], ['POST', '/sprocess/extra'], ['POST', '/sprocess-other'], ['POST', '/Sprocess'], ['POST', '/donate'], ['POST', '/wp-json/donation-box/v1/webhook/live']] as [$method, $uri]) {
    $_SERVER['REQUEST_METHOD'] = $method; $_SERVER['REQUEST_URI'] = $uri;
    $wp = (object) ['query_vars' => ['pagename' => 'original', 'rest_route' => '/existing']];
    routing_hook('parse_request', $wp);
    route_check($wp->query_vars === ['pagename' => 'original', 'rest_route' => '/existing'], "$method $uri preserves website routing");
}
foreach (['/sprocess', '/sprocess?mode=live&rest_route=/donation-box/v1/webhook/live'] as $uri) {
    $_SERVER['REQUEST_METHOD'] = 'POST'; $_SERVER['REQUEST_URI'] = $uri;
    $wp = (object) ['query_vars' => ['error' => '404']];
    routing_hook('parse_request', $wp);
    route_check($wp->query_vars['rest_route'] === '/donation-box/v1/webhook/test', 'test route works without a matching rewrite rule and overrides query mode');
}
$GLOBALS['wpdb'] = new TestDB();
$GLOBALS['options'] = ['donation_box_settings' => ['mode' => 'live', 'test_webhook' => 'whsec_test', 'live_webhook' => 'whsec_live']];
$body = "{\n  \"id\": \"evt_alias\", \"type\": \"unhandled.test\", \"livemode\": false, \"data\": {\"object\": {}}\n}";
function route_signature(string $body, string $secret): string { $time = time(); return 't=' . $time . ',v1=' . hash_hmac('sha256', $time . '.' . $body, $secret); }
$signature = route_signature($body, 'whsec_test');
$result = routed_webhook($body, $signature, '/sprocess?mode=live');
route_check($result === ['received' => true], 'signed raw test payload accepted while form is live');
route_check(routed_webhook($body, $signature) === ['received' => true], 'duplicate accepted via alias');
route_check($GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM wp_donation_box_events') === 1, 'duplicate recorded once');
route_check(routed_webhook($body, $signature, '/wp-json/donation-box/v1/webhook/test', 'POST', '/donation-box/v1/webhook/test') === ['received' => true], 'existing REST test endpoint accepts shared duplicate');
route_check($GLOBALS['wpdb']->get_var('SELECT COUNT(*) FROM wp_donation_box_events') === 1, 'duplicate protection shared between alias and REST');
foreach ([[$body, ''], [$body, route_signature($body, 'whsec_live')], [$body . ' ', $signature]] as [$payload, $header]) {
    $result = routed_webhook($payload, $header);
    route_check(is_wp_error($result) && $result->data['status'] === 400 && $result->code === 'signature', 'missing/wrong/tampered signature rejected even for duplicate');
}
$live = str_replace('false', 'true', $body);
$result = routed_webhook($live, route_signature($live, 'whsec_test'));
route_check(is_wp_error($result) && $result->code === 'event', 'live event rejected with valid test signature');
$live = str_replace('evt_alias', 'evt_live', $live);
route_check(routed_webhook($live, route_signature($live, 'whsec_live'), '/wp-json/donation-box/v1/webhook/live?mode=test', 'POST', '/donation-box/v1/webhook/live') === ['received' => true], 'existing REST live endpoint uses live secret despite query override');
$GLOBALS['options']['donation_box_settings']['test_webhook'] = '';
$result = routed_webhook($body, $signature);
route_check(is_wp_error($result) && $result->code === 'signature', 'missing configured test secret fails closed');
echo "Passed $count routing assertions.\n";
