<?php
/**
 * REST route under /wp-json/companydata/v1. The checkout script only ever
 * talks to this; it proxies to the CompanyData API with the server-side key.
 *
 * @package CompanyData_WC
 */

namespace CompanyData_WC;

use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class REST {

	const NS = 'companydata/v1';

	public static function init(): void {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Guests check out too, so there is no capability to check. The REST
	 * nonce ties the request to a checkout page this site served; per-IP and
	 * daily limits apply in the callback.
	 */
	public static function can_lookup( WP_REST_Request $req ): bool {
		return (bool) wp_verify_nonce( (string) $req->get_header( 'x_wp_nonce' ), 'wp_rest' );
	}

	public static function routes(): void {
		register_rest_route( self::NS, '/lookup', array(
			'methods'             => WP_REST_Server::READABLE,
			'callback'            => array( __CLASS__, 'lookup' ),
			'permission_callback' => array( __CLASS__, 'can_lookup' ),
			'args'                => array(
				'q'       => array( 'required' => true, 'sanitize_callback' => 'sanitize_text_field' ),
				'country' => array( 'required' => true, 'sanitize_callback' => fn( $v ) => strtoupper( sanitize_text_field( (string) $v ) ) ),
			),
		) );
	}

	public static function lookup( WP_REST_Request $req ) {
		if ( ! Api_Client::is_connected() ) {
			return self::error( 'companydata_not_connected', __( 'Company lookup is not connected.', 'companydata-company-lookup' ), 503 );
		}
		$q       = trim( (string) $req['q'] );
		$country = (string) $req['country'];
		if ( ! in_array( $country, allowed_countries(), true ) ) {
			return self::error( 'companydata_country', __( 'Company lookup is not available for this country.', 'companydata-company-lookup' ), 400 );
		}
		$min = (int) Settings::get( 'min_chars', 3 );
		if ( mb_strlen( $q ) < $min || mb_strlen( $q ) > 80 ) {
			return rest_ensure_response( array( 'data' => array(), 'warnings' => array() ) );
		}
		if ( ! consume_ip( 'lookup', (int) Settings::get( 'limit_ip_day', 200 ), DAY_IN_SECONDS ) ) {
			return self::error( 'companydata_throttled', __( 'Too many lookups. Please fill in the address by hand.', 'companydata-company-lookup' ), 429 );
		}
		if ( ! under_daily_cap() ) {
			return self::error( 'companydata_cap', __( 'Company lookup has reached its daily limit.', 'companydata-company-lookup' ), 429 );
		}
		$res = Api_Client::search( $q, $country, (int) Settings::get( 'results', 8 ) );
		if ( is_wp_error( $res ) ) {
			$data   = (array) $res->get_error_data();
			$status = (int) ( $data['status'] ?? 500 );
			// A bad or exhausted key is the owner's problem, not the shopper's: never show it as 401.
			return self::error( $res->get_error_code(), __( 'Company lookup is temporarily unavailable.', 'companydata-company-lookup' ), in_array( $status, array( 401, 403 ), true ) ? 503 : $status );
		}
		return rest_ensure_response( array( 'data' => $res['records'], 'warnings' => $res['warnings'] ) );
	}

	private static function error( string $code, string $message, int $status ): WP_REST_Response {
		return new WP_REST_Response( array( 'code' => $code, 'message' => $message ), $status );
	}
}
