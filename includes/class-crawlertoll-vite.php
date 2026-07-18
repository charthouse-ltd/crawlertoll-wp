<?php
/**
 * Enqueue a Vite-built React app bundle (free|pro) into wp-admin by reading its
 * per-app manifest. Vite output is ES modules, so the script tag must carry
 * type="module" or the browser throws "Cannot use import statement outside a
 * module" and the page renders blank. File-existence-guarded so a stripped
 * bundle (the free build has no pro-app) degrades to nothing instead of a 404.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CrawlerToll_Vite {

	/**
	 * Enqueue the built bundle for an app.
	 *
	 * @param string $app    'free' or 'pro'.
	 * @param string $handle Script handle.
	 * @return bool True if enqueued, false if the bundle is absent.
	 */
	public static function enqueue( $app, $handle ) {
		$app = in_array( $app, array( 'pro', 'unlock' ), true ) ? $app : 'free';
		$dir = CRAWLERTOLL_PLUGIN_DIR . "assets/app/{$app}-app/";
		$url = plugin_dir_url( CRAWLERTOLL_PLUGIN_FILE ) . "assets/app/{$app}-app/";

		$manifest_file = $dir . 'manifest.json';
		if ( ! file_exists( $manifest_file ) ) {
			return false;
		}
		$manifest = json_decode( (string) file_get_contents( $manifest_file ), true ); // phpcs:ignore WordPress.WP.AlternativeFunctions
		if ( ! is_array( $manifest ) || empty( $manifest[ "src/{$app}/main.tsx" ]['file'] ) ) {
			return false;
		}

		$entry = $manifest[ "src/{$app}/main.tsx" ]['file'];
		wp_enqueue_script( $handle, $url . $entry, array(), (string) filemtime( $dir . $entry ), true );
		self::mark_module( $handle );

		// Enqueue every CSS asset the manifest lists (cssCodeSplit is off, so one file).
		foreach ( $manifest as $chunk ) {
			if ( ! empty( $chunk['file'] ) && '.css' === substr( $chunk['file'], -4 ) ) {
				wp_enqueue_style( $handle . '-' . substr( md5( $chunk['file'] ), 0, 8 ), $url . $chunk['file'], array(), (string) filemtime( $dir . $chunk['file'] ) );
			}
		}
		return true;
	}

	/**
	 * Force type="module" on our handle's <script> tag (Vite output is ESM).
	 *
	 * We inject the attribute into the existing external <script src> tag rather
	 * than rebuilding it from $src: modern WordPress concatenates any
	 * wp_add_inline_script('before') content (our window.crawlertollFree data
	 * blob) into the SAME $tag string passed to this filter, and rebuilding from
	 * $src alone silently drops it. The lookahead targets only the tag bearing
	 * this handle's id="{handle}-js" (not the "-before"/"-after" inline tags),
	 * quote-agnostic so it holds across WP versions.
	 *
	 * @param string $handle Script handle to mark.
	 * @return void
	 */
	private static function mark_module( $handle ) {
		$id = $handle . '-js';
		add_filter(
			'script_loader_tag',
			function ( $tag, $h ) use ( $handle, $id ) {
				if ( $h !== $handle ) {
					return $tag;
				}
				return preg_replace(
					'#<script(\s+)(?=[^>]*\bid=["\']' . preg_quote( $id, '#' ) . '["\'])#',
					'<script type="module"$1',
					$tag,
					1
				);
			},
			10,
			2
		);
	}
}
