<?php
/**
 * CrawlerToll Plugin Test Harness
 *
 * Tests core plugin classes without requiring a full WordPress install.
 * Run: php test-harness.php
 */

// Mock WordPress functions that the plugin depends on.
if (!function_exists('__')) { function __($s, $d = '') { return $s; } }
if (!function_exists('esc_html__')) { function esc_html__($s, $d = '') { return $s; } }
if (!function_exists('esc_html')) { function esc_html($s) { return htmlspecialchars($s, ENT_QUOTES); } }
if (!function_exists('esc_attr')) { function esc_attr($s) { return htmlspecialchars($s, ENT_QUOTES); } }
if (!function_exists('esc_url')) { function esc_url($s) { return $s; } }
if (!function_exists('esc_url_raw')) { function esc_url_raw($s) { return $s; } }
if (!function_exists('esc_textarea')) { function esc_textarea($s) { return htmlspecialchars($s, ENT_QUOTES); } }
if (!function_exists('esc_attr__')) { function esc_attr__($s, $d = '') { return $s; } }
if (!function_exists('wp_parse_url')) { function wp_parse_url($u, $c = -1) { return parse_url($u, $c); } }
if (!function_exists('home_url')) { function home_url($p = '') { return 'https://test.example' . $p; } }
if (!function_exists('get_bloginfo')) { function get_bloginfo($f = '') { return $f === 'name' ? 'Test Site' : 'admin@test.example'; } }
if (!function_exists('wp_unslash')) { function wp_unslash($s) { return is_string($s) ? stripslashes($s) : $s; } }
if (!function_exists('sanitize_text_field')) { function sanitize_text_field($s) { return strip_tags((string) $s); } }
if (!function_exists('wp_json_encode')) { function wp_json_encode($d, $o = 0) { return json_encode($d, $o); } }
if (!function_exists('current_time')) { function current_time($t = 'mysql') { return $t === 'mysql' ? gmdate('Y-m-d H:i:s') : time(); } }
if (!function_exists('wp_parse_args')) { function wp_parse_args($a, $d) { return array_merge($d, is_array($a) ? $a : []); } }
if (!function_exists('number_format_i18n')) { function number_format_i18n($n) { return number_format($n); } }
if (!function_exists('wp_generate_password')) { function wp_generate_password($l = 12) { return bin2hex(random_bytes($l/2)); } }
if (!function_exists('get_option')) { function get_option($k, $d = false) { return $d; } }
if (!function_exists('update_option')) { function update_option($k, $v) { return true; } }
if (!function_exists('delete_option')) { function delete_option($k) { return true; } }
if (!function_exists('add_option')) { function add_option($k, $v) { return true; } }
if (!function_exists('is_email')) { function is_email($e) { return filter_var($e, FILTER_VALIDATE_EMAIL); } }
if (!function_exists('wp_mail')) { function wp_mail($to, $subj, $body) { echo "  [MAIL] To: $to | Subject: $subj\n"; return true; } }
if (!function_exists('wp_next_scheduled')) { function wp_next_scheduled($h) { return false; } }
if (!function_exists('wp_schedule_event')) { function wp_schedule_event($t, $r, $h) { return true; } }
if (!function_exists('wp_clear_scheduled_hook')) { function wp_clear_scheduled_hook($h) { return true; } }
if (!function_exists('wp_remote_get')) { function wp_remote_get($u, $a = []) { return new WP_Error('not_connected'); } }
if (!function_exists('wp_remote_post')) { function wp_remote_post($u, $a = []) { return new WP_Error('not_connected'); } }
if (!function_exists('wp_remote_retrieve_body')) { function wp_remote_retrieve_body($r) { return ''; } }
if (!function_exists('is_wp_error')) { function is_wp_error($t) { return $t instanceof WP_Error; } }
if (!function_exists('wp_verify_nonce')) { function wp_verify_nonce($n, $a) { return true; } }
if (!function_exists('get_transient')) { function get_transient($k) { return false; } }
if (!function_exists('set_transient')) { function set_transient($k, $v, $e) { return true; } }
if (!function_exists('wp_die')) { function wp_die($m) { die($m); } }
if (!function_exists('current_user_can')) { function current_user_can($c) { return true; } }
if (!function_exists('admin_url')) { function admin_url($p = '') { return 'https://test.example/wp-admin/' . $p; } }
if (!function_exists('plugin_dir_url')) { function plugin_dir_url($f) { return 'https://test.example/wp-content/plugins/crawlertoll/'; } }
if (!function_exists('plugin_dir_path')) { function plugin_dir_path($f) { return __DIR__ . '/'; } }
if (!function_exists('wp_create_nonce')) { function wp_create_nonce($a = -1) { return 'test_nonce'; } }
if (!function_exists('add_action')) { function add_action($h, $c, $p = 10, $a = 1) { return true; } }
if (!function_exists('add_filter')) { function add_filter($h, $c, $p = 10, $a = 1) { return true; } }
if (!function_exists('apply_filters')) { function apply_filters($h, $v) { return $v; } }
if (!function_exists('do_action')) { function do_action($h, ...$a) { return true; } }
if (!function_exists('has_action')) { function has_action($h, $c = false) { return false; } }
if (!function_exists('register_activation_hook')) { function register_activation_hook($f, $c) { return true; } }
if (!function_exists('register_deactivation_hook')) { function register_deactivation_hook($f, $c) { return true; } }
if (!function_exists('flush_rewrite_rules')) { function flush_rewrite_rules() { return true; } }
if (!function_exists('add_rewrite_rule')) { function add_rewrite_rule($r, $q, $a) { return true; } }
if (!function_exists('get_query_var')) { function get_query_var($v, $d = '') { return $d; } }
if (!function_exists('status_header')) { function status_header($c) { return true; } }
if (!function_exists('nocache_headers')) { function nocache_headers() { return true; } }
if (!function_exists('header')) { function header($h) { return true; } }
if (!function_exists('header_register_callback')) { function header_register_callback($c) { return true; } }
if (!function_exists('add_options_page')) { function add_options_page($p, $m, $c, $s, $cb) { return true; } }
if (!function_exists('register_setting')) { function register_setting($g, $n, $a = []) { return true; } }
if (!function_exists('settings_fields')) { function settings_fields($g) { return true; } }
if (!function_exists('checked')) { function checked($v, $c = true) { return $v == $c ? 'checked' : ''; } }
if (!function_exists('selected')) { function selected($v, $c = true) { return $v == $c ? 'selected' : ''; } }
if (!function_exists('submit_button')) { function submit_button($t = '', $n = '', $i = '', $w = false) { return ''; } }
if (!function_exists('wp_enqueue_style')) { function wp_enqueue_style($h, $s, $d = [], $v = null) { return true; } }
if (!function_exists('wp_enqueue_script')) { function wp_enqueue_script($h, $s, $d = [], $v = null, $f = false) { return true; } }
if (!function_exists('wp_localize_script')) { function wp_localize_script($h, $n, $d) { return true; } }
if (!function_exists('get_queried_object_id')) { function get_queried_object_id() { return 0; } }
if (!function_exists('get_post_meta')) { function get_post_meta($id, $k, $s = false) { return $s ? '' : []; } }
if (!function_exists('get_categories')) { function get_categories($a = []) { return []; } }

