<?php
/**
 * Options storage. One serialized option, plus the API key kept separately
 * and obfuscated so it is not readable in a plain database dump.
 *
 * @package CompanyData_WC
 */

namespace CompanyData_WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Settings {

	const OPTION     = 'cdwc_settings';
	const KEY_OPTION = 'cdwc_api_key';

	public static function defaults(): array {
		return array(
			'countries_mode'     => 'shop', // shop | all | list
			'countries_list'     => array(),
			'min_chars'          => 3,
			'debounce_ms'        => 350,
			'results'            => 8,
			'cache_days'         => 30,
			'limit_ip_day'       => 200,
			'daily_cap'          => 0,
			'shipping'           => 1,
			'fill_fields'        => array( 'address_1', 'address_2', 'postcode', 'city', 'state', 'country' ),
			'registration_field' => 'hidden', // hidden | optional | required
			'credit_link'        => 0,
			'delete_on_uninstall' => 0,
		);
	}

	public static function all(): array {
		$saved = get_option( self::OPTION, array() );
		if ( ! is_array( $saved ) ) {
			$saved = array();
		}
		return array_merge( self::defaults(), $saved );
	}

	public static function get( string $key, $fallback = null ) {
		$all = self::all();
		return array_key_exists( $key, $all ) ? $all[ $key ] : $fallback;
	}

	/**
	 * Sanitize the settings array coming from the admin form.
	 */
	public static function sanitize( $input ): array {
		$input = is_array( $input ) ? $input : array();
		$out   = self::all();

		// The key is stored in its own option, never in this array.
		if ( ! empty( $input['clear_api_key'] ) ) {
			self::set_api_key( '' );
		} elseif ( ! empty( $input['api_key'] ) ) {
			self::set_api_key( trim( sanitize_text_field( (string) $input['api_key'] ) ) );
		}

		$mode                  = sanitize_key( $input['countries_mode'] ?? 'shop' );
		$out['countries_mode'] = in_array( $mode, array( 'shop', 'all', 'list' ), true ) ? $mode : 'shop';
		$codes                 = preg_split( '/[\s,]+/', strtoupper( sanitize_text_field( (string) ( $input['countries_list'] ?? '' ) ) ) ) ?: array();
		$out['countries_list'] = array_values( array_unique( array_filter( $codes, fn( $c ) => preg_match( '/^[A-Z]{2}$/', $c ) ) ) );

		$out['min_chars']    = max( 2, min( 6, (int) ( $input['min_chars'] ?? 3 ) ) );
		$out['debounce_ms']  = max( 150, min( 1500, (int) ( $input['debounce_ms'] ?? 350 ) ) );
		$out['results']      = max( 3, min( 20, (int) ( $input['results'] ?? 8 ) ) );
		$out['cache_days']   = max( 0, min( 365, (int) ( $input['cache_days'] ?? 30 ) ) );
		$out['limit_ip_day'] = max( 0, (int) ( $input['limit_ip_day'] ?? 200 ) );
		$out['daily_cap']    = max( 0, (int) ( $input['daily_cap'] ?? 0 ) );
		$out['shipping']     = empty( $input['shipping'] ) ? 0 : 1;

		$allowed            = array( 'address_1', 'address_2', 'postcode', 'city', 'state', 'country' );
		$fields             = isset( $input['fill_fields'] ) && is_array( $input['fill_fields'] ) ? $input['fill_fields'] : array();
		$out['fill_fields'] = array_values( array_intersect( $allowed, array_map( 'sanitize_key', $fields ) ) );

		$reg                       = sanitize_key( $input['registration_field'] ?? 'hidden' );
		$out['registration_field'] = in_array( $reg, array( 'hidden', 'optional', 'required' ), true ) ? $reg : 'hidden';
		$out['credit_link']        = empty( $input['credit_link'] ) ? 0 : 1;
		$out['delete_on_uninstall'] = empty( $input['delete_on_uninstall'] ) ? 0 : 1;

		return $out;
	}

	/* --------------------------------------------------------------- Key */

	/**
	 * The API key: wp-config constant wins, then the stored (obfuscated) option.
	 */
	public static function api_key(): string {
		if ( defined( 'COMPANYDATA_API_KEY' ) && COMPANYDATA_API_KEY ) {
			return (string) COMPANYDATA_API_KEY;
		}
		$stored = get_option( self::KEY_OPTION, '' );
		return $stored ? self::unobfuscate( (string) $stored ) : '';
	}

	public static function has_constant_key(): bool {
		return defined( 'COMPANYDATA_API_KEY' ) && (bool) COMPANYDATA_API_KEY;
	}

	public static function set_api_key( string $key ): void {
		if ( '' === $key ) {
			delete_option( self::KEY_OPTION );
			return;
		}
		update_option( self::KEY_OPTION, self::obfuscate( $key ), false );
	}

	/**
	 * Masked key for display: first 4 and last 4 characters.
	 */
	public static function masked_key(): string {
		$key = self::api_key();
		if ( strlen( $key ) < 12 ) {
			return $key ? '****' : '';
		}
		return substr( $key, 0, 4 ) . '****' . substr( $key, -4 );
	}

	/**
	 * Symmetric obfuscation keyed on the site's auth salt. Not a substitute
	 * for server security; it keeps the key out of casual reads of the
	 * options table.
	 */
	private static function obfuscate( string $plain ): string {
		if ( function_exists( 'openssl_encrypt' ) ) {
			$key = hash( 'sha256', wp_salt( 'auth' ), true );
			$iv  = random_bytes( 16 );
			$enc = openssl_encrypt( $plain, 'aes-256-cbc', $key, OPENSSL_RAW_DATA, $iv );
			if ( false !== $enc ) {
				return 'enc:' . base64_encode( $iv . $enc ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
			}
		}
		return 'b64:' . base64_encode( $plain ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
	}

	private static function unobfuscate( string $stored ): string {
		if ( 0 === strpos( $stored, 'enc:' ) && function_exists( 'openssl_decrypt' ) ) {
			$raw = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			if ( false === $raw || strlen( $raw ) < 17 ) {
				return '';
			}
			$key = hash( 'sha256', wp_salt( 'auth' ), true );
			$dec = openssl_decrypt( substr( $raw, 16 ), 'aes-256-cbc', $key, OPENSSL_RAW_DATA, substr( $raw, 0, 16 ) );
			return false === $dec ? '' : $dec;
		}
		if ( 0 === strpos( $stored, 'b64:' ) ) {
			$dec = base64_decode( substr( $stored, 4 ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode
			return false === $dec ? '' : $dec;
		}
		return $stored;
	}
}
