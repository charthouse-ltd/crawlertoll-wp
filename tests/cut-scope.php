<?php
/**
 * Sealing-engine free-safety guard (WS3, 2026-06-29).
 *
 * History: this file used to assert the sealed/registry engine was NOT shipped
 * (the 2026-06-20 strategy-lock cut). That lock is reversed — "free = real
 * sealing" — so the engine now ships in BOTH artifacts. The invariant that still
 * matters: because the engine ships in the FREE build (where the Pro classes
 * CrawlerToll_DB/Pricing/Alerts/Logger/Provenance/CatalogueUpdater are stripped),
 * the sealing files must NOT depend on any Pro-only class, or the free build
 * fatals. This guards that.
 *
 * Run: php tests/cut-scope.php   (exit 0 = pass, 1 = fail)
 */

$plugin_dir = dirname( __DIR__ );

$sealing_files = array(
	'includes/class-crawlertoll-sealed.php',
	'includes/class-crawlertoll-sealed-gate.php',
	'includes/class-crawlertoll-registry.php',
	'includes/class-crawlertoll-cut.php',
	'includes/class-crawlertoll-premium-gate.php',
);

// Pro-only classes stripped from the free wp.org build (see build.sh PREMIUM_ONLY).
$pro_only = array(
	'CrawlerToll_DB',
	'CrawlerToll_Pricing',
	'CrawlerToll_Alerts',
	'CrawlerToll_Logger',
	'CrawlerToll_Provenance',
	'CrawlerToll_CatalogueUpdater',
);

$violations = array();
foreach ( $sealing_files as $rel ) {
	$path = $plugin_dir . DIRECTORY_SEPARATOR . $rel;
	if ( ! is_file( $path ) ) {
		$violations[] = "$rel is missing (the sealing engine must ship)";
		continue;
	}
	$src = (string) file_get_contents( $path );
	foreach ( $pro_only as $cls ) {
		if ( strpos( $src, $cls ) !== false ) {
			$violations[] = "$rel references Pro-only class \"$cls\" (not free-safe)";
		}
	}
}

if ( ! empty( $violations ) ) {
	fwrite( STDERR, "FAIL: sealing engine is not free-safe:\n" );
	foreach ( $violations as $v ) {
		fwrite( STDERR, "  - $v\n" );
	}
	exit( 1 );
}

echo 'PASS: sealing engine ships and is free-safe (' . count( $sealing_files ) . " files checked)\n";
exit( 0 );
