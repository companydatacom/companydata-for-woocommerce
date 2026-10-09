<?php
/**
 * Server-side client for the CompanyData API. The key never leaves PHP;
 * the checkout talks to our own REST route, which calls this.
 *
 * Only /api/company/search is used. With the fields it returns (name,
 * address, registration number) a checkout costs one search and no credit.
 *
 * @package CompanyData_WC
 */

namespace CompanyData_WC;

use WP_Error;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Api_Client {

	public static function is_connected(): bool {
		return '' !== Settings::api_key();
	}

	/**
	 * Search by name (prefix, with the API's own fuzzy fallbacks) or by
	 * registration number, inside one country. Cached so repeat lookups of
	 * the same text cost nothing.
	 *
	 * @return array|WP_Error { records: Company[], warnings: string[] }
	 */
	public static function search( string $q, string $country, int $limit = 8 ) {
		$q       = trim( preg_replace( '/\s+/', ' ', $q ) );
		$country = strtoupper( $country );
		$by_id   = looks_like_registration( $q );
		$params  = array(
			'countryCode' => $country,
			'statusCode'  => '0,1', // Head offices and single locations; no branches.
			'pageSize'    => max( 1, min( 50, $limit ) ),
		);
		if ( $by_id ) {
			$params['nationalId'] = preg_replace( '/[^A-Z0-9]/', '', strtoupper( $q ) );
		} else {
			$params['search'] = $q;
		}

		$ttl       = (int) Settings::get( 'cache_days', 30 ) * DAY_IN_SECONDS;
		$cache_key = 'companydata_s_' . md5( wp_json_encode( $params ) );
		if ( $ttl > 0 ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$res = self::request( '/api/company/search', $params );
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		self::count( $by_id ? 'lookup' : 'search' );

		$out = array(
			'records'  => array_map( fn( $r ) => self::shape( (array) $r, $country ), (array) ( $res['data']['records'] ?? array() ) ),
			'warnings' => array_values( array_map( fn( $w ) => (string) ( $w['code'] ?? '' ), (array) ( $res['meta']['warnings'] ?? array() ) ) ),
		);
		if ( $ttl > 0 ) {
			set_transient( $cache_key, $out, $ttl );
		}
		return $out;
	}

	/**
	 * One search record as the checkout needs it. Field names in the API
	 * carry spaces; the checkout gets WooCommerce's names.
	 */
	private static function shape( array $r, string $requested_country ): array {
		$str = fn( $k ) => trim( (string) ( $r[ $k ] ?? '' ) );
		$city = $str( 'City' );
		// Registers often store cities in capitals ("AMSTERDAM").
		if ( $city === strtoupper( $city ) && strlen( $city ) > 3 ) {
			$city = mb_convert_case( mb_strtolower( $city ), MB_CASE_TITLE );
		}
		return array(
			'id'           => $str( 'ID' ),
			'name'         => $str( 'Company Name' ),
			'tradeName'    => $str( 'Trade Name' ),
			'address_1'    => $str( 'Address 1' ),
			'address_2'    => $str( 'Address 2' ),
			'postcode'     => $str( 'Postal Code' ),
			'city'         => $city,
			'state'        => $str( 'State/Province' ),
			'country'      => country_code_from_name( $str( 'Country' ), $requested_country ),
			'countryName'  => $str( 'Country' ),
			'registration' => $str( 'Company Registration Number' ),
		);
	}

	/**
	 * A cheap request to prove the key works (used by the settings screen).
	 *
	 * @return true|WP_Error
	 */
	public static function test() {
		$res = self::request( '/api/company/search', array( 'search' => 'test', 'pageSize' => 1 ) );
		return is_wp_error( $res ) ? $res : true;
	}

	/* -------------------------------------------------------------- Transport */

	/**
	 * Perform a GET. Returns the decoded JSON or a WP_Error whose data carries
	 * the upstream HTTP status and, for quota errors, the upgrade URL.
	 */
	private static function request( string $path, array $query ) {
		$key = Settings::api_key();
		if ( '' === $key ) {
			return new WP_Error( 'companydata_no_key', __( 'No CompanyData API key is configured.', 'companydata-for-woocommerce' ), array( 'status' => 503 ) );
		}
		$url      = add_query_arg( array_map( 'rawurlencode', $query ), COMPANYDATA_WC_API_BASE . $path );
		$response = wp_remote_get( $url, array(
			'timeout' => 10,
			'headers' => array(
				'x-api-key'  => $key,
				'Accept'     => 'application/json',
				'User-Agent' => self::user_agent(),
			),
		) );
		if ( is_wp_error( $response ) ) {
			return new WP_Error( 'companydata_transport', $response->get_error_message(), array( 'status' => 502 ) );
		}
		$status = (int) wp_remote_retrieve_response_code( $response );
		$json   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $json ) ) {
			$json = array();
		}
		if ( $status >= 200 && $status < 300 ) {
			return $json;
		}
		$code = 'companydata_upstream';
		if ( 401 === $status ) {
			$code = 'companydata_bad_key';
		} elseif ( 403 === $status ) {
			$code = 'companydata_inactive';
		} elseif ( 429 === $status ) {
			$code = 'companydata_quota';
		}
		$data = array( 'status' => $status );
		foreach ( array( 'upgradeUrl', 'resetsAt', 'subscriptionStatus' ) as $k ) {
			if ( ! empty( $json[ $k ] ) ) {
				$data[ $k ] = $json[ $k ];
			}
		}
		return new WP_Error( $code, (string) ( $json['message'] ?? sprintf( 'HTTP %d', $status ) ), $data );
	}

	private static function user_agent(): string {
		global $wp_version;
		return sprintf( 'companydata-woocommerce/%s (WP %s; WC %s)', COMPANYDATA_WC_VERSION, $wp_version, defined( 'WC_VERSION' ) ? WC_VERSION : '?' );
	}

	/* ------------------------------------------------------- Local counters */

	/**
	 * Per-day counters for the Usage screen and the daily cap. The API does
	 * not return usage headers on every plan, so we count what we send.
	 */
	private static function count( string $type ): void {
		$counters = self::counters();
		$day      = gmdate( 'Y-m-d' );
		if ( ! isset( $counters[ $day ] ) ) {
			$counters[ $day ] = array();
			ksort( $counters );
			while ( count( $counters ) > 45 ) {
				array_shift( $counters );
			}
		}
		$counters[ $day ][ $type ] = ( $counters[ $day ][ $type ] ?? 0 ) + 1;
		update_option( 'companydata_counters', $counters, false );
	}

	public static function counters(): array {
		$c = get_option( 'companydata_counters', array() );
		return is_array( $c ) ? $c : array();
	}
}
