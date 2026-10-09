<?php
/**
 * Small shared helpers: country mapping, throttles, links.
 *
 * @package CompanyData_WC
 */

namespace CompanyData_WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Build a companydata.com URL with plugin attribution.
 */
function site_url( string $path, array $extra = array() ): string {
	return add_query_arg( array_merge( array( 'utm_source' => 'wordpress-plugin', 'utm_medium' => 'plugin' ), $extra ), COMPANYDATA_WC_SITE . '/' . ltrim( $path, '/' ) );
}

/**
 * ISO-2 codes the lookup may search in: the shop's selling countries by
 * default, or the owner's own list.
 */
function allowed_countries(): array {
	$mode = (string) Settings::get( 'countries_mode', 'shop' );
	if ( 'list' === $mode ) {
		$list = array_filter( array_map( 'strtoupper', (array) Settings::get( 'countries_list', array() ) ) );
		if ( $list ) {
			return array_values( $list );
		}
	}
	if ( 'all' === $mode ) {
		return array_keys( WC()->countries->get_countries() );
	}
	return array_keys( WC()->countries->get_allowed_countries() );
}

/**
 * The API returns country names ("Netherlands", "USA", "Unitedkingdom"),
 * not codes. Map tolerantly onto WooCommerce's country list; when the name
 * matches the country the visitor already selected, keep that.
 */
function country_code_from_name( string $name, string $preferred = '' ): string {
	static $map = null;
	$norm = fn( string $s ): string => preg_replace( '/[^a-z]/', '', strtolower( remove_accents( $s ) ) );
	$n    = $norm( $name );
	if ( '' === $n ) {
		return $preferred;
	}
	if ( null === $map ) {
		$map = array();
		foreach ( WC()->countries->get_countries() as $code => $label ) {
			$map[ $norm( html_entity_decode( $label, ENT_QUOTES ) ) ] = $code;
		}
		$map += array(
			'usa'                  => 'US',
			'unitedstates'         => 'US',
			'unitedstatesofamerica' => 'US',
			'unitedkingdom'        => 'GB',
			'uk'                   => 'GB',
			'greatbritain'         => 'GB',
			'england'              => 'GB',
			'scotland'             => 'GB',
			'wales'                => 'GB',
			'northernireland'      => 'GB',
			'holland'              => 'NL',
			'thenetherlands'       => 'NL',
			'russia'               => 'RU',
			'korea'                => 'KR',
			'southkorea'           => 'KR',
			'czechia'              => 'CZ',
			'czechrepublic'        => 'CZ',
			'vietnam'              => 'VN',
			'uae'                  => 'AE',
			'unitedarabemirates'   => 'AE',
			'hongkong'             => 'HK',
			'taiwan'               => 'TW',
			'macedonia'            => 'MK',
			'ivorycoast'           => 'CI',
			'swaziland'            => 'SZ',
			'turkey'               => 'TR',
			'turkiye'              => 'TR',
		);
	}
	if ( $preferred && isset( $map[ $n ] ) && $map[ $n ] === $preferred ) {
		return $preferred;
	}
	return $map[ $n ] ?? $preferred;
}

/**
 * Does the text look like a registration number rather than a name?
 * At least five digits and nothing that reads like a word.
 */
function looks_like_registration( string $q ): bool {
	return preg_match_all( '/\d/', $q ) >= 5 && ! preg_match( '/[a-z]{3,}/i', $q );
}

/**
 * Client IP for throttling.
 */
function client_ip(): string {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
	return $ip ?: '0.0.0.0';
}

/**
 * Per-IP throttle for the public lookup route. 0 = unlimited.
 */
function consume_ip( string $bucket, int $limit, int $window ): bool {
	if ( $limit <= 0 ) {
		return true;
	}
	$key   = 'companydata_ip_' . $bucket . '_' . md5( client_ip() ) . '_' . floor( time() / $window );
	$count = (int) get_transient( $key );
	if ( $count >= $limit ) {
		return false;
	}
	set_transient( $key, $count + 1, $window );
	return true;
}

/**
 * Site-wide daily cap on API searches, so one busy day cannot use up the
 * month's search allowance. 0 = no cap.
 */
function under_daily_cap(): bool {
	$cap = (int) Settings::get( 'daily_cap', 0 );
	if ( $cap <= 0 ) {
		return true;
	}
	$counters = Api_Client::counters();
	$today    = $counters[ gmdate( 'Y-m-d' ) ] ?? array();
	return array_sum( $today ) < $cap;
}

/**
 * Optional "Company lookup by CompanyData" line under the field. Off by
 * default (wordpress.org guideline 10).
 */
function credit_html(): string {
	if ( ! Settings::get( 'credit_link', 0 ) ) {
		return '';
	}
	return '<p class="companydata-credit"><a href="' . esc_url( site_url( '', array( 'utm_medium' => 'credit' ) ) ) . '" target="_blank" rel="noopener">' . esc_html__( 'Company lookup by CompanyData', 'companydata-company-lookup' ) . '</a></p>';
}