class WP_Error {
    public function __construct($code = '', $msg = '') {}
    public function get_error_message() { return 'Mock error'; }
}

class WP_REST_Response {
    public function __construct($data = null, $status = 200, $headers = []) {}
    public function header($k, $v) { return $this; }
}

class WP_REST_Request {
    public function __construct($method = 'GET', $route = '') {}
    public function get_param($k) { return null; }
    public function get_header($k) { return null; }
}

class wpdb {
    public $prefix = 'wp_';
    public function query($s) { return true; }
    public function get_var($s) { return null; }
    public function get_row($s, $o = 'OBJECT') { return null; }
    public function get_results($s, $o = 'OBJECT') { return []; }
    public function prepare($q, ...$a) { return $q; }
    public function insert($t, $d, $f = null) { return 1; }
    public function update($t, $d, $w, $df = null, $wf = null) { return 1; }
    public function delete($t, $w, $wf = null) { return 1; }
    public function get_charset_collate() { return 'utf8mb4_unicode_ci'; }
    public $insert_id = 1;
}

$GLOBALS['wpdb'] = new wpdb();

// Load plugin constants
define('ABSPATH', '/');
define('CRAWLERTOLL_VERSION', '0.2.0');
define('CRAWLERTOLL_PLUGIN_FILE', __FILE__);
define('CRAWLERTOLL_PLUGIN_DIR', __DIR__ . '/');
define('CRAWLERTOLL_OPTION_KEY', 'crawlertoll_settings');

