<?php
// Small WordPress boundary double backed by SQLite. No network or real donations.
define('ABSPATH', __DIR__ . '/');
define('MINUTE_IN_SECONDS', 60);
define('ARRAY_A', 'ARRAY_A');
class WP_Error {
    public function __construct(public string $code, public string $message = '', public array $data = []) {}
}
class WP_REST_Response {
    public array $headers = [];
    public function __construct(public $data, public int $status = 200) {}
    public function header($name, $value) { $this->headers[$name] = $value; }
}
class WP_REST_Request implements ArrayAccess {
    public ?array $url_params = null;
    public function __construct(public array $params = [], public array $headers = [], public string $body = '') {}
    public function get_json_params() { return json_decode($this->body, true); }
    public function get_header($key) { return $this->headers[strtolower($key)] ?? ''; }
    public function get_body() { return $this->body; }
    public function get_url_params() { return $this->url_params ?? $this->params; }
    public function get_param($key) { return $this->params[$key] ?? null; }
    public function offsetExists(mixed $offset): bool { return isset($this->params[$offset]); }
    public function offsetGet(mixed $offset): mixed { return $this->params[$offset] ?? null; }
    public function offsetSet(mixed $offset, mixed $value): void { $this->params[$offset] = $value; }
    public function offsetUnset(mixed $offset): void { unset($this->params[$offset]); }
}
function __($text, $domain = '') { return $text; }
function is_wp_error($value) { return $value instanceof WP_Error; }
function wp_parse_args($args, $defaults) { return array_merge($defaults, $args); }
function get_option($name, $default = false) { return $GLOBALS['options'][$name] ?? $default; }
function wp_unslash($value) { return is_array($value) ? array_map('wp_unslash', $value) : (is_string($value) ? stripslashes($value) : $value); }
function sanitize_text_field($value) { return is_scalar($value) ? trim(strip_tags((string) $value)) : ''; }
function sanitize_email($value) { return filter_var($value, FILTER_SANITIZE_EMAIL); }
function is_email($value) { return filter_var($value, FILTER_VALIDATE_EMAIL); }
function sanitize_hex_color($value) { return preg_match('/^#(?:[a-fA-F0-9]{3}){1,2}$/D', $value) ? $value : null; }
function add_settings_error(...$args) { $GLOBALS['settings_errors'][] = $args; }
function wp_remote_request($url, $args) {
    $GLOBALS['http_calls'][] = [$url, $args];
    $path = substr($url, strlen('https://api.stripe.com/v1/'));
    if (!isset($GLOBALS['stripe'][$path])) { return new WP_Error('http', 'No fixture for ' . $path); }
    return ['code' => 200, 'body' => json_encode($GLOBALS['stripe'][$path])];
}
function wp_remote_retrieve_body($response) { return $response['body']; }
function wp_remote_retrieve_response_code($response) { return $response['code']; }
function wp_parse_url($url, $component = -1) { return parse_url($url, $component); }
function home_url($path = '/') { return 'https://example.test' . $path; }
function remove_query_arg($keys, $url) {
    $p = parse_url($url); parse_str($p['query'] ?? '', $q);
    foreach ((array) $keys as $key) { unset($q[$key]); }
    return $p['scheme'] . '://' . $p['host'] . (isset($p['port']) ? ':' . $p['port'] : '') . ($p['path'] ?? '/') . ($q ? '?' . http_build_query($q) : '');
}
function add_query_arg($key, $value = '', $url = '') { if (is_array($key)) { return $value . '?' . http_build_query($key); } if ($url === '') { $url = 'https://example.test/donations'; } return $url . (str_contains($url, '?') ? '&' : '?') . urlencode($key) . '=' . urlencode($value); }
function current_time($type, $gmt = false) { return gmdate('Y-m-d H:i:s'); }
function wp_generate_uuid4() { return sprintf('%08x-1234-4321-8123-%012x', ++$GLOBALS['uuid'], $GLOBALS['uuid']); }
function wp_generate_password(...$args) { return 'random-password-not-exposed'; }
function get_user_by($field, $value) { foreach ($GLOBALS['users'] as $user) { if (($field === 'email' ? $user->user_email : $user->ID) === $value) { return $user; } } return false; }
function wp_insert_user($data) {
    $id = count($GLOBALS['users']) + 1;
    $GLOBALS['users'][$id] = (object) ['ID' => $id, 'user_email' => $data['user_email'], 'role' => $data['role']];
    return $id;
}
function wp_new_user_notification(...$args) { $GLOBALS['notifications'][] = $args; }
function get_current_user_id() { return $GLOBALS['current_user'] ?? 0; }
function wp_verify_nonce($nonce, $action) { return $nonce === 'valid'; }
function wp_create_nonce($action) { $GLOBALS['created_nonces'][] = $action; return $GLOBALS['nonce_value'] ?? 'valid'; }
function wp_salt($scheme) { return 'test-only-salt'; }
function get_transient($key) { return $GLOBALS['transients'][$key] ?? false; }
function set_transient($key, $value, $ttl) { $GLOBALS['transients'][$key] = $value; }
function is_ssl() { return true; }
function sanitize_key($value) { return preg_replace('/[^a-z0-9_\-]/', '', strtolower($value)); }
class TestDB {
    public string $prefix = 'wp_'; public int $insert_id = 0; public PDO $pdo;
    public function __construct() {
        $this->pdo = new PDO('sqlite::memory:'); $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $this->pdo->exec("CREATE TABLE wp_donation_box_payments (
            id INTEGER PRIMARY KEY AUTOINCREMENT, reference TEXT UNIQUE, mode TEXT, user_id INTEGER DEFAULT 0,
            email TEXT, first_name TEXT DEFAULT '', last_name TEXT DEFAULT '', phone TEXT DEFAULT '',
            amount INTEGER, refunded_amount INTEGER DEFAULT 0, currency TEXT, frequency TEXT, status TEXT DEFAULT 'pending',
            customer_id TEXT DEFAULT '', session_id TEXT DEFAULT '', subscription_id TEXT DEFAULT '', stripe_id TEXT DEFAULT '', created_at TEXT, paid_at TEXT
        ); CREATE TABLE wp_donation_box_subscriptions (
            id INTEGER PRIMARY KEY AUTOINCREMENT, stripe_id TEXT UNIQUE, mode TEXT, reference TEXT, customer_id TEXT, email TEXT, amount INTEGER, currency TEXT, status TEXT,
            cancel_at_period_end INTEGER, period_end INTEGER, updated_at TEXT
        ); CREATE TABLE wp_donation_box_events (event_id TEXT PRIMARY KEY, processed_at TEXT)");
    }
    public function prepare($sql, ...$values) {
        $i = 0; return preg_replace_callback('/%[sdi]/', function ($m) use (&$i, $values) { $v = $values[$i++]; if ($m[0] === '%i') { return '`' . str_replace('`', '``', (string) $v) . '`'; } return $m[0] === '%d' ? (string) (int) $v : $this->pdo->quote((string) $v); }, $sql);
    }
    public function esc_like($value) { return addcslashes($value, '_%\\'); }
    public function get_results($sql, $output = null) { return $this->pdo->query($sql)->fetchAll($output === ARRAY_A ? PDO::FETCH_ASSOC : PDO::FETCH_OBJ); }
    public function get_col($sql) { return $this->pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN); }
    public function get_row($sql) { return $this->pdo->query($sql)->fetchObject() ?: null; }
    public function get_var($sql) { if (str_contains($sql, 'GET_LOCK') || str_contains($sql, 'RELEASE_LOCK')) { return 1; } return $this->pdo->query($sql)->fetchColumn(); }
    public function query($sql) { return $this->pdo->exec($sql); }
    public function insert($table, $data) {
        $sql = 'INSERT INTO ' . $table . ' (' . implode(',', array_keys($data)) . ') VALUES (' . implode(',', array_fill(0, count($data), '?')) . ')';
        $result = $this->pdo->prepare($sql)->execute(array_values($data)); $this->insert_id = (int) $this->pdo->lastInsertId(); return $result ? 1 : false;
    }
    public function update($table, $data, $where) {
        $sql = 'UPDATE ' . $table . ' SET ' . implode(',', array_map(static fn ($k) => $k . '=?', array_keys($data))) . ' WHERE ' . implode(' AND ', array_map(static fn ($k) => $k . '=?', array_keys($where)));
        $s = $this->pdo->prepare($sql); $s->execute([...array_values($data), ...array_values($where)]); return $s->rowCount();
    }
}
require __DIR__ . '/../includes/class-settings.php';
require __DIR__ . '/../includes/class-store.php';
require __DIR__ . '/../includes/class-stripe.php';
require __DIR__ . '/../includes/class-api.php';
require __DIR__ . '/../includes/class-admin.php';
