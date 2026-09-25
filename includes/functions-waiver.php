<?php
/**
 * EU/UK right-of-withdrawal waiver helpers (2026-09-25). Free-safe, no Pro deps.
 *
 * @package CrawlerToll
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether paid unlocks must collect the reader's express consent to immediate
 * access and acknowledgement that the 14-day right of withdrawal is lost
 * (Directive 2011/83/EU Art. 16(m); UK Consumer Contracts Regulations 2013
 * reg. 37). The publisher is the trader; CrawlerToll only collects and records
 * the consent for them.
 *
 * 'auto' (default) = on when the site's timezone is Europe/* or its locale is
 * an EU/EEA/UK one. Publishers selling to European readers from elsewhere
 * should switch it to 'on'.
 *
 * @param array|null $settings
 * @return bool
 */
function crawlertoll_waiver_required( $settings = null ) {
	$settings = is_array( $settings ) ? $settings : crawlertoll_get_settings();
	$mode     = isset( $settings['withdrawal_waiver'] ) ? (string) $settings['withdrawal_waiver'] : 'auto';
	if ( 'on' === $mode ) {
		return true;
	}
	if ( 'off' === $mode ) {
		return false;
	}
	return crawlertoll_site_looks_european();
}

/**
 * Heuristic for 'auto': Europe/* timezone, or a locale whose country is in the
 * EU, EEA, UK or Switzerland. Pure over the two inputs when they are given.
 *
 * @param string|null $timezone
 * @param string|null $locale
 * @return bool
 */
function crawlertoll_site_looks_european( $timezone = null, $locale = null ) {
	$timezone = null !== $timezone ? (string) $timezone : (string) get_option( 'timezone_string', '' );
	$locale   = null !== $locale ? (string) $locale : ( function_exists( 'get_locale' ) ? (string) get_locale() : '' );
	if ( 0 === strpos( $timezone, 'Europe/' ) ) {
		return true;
	}
	$countries = array( 'AT', 'BE', 'BG', 'HR', 'CY', 'CZ', 'DK', 'EE', 'FI', 'FR', 'DE', 'GR', 'HU', 'IE', 'IT', 'LV', 'LT', 'LU', 'MT', 'NL', 'PL', 'PT', 'RO', 'SK', 'SI', 'ES', 'SE', 'IS', 'LI', 'NO', 'GB', 'CH' );
	if ( preg_match( '/^[a-z]{2,3}_([A-Z]{2})/', $locale, $m ) ) {
		return in_array( $m[1], $countries, true );
	}
	return false;
}

/**
 * The consent sentence shown next to the checkbox and stored with the card
 * payment. Translatable; kept under 500 characters (Stripe metadata limit).
 *
 * @return string
 */
function crawlertoll_waiver_text() {
	return __( 'I want access right away. I agree that the content is unlocked immediately and understand that I therefore lose my 14-day right of withdrawal.', 'crawlertoll' );
}
