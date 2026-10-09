<?php
/**
 * Block-based checkout: the optional registration-number field (Additional
 * Checkout Fields API) and the CompanyData ID and number sent as Store API
 * extension data, saved on the order like the classic checkout does.
 *
 * @package CompanyData_WC
 */

namespace CompanyData_WC;

use Automattic\WooCommerce\StoreApi\Schemas\V1\CheckoutSchema;
use WC_Order;
use WP_REST_Request;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Checkout_Blocks {

	const FIELD = 'companydata/registration';
	const KEYS  = array( 'billing_id', 'billing_registration', 'shipping_id', 'shipping_registration' );

	public static function init(): void {
		if ( did_action( 'woocommerce_blocks_loaded' ) ) {
			self::register_data();
		} else {
			add_action( 'woocommerce_blocks_loaded', array( __CLASS__, 'register_data' ) );
		}
		add_action( 'woocommerce_init', array( __CLASS__, 'register_field' ) );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'save' ), 10, 2 );
	}

	/**
	 * Let the checkout request carry the picked company.
	 */
	public static function register_data(): void {
		if ( ! function_exists( 'woocommerce_store_api_register_endpoint_data' ) ) {
			return;
		}
		$schema = array();
		foreach ( self::KEYS as $key ) {
			$schema[ $key ] = array(
				'description' => __( 'Company picked from the CompanyData lookup.', 'companydata-for-woocommerce' ),
				'type'        => array( 'string', 'null' ),
				'context'     => array(),
			);
		}
		woocommerce_store_api_register_endpoint_data( array(
			'endpoint'        => CheckoutSchema::IDENTIFIER,
			'namespace'       => 'companydata',
			'schema_callback' => fn() => $schema,
		) );
	}

	/**
	 * The visible registration-number field, when enabled. WooCommerce stores
	 * and shows its value itself; save() also copies it to our order meta.
	 */
	public static function register_field(): void {
		$mode = (string) Settings::get( 'registration_field', 'hidden' );
		if ( 'hidden' === $mode || ! function_exists( 'woocommerce_register_additional_checkout_field' ) ) {
			return;
		}
		woocommerce_register_additional_checkout_field( array(
			'id'            => self::FIELD,
			'label'         => __( 'Company registration number', 'companydata-for-woocommerce' ),
			'optionalLabel' => __( 'Company registration number (optional)', 'companydata-for-woocommerce' ),
			'location'      => 'address',
			'required'      => 'required' === $mode,
			'attributes'    => array( 'autocomplete' => 'off' ),
		) );
	}

	public static function save( WC_Order $order, WP_REST_Request $request ): void {
		$ext  = (array) ( $request['extensions']['companydata'] ?? array() );
		$read = fn( string $key ) => sanitize_text_field( (string) ( $ext[ $key ] ?? '' ) );

		// The shipping form comes first in the block checkout and often doubles as billing.
		$id  = $read( 'billing_id' ) ?: $read( 'shipping_id' );
		$reg = $read( 'billing_registration' ) ?: $read( 'shipping_registration' );
		if ( '' === $reg ) {
			$typed = $request['billing_address'][ self::FIELD ] ?? $request['shipping_address'][ self::FIELD ] ?? '';
			$reg   = sanitize_text_field( (string) $typed );
		}

		if ( '' !== $id ) {
			$order->update_meta_data( Checkout::META_ID, $id );
		}
		if ( '' !== $reg ) {
			$order->update_meta_data( Checkout::META_REG, $reg );
		}

		$customer_id = $order->get_customer_id();
		if ( $customer_id && ( '' !== $id || '' !== $reg ) ) {
			$customer = new \WC_Customer( $customer_id );
			if ( '' !== $id ) {
				$customer->update_meta_data( 'companydata_id', $id );
			}
			if ( '' !== $reg ) {
				$customer->update_meta_data( 'companydata_registration', $reg );
			}
			$customer->save();
		}
	}
}