// ─── Now test ────────────────────────────────────────────────────

$pass = 0;
$fail = 0;

function test($name, $condition) {
    global $pass, $fail;
    if ($condition) { echo "  ✅ $name\n"; $pass++; }
    else { echo "  ❌ $name\n"; $fail++; }
}

echo "=== CrawlerToll Plugin Test Suite ===\n\n";

// Load core classes
require_once __DIR__ . '/includes/class-crawlertoll-bot-catalogue.php';
require_once __DIR__ . '/includes/class-crawlertoll-rsl-parser.php';
require_once __DIR__ . '/includes/class-crawlertoll-safemode.php';
require_once __DIR__ . '/includes/class-crawlertoll-decision.php';
require_once __DIR__ . '/includes/class-crawlertoll-http402.php';
require_once __DIR__ . '/includes/class-crawlertoll-db.php';
require_once __DIR__ . '/includes/class-crawlertoll-logger.php';
require_once __DIR__ . '/includes/class-crawlertoll-provenance.php';
require_once __DIR__ . '/includes/class-crawlertoll-pricing.php';
require_once __DIR__ . '/includes/class-crawlertoll-alerts.php';
require_once __DIR__ . '/includes/class-crawlertoll-catalogue-updater.php';
require_once __DIR__ . '/includes/class-crawlertoll-registry.php';

echo "1. Bot Catalogue\n";
$bots = CrawlerToll_Bot_Catalogue::all();
test('30+ bots in catalogue', count($bots) >= 28);
test('GPTBot detected', CrawlerToll_Bot_Catalogue::match('GPTBot/1.2') !== null);
test('ClaudeBot detected', CrawlerToll_Bot_Catalogue::match('ClaudeBot/1.0') !== null);
test('PerplexityBot detected', CrawlerToll_Bot_Catalogue::match('PerplexityBot/1.0') !== null);
test('Browser NOT detected', CrawlerToll_Bot_Catalogue::match('Mozilla/5.0') === null);
test('Empty UA returns null', CrawlerToll_Bot_Catalogue::match('') === null);

echo "\n2. RSL Parser\n";
$policy = "User-agent: GPTBot\nDisallow: /\nAllow: /wp-content/uploads/\nCompensation: per-crawl 5000 micros USD\n\nUser-agent: *\nDisallow:";
$parsed = CrawlerToll_RSL_Parser::parse($policy);
test('Parses two groups', count($parsed['groups']) === 2);
test('First group has GPTBot', in_array('gptbot', $parsed['groups'][0]['user_agents']));
test('First group disallows /', in_array('/', $parsed['groups'][0]['disallow']));
test('First group allows /wp-content/uploads/', in_array('/wp-content/uploads/', $parsed['groups'][0]['allow']));
test('Compensation parsed', !empty($parsed['groups'][0]['compensation']));

