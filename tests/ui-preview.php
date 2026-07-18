<?php
// Render admin/views/settings.php into a standalone HTML page (real settings.php
// + real assets/admin.css) for a visual check. Run: php tests/ui-preview.php > out.html
define( 'ABSPATH', __DIR__ . '/' );
define( 'CRAWLERTOLL_OPTION_KEY', 'crawlertoll_settings' );

function esc_html_e( $s, $d = null ) { echo $s; }
function esc_html__( $s, $d = null ) { return $s; }
function esc_html( $s ) { return $s; }
function esc_attr( $s ) { return $s; }
function esc_attr_e( $s, $d = null ) { echo $s; }
function esc_url( $s ) { return $s; }
function esc_url_raw( $s ) { return $s; }
function esc_textarea( $s ) { return $s; }
function __( $s, $d = null ) { return $s; }
function settings_fields( $g ) {}
function submit_button( $t = '', $ty = '', $n = '', $wrap = true ) { echo '<button type="submit" class="button button-primary">' . $t . '</button>'; }
function home_url( $p = '' ) { return 'http://example.test' . $p; }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function checked( $a, $b = true, $e = true ) { $r = $a ? " checked='checked'" : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " selected='selected'" : ''; if ( $e ) { echo $r; } return $r; }
function crawlertoll_rail_options() { return array( 'x402' => 'x402 — Coinbase + LF stablecoin rail', 'cloudflare-ppc' => 'Cloudflare Pay Per Crawl', 'custom' => 'Custom' ); }

$settings = array(
	'enabled'      => true,
	'price_micros' => 5000,
	'currency'     => 'USD',
	'rail'         => 'x402',
	'payment_url'  => '',
	'terms_url'    => '',
	'policy'       => "User-agent: GPTBot\nUser-agent: ClaudeBot\nUser-agent: PerplexityBot\nDisallow: /\nAllow: /wp-content/uploads/\nLicense: http://example.test/ai-license\nCompensation: per-crawl 5000 micros USD\nStandard: RSL/1.0\n\nUser-agent: *\nDisallow:\n",
	'total_gate'   => true,
	'remove_data_on_uninstall' => false,
);
$rails           = crawlertoll_rail_options();
$bots            = array(
	array( 'name' => 'GPTBot', 'operator' => 'OpenAI', 'category' => 'training' ),
	array( 'name' => 'ClaudeBot', 'operator' => 'Anthropic', 'category' => 'training' ),
	array( 'name' => 'PerplexityBot', 'operator' => 'Perplexity', 'category' => 'search' ),
);
$category_counts = array( 'training' => 2, 'search' => 1 );
$policy_data     = array( 'groups' => array( array( 'user_agents' => array( 'GPTBot' ) ) ) );
$active_bots     = 3;
$active_groups   = 2;

ob_start();
include __DIR__ . '/../admin/views/settings.php';
$body = ob_get_clean();

$css = @file_get_contents( __DIR__ . '/../assets/admin.css' );

echo "<!doctype html>\n<html><head><meta charset='utf-8'><title>CrawlerToll Settings — preview</title>\n";
echo "<link rel='stylesheet' href='https://cdn.jsdelivr.net/gh/WordPress/dashicons@master/css/dashicons.css'>\n";
echo "<style>\nbody{background:#f0f0f1;margin:0;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#1e1e1e;}\n.wrap{max-width:900px;margin:0 auto;}\n.ct-header h1{font-size:23px;font-weight:600;}\n.ct-badge{font-size:11px;background:#6366f1;color:#fff;padding:2px 8px;border-radius:99px;vertical-align:middle;}\n.button{padding:8px 16px;border-radius:6px;border:1px solid #c3c4c7;background:#f6f7f7;cursor:pointer;}\n.button-primary{background:#6366f1;color:#fff;border:none;}\n" . $css . "\n</style>\n";
echo "</head><body><div class='wrap'>\n";
echo "<div class='ct-header'><h1>CrawlerToll <span class='ct-badge'>v0.2.0</span></h1></div>\n";
echo $body;
echo "\n</div></body></html>\n";
