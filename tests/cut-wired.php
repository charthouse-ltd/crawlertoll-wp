<?php
/**
 * Visual cut-bar wiring guard (spec: docs/specs/access-tiers-v1.md §5.2, 2026-08-28).
 *
 * The cut bar lets a publisher drag a divider over the article outline to set
 * the free-preview/sealed-body boundary. This guards: the cut meta + split
 * priority (free-safe), the gate callers, the Gutenberg panel asset + enqueue,
 * the classic metabox, and the Wall preview admin tab.
 *
 * Run: php tests/cut-wired.php   (exit 0 = pass, 1 = fail)
 */

$dir    = dirname( __DIR__ );
$cut    = (string) file_get_contents( $dir . '/includes/class-crawlertoll-cut.php' );
$gate   = (string) file_get_contents( $dir . '/includes/class-crawlertoll-premium-gate.php' );
$main   = (string) file_get_contents( $dir . '/crawlertoll.php' );
$admin  = (string) file_get_contents( $dir . '/admin/class-crawlertoll-admin.php' );
$panel  = $dir . '/assets/js/editor-panel.js';
$panels = file_exists( $panel ) ? (string) file_get_contents( $panel ) : '';

$fail = 0;
function ck( $c, $m ) { global $fail; echo ( $c ? 'PASS' : 'FAIL' ) . ": $m\n"; if ( ! $c ) { $fail++; } }

// A — cut meta + priority in the splitter (free-safe class).
ck( strpos( $cut, "const CUT_META = '_crawlertoll_cut'" ) !== false, 'CUT_META constant defined' );
ck( strpos( $cut, 'function split_for_post' ) !== false, 'split_for_post() exists (meta-aware entry point)' );
ck( strpos( $cut, 'function split_at_index' ) !== false, 'split_at_index() (pure unit cut) exists' );
ck( strpos( $cut, 'function block_boundaries' ) !== false, 'block_boundaries() (top-level scan) exists' );
ck( strpos( $cut, 'function paragraph_boundaries' ) !== false, 'paragraph_boundaries() (classic scan) exists' );
ck( strpos( $cut, 'retreat_to_clean( $raw, $off )' ) !== false, 'paragraph meta-cut retreats to a clean boundary (leak-safe)' );

// B — the gate resolves splits through the meta-aware entry point everywhere.
ck( strpos( $gate, 'CrawlerToll_Cut::split_for_post' ) !== false, 'premium gate uses split_for_post()' );
ck( substr_count( $gate, 'CrawlerToll_Cut::split_for_post' ) >= 2, 'both split sites (neutralize + parts) use split_for_post()' );
ck( strpos( $gate, 'CrawlerToll_Cut::split(' ) === false, 'no raw split() callers remain in the gate' );

// C — WP wiring: meta registration + editor surfaces, hooked from the bootstrap.
ck( strpos( $cut, 'function register_hooks' ) !== false, 'register_hooks() exists on CrawlerToll_Cut' );
ck( strpos( $main, 'CrawlerToll_Cut::register_hooks()' ) !== false, 'bootstrap calls CrawlerToll_Cut::register_hooks()' );
ck( strpos( $cut, 'register_post_meta' ) !== false && strpos( $cut, "'show_in_rest'      => true" ) !== false, 'cut meta registered with REST exposure (Gutenberg needs it)' );
ck( strpos( $cut, 'edit_post' ) !== false, 'cut meta write auth requires edit_post' );
ck( strpos( $cut, 'enqueue_block_editor_assets' ) !== false, 'Gutenberg panel enqueued on the block editor' );
ck( strpos( $cut, 'add_meta_boxes' ) !== false && strpos( $cut, 'save_classic_metabox' ) !== false, 'classic metabox registered + saved' );
ck( strpos( $cut, 'crawlertoll_cut_nonce' ) !== false, 'classic metabox save is nonced' );

// D — the Gutenberg panel asset exists and is build-step-free on core externals.
ck( file_exists( $panel ), 'assets/js/editor-panel.js present' );
ck( strpos( $panels, 'registerPlugin' ) !== false && strpos( $panels, 'PluginDocumentSettingPanel' ) !== false, 'panel registers a Gutenberg document sidebar panel' );
ck( strpos( $panels, '_crawlertoll_cut' ) !== false, 'panel persists _crawlertoll_cut meta' );
ck( strpos( $panels, '_crawlertoll_premium' ) !== false, 'panel only activates for premium posts' );
ck( strpos( $panels, 'onPointerDown' ) !== false && strpos( $panels, 'onPointerMove' ) !== false, 'drag interaction (pointer events) implemented' );
ck( strpos( $panels, 'onKeyDown' ) !== false, 'keyboard adjustment (accessibility) implemented' );

// E — Wall preview admin tab.
ck( strpos( $admin, "'preview'" ) !== false, 'Wall preview tab registered' );
ck( strpos( $admin, 'render_preview_tab' ) !== false, 'render_preview_tab() routed' );
ck( strpos( $gate, 'function wall_preview_html' ) !== false, 'gate exposes wall_preview_html()' );
ck( strpos( $gate, 'WITHOUT sealing' ) !== false || strpos( $gate, 'side effect' ) !== false, 'admin preview is documented side-effect-free (no seal/register)' );

// F — the preview tab must NOT be Pro-gated (the cut is a free feature).
ck( ! preg_match( "/array\(\s*'pricing'[^)]*'preview'/", $admin ), 'preview tab is not in the Pro-redirect list' );

exit( $fail === 0 ? 0 : 1 );
