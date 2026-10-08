<?php
/**
 * WordPress privacy tools: export and erase the company meta kept on a
 * customer account. Order meta is covered by WooCommerce's own exporter.
 *
 * @package CompanyData_WC
 */

namespace CompanyData_WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Privacy {

	public static function init(): void {
		add_filter( 'wp_privacy_personal_data_exporters', array( __CLASS__, 'register_exporter' ) );
		add_filter( 'wp_privacy_personal_data_erasers', array( __CLASS__, 'register_eraser' ) );
	}

	public static function register_exporter( array $exporters ): array {
		$exporters['companydata-for-woocommerce'] = array(
			'exporter_friendly_name' => __( 'CompanyData for WooCommerce', 'companydata-for-woocommerce' ),
			'callback'               => array( __CLASS__, 'export' ),
		);
		return $exporters;
	}

	public static function register_eraser( array $erasers ): array {
		$erasers['companydata-for-woocommerce'] = array(
			'eraser_friendly_name' => __( 'CompanyData for WooCommerce', 'companydata-for-woocommerce' ),
			'callback'             => array( __CLASS__, 'erase' ),
		);
		return $erasers;
	}

	public static function export( string $email ): array {
		$user = get_user_by( 'email', $email );
		$data = array();
		if ( $user ) {
			$id  = (string) get_user_meta( $user->ID, 'companydata_id', true );
			$reg = (string) get_user_meta( $user->ID, 'companydata_registration', true );
			if ( $id || $reg ) {
				$data[] = array(
					'group_id'    => 'companydata',
					'group_label' => __( 'Company register', 'companydata-for-woocommerce' ),
					'item_id'     => 'companydata-' . $user->ID,
					'data'        => array(
						array( 'name' => __( 'Registration number', 'companydata-for-woocommerce' ), 'value' => $reg ),
						array( 'name' => __( 'CompanyData ID', 'companydata-for-woocommerce' ), 'value' => $id ),
					),
				);
			}
		}
		return array( 'data' => $data, 'done' => true );
	}

	public static function erase( string $email ): array {
		$user    = get_user_by( 'email', $email );
		$removed = false;
		if ( $user ) {
			$removed = delete_user_meta( $user->ID, 'companydata_id' ) || delete_user_meta( $user->ID, 'companydata_registration' );
		}
		return array( 'items_removed' => $removed, 'items_retained' => false, 'messages' => array(), 'done' => true );
	}
}
