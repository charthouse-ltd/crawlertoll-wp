<?php
// Local CLI harness: render admin/views/settings.php with the WP template tags
// stubbed, and assert the free settings view renders cleanly and no longer
// carries the cut TOTAL-gate / sealed-content toggle (2026-06-20 cut).
// Run: php tests/ui-harness.php
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
function settings_fields( $g ) { echo "<!-- settings_fields:$g -->"; }
function submit_button( $t = '', $ty = '', $n = '', $wrap = true ) { echo '<button>' . $t . '</button>'; }
function home_url( $p = '' ) { return 'http://example.test' . $p; }
function wp_json_encode( $d, $o = 0 ) { return json_encode( $d, $o ); }
function checked( $a, $b = true, $e = true ) { $r = $a ? " checked='checked'" : ''; if ( $e ) { echo $r; } return $r; }
function selected( $a, $b = true, $e = true ) { $r = ( (string) $a === (string) $b ) ? " selected='selected'" : ''; if ( $e ) { echo $r; } return $r; }
function crawlertoll_rail_options() { return array( 'x402' => 'x402 — stablecoin', 'custom' => 'Custom' ); }

function render_settings() {
	$settings = array(
		'enabled'      => false,
		'price_micros' => 5000,
		'currency'     => 'USD',
		'rail'         => 'x402',
		'payment_url'  => '',
		'terms_url'    => '',
		'policy'       => "User-agent: GPTBot\nDisallow: /\n",
		'remove_data_on_uninstall' => false,
	);
	$rails           = crawlertoll_rail_options();
	$bots            = array( array( 'name' => 'GPTBot', 'operator' => 'OpenAI', 'category' => 'training' ) );
	$category_counts = array( 'training' => 1 );
	$policy_data     = array( 'groups' => array() );
	$active_bots     = 1;
	$active_groups   = 1;
	ob_start();
	include __DIR__ . '/../admin/views/settings.php';
	return ob_get_clean();
}

$html = render_settings();

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

ck( strlen( $html ) > 500, 'settings view renders (' . strlen( $html ) . ' bytes)' );
ck( strpos( $html, 'Payment offer' ) !== false, 'Payment offer card still present' );
ck( strpos( $html, 'TOTAL gate' ) === false, 'TOTAL gate card removed (cut 2026-06-20)' );
ck( strpos( $html, 'crawlertoll_settings[total_gate]' ) === false, 'total_gate checkbox removed' );

exit( 0 === $fail ? 0 : 1 );
