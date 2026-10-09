<?php
/**
 * Uninstall: removes options and caches. Order and customer meta is only
 * removed when the owner opted in.
 *
 * @package CompanyData_WC
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

$companydata_settings = get_option( 'companydata_settings', array() );

delete_option( 'companydata_settings' );
delete_option( 'companydata_api_key' );
delete_option( 'companydata_counters' );

global $wpdb;
$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s", $wpdb->esc_like( '_transient_companydata_' ) . '%', $wpdb->esc_like( '_transient_timeout_companydata_' ) . '%' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( ! empty( $companydata_settings['delete_on_uninstall'] ) ) {
	delete_metadata( 'user', 0, 'companydata_id', '', true );
	delete_metadata( 'user', 0, 'companydata_registration', '', true );
	// Orders: both the legacy postmeta store and the HPOS meta table.
	$wpdb->query( $wpdb->prepare( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN (%s, %s)", '_companydata_id', '_companydata_registration' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$companydata_orders_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $companydata_orders_meta ) ) === $companydata_orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s)', $companydata_orders_meta, '_companydata_id', '_companydata_registration' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