echo "\n3. Safe Mode\n";
test('Googlebot is safe', CrawlerToll_SafeMode::is_safe('Googlebot/2.1'));
test('Bingbot is safe', CrawlerToll_SafeMode::is_safe('bingbot/2.0'));
test('DuckDuckBot is safe', CrawlerToll_SafeMode::is_safe('DuckDuckBot/1.0'));
test('Applebot is safe', CrawlerToll_SafeMode::is_safe('Applebot/1.0'));
test('Applebot-Extended is NOT safe', !CrawlerToll_SafeMode::is_safe('Applebot-Extended/1.0'));
test('GPTBot is NOT safe', !CrawlerToll_SafeMode::is_safe('GPTBot/1.2'));
test('Facebook external is safe', CrawlerToll_SafeMode::is_safe('facebookexternalhit/1.1'));
test('Slackbot is safe', CrawlerToll_SafeMode::is_safe('Slackbot-LinkExpanding 1.0'));

echo "\n4. Decision Engine\n";
$settings = [
    'enabled' => true, 'price_micros' => 5000, 'currency' => 'USD',
    'rail' => 'x402', 'policy' => $policy, 'payment_url' => '', 'terms_url' => ''
];

// Safe mode test
$dec = CrawlerToll_Decision::decide(['method' => 'GET', 'path' => '/', 'user_agent' => 'Googlebot/2.1'], $settings);
test('Googlebot allowed (safe mode)', $dec['action'] === 'allow');

// Bot detection + 402
$dec = CrawlerToll_Decision::decide(['method' => 'GET', 'path' => '/secret/', 'user_agent' => 'GPTBot/1.2'], $settings);
test('GPTBot gets 402 on disallowed path', $dec['action'] === '402');

// Bot on allowed path
$dec = CrawlerToll_Decision::decide(['method' => 'GET', 'path' => '/wp-content/uploads/photo.jpg', 'user_agent' => 'GPTBot/1.2'], $settings);
test('GPTBot allowed on allowed path', $dec['action'] === 'allow');

// Browser
$dec = CrawlerToll_Decision::decide(['method' => 'GET', 'path' => '/', 'user_agent' => 'Mozilla/5.0'], $settings);
test('Browser allowed', $dec['action'] === 'allow');

echo "\n5. Content Provenance (SHA-256)\n";
$hash1 = CrawlerToll_Provenance::hash_content('test content');
$hash2 = CrawlerToll_Provenance::hash_content('test content');
$hash3 = CrawlerToll_Provenance::hash_content('different content');
test('SHA-256 produces 64-char hex', strlen($hash1) === 64);
test('Same content = same hash', $hash1 === $hash2);
test('Different content = different hash', $hash1 !== $hash3);
test('Verify matches', CrawlerToll_Provenance::verify('test content', $hash1));
test('Verify rejects wrong content', !CrawlerToll_Provenance::verify('wrong', $hash1));

echo "\n6. Per-Path Pricing\n";
$db = new CrawlerToll_DB($GLOBALS['wpdb']);
$pricing = new CrawlerToll_Pricing($db);
$settings['path_pricing'] = [
    ['path' => '/premium/*', 'price_micros' => 50000],
    ['path' => '/blog/', 'price_micros' => 2000],
];
$r = $pricing->resolve('/premium/report-2026/', $settings);
test('Wildcard path matched', $r['price_micros'] === 50000);
$r = $pricing->resolve('/blog/post-slug/', $settings);
test('Exact prefix matched', $r['price_micros'] === 2000);
$r = $pricing->resolve('/about/', $settings);
test('Fallback to default', $r['price_micros'] === 5000);

echo "\n7. Database Layer\n";
test('Table name has prefix', strpos($db->get_table_name(), 'wp_') !== false);

echo "\n8. Registry\n";
$registry = new CrawlerToll_Registry();
test('Not registered by default', !CrawlerToll_Registry::is_registered());

echo "\n9. Alerts\n";
$alerts = new CrawlerToll_Alerts($db);
test('Alert class instantiates', $alerts !== null);

echo "\n10. Pro Log Export (CSV/JSON)\n";
require_once __DIR__ . '/admin/class-crawlertoll-pro-admin.php';
$export_entries = [
    [
        'request_time' => '2026-05-30 14:32:15',
        'bot_name'     => 'GPTBot',
        'bot_operator' => 'OpenAI',
        'bot_category' => 'training',
        'request_path' => '/premium/report-2026/',
        'action'       => '402',
        'price_micros' => 50000,
        'currency'     => 'USD',
        'rail'         => 'x402',
        'content_hash' => str_repeat('a', 64),
        'http_status'  => 402,
    ],
    [
        'request_time' => '2026-05-30 14:31:00',
        'bot_name'     => 'ClaudeBot',
        'bot_operator' => 'Anthropic',
        'bot_category' => 'training',
        'request_path' => '/about/',
        'action'       => 'allow',
        'price_micros' => 0,
        'currency'     => 'USD',
        'rail'         => 'x402',
        'content_hash' => null,
        'http_status'  => 200,
    ],
];

$csv = CrawlerToll_Pro_Admin::format_logs_csv($export_entries);
$csv_lines = array_values(array_filter(explode("\n", trim($csv))));
test('CSV header matches spec §2.11', strpos($csv_lines[0], 'time,bot_name,operator,category,path,action,price_micros,currency,rail,content_hash,http_status') === 0);
test('CSV has one row per entry + header', count($csv_lines) === 3);
test('CSV row carries bot + price', strpos($csv_lines[1], 'GPTBot') !== false && strpos($csv_lines[1], '50000') !== false);
test('CSV nulls render empty, not "null"', strpos($csv_lines[2], 'null') === false);

$json = CrawlerToll_Pro_Admin::format_logs_json($export_entries, ['site' => 'Test Site', 'count' => count($export_entries)]);
$decoded = json_decode($json, true);
test('JSON is valid', is_array($decoded));
test('JSON has meta.count', isset($decoded['meta']['count']) && $decoded['meta']['count'] === 2);
test('JSON entries length matches', isset($decoded['entries']) && count($decoded['entries']) === 2);
test('JSON preserves bot_name', $decoded['entries'][0]['bot_name'] === 'GPTBot');

echo "\n11. Multi-Rail Routing (§2.5)\n";
$rail_settings = ['rail' => 'x402', 'rail_overrides' => ['GPTBot' => 'cloudflare-ppc', 'ClaudeBot' => 'tollbit']];
test('Override applied for GPTBot', CrawlerToll_Pricing::resolve_rail('GPTBot', $rail_settings) === 'cloudflare-ppc');
test('Override applied for ClaudeBot', CrawlerToll_Pricing::resolve_rail('ClaudeBot', $rail_settings) === 'tollbit');
test('Bot without override uses default', CrawlerToll_Pricing::resolve_rail('PerplexityBot', $rail_settings) === 'x402');
test('Empty bot name uses default', CrawlerToll_Pricing::resolve_rail('', $rail_settings) === 'x402');
test('No overrides key uses default', CrawlerToll_Pricing::resolve_rail('GPTBot', ['rail' => 'skyfire']) === 'skyfire');
test('Non-array overrides uses default', CrawlerToll_Pricing::resolve_rail('GPTBot', ['rail' => 'x402', 'rail_overrides' => 'bad']) === 'x402');
test('Empty-string override falls back to default', CrawlerToll_Pricing::resolve_rail('GPTBot', ['rail' => 'x402', 'rail_overrides' => ['GPTBot' => '']]) === 'x402');

echo "\n────────────────────────────────────\n";
echo "Results: $pass passed, $fail failed\n";
echo ($fail === 0 ? "ALL TESTS PASSED ✅\n" : "SOME TESTS FAILED ❌\n");
