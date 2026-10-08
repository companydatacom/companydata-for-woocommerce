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

$cdwc_settings = get_option( 'cdwc_settings', array() );

delete_option( 'cdwc_settings' );
delete_option( 'cdwc_api_key' );
delete_option( 'cdwc_counters' );

global $wpdb;
$wpdb->query( "DELETE FROM {$wpdb->options} WHERE option_name LIKE '_transient_cdwc_%' OR option_name LIKE '_transient_timeout_cdwc_%'" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery

if ( ! empty( $cdwc_settings['delete_on_uninstall'] ) ) {
	delete_metadata( 'user', 0, 'companydata_id', '', true );
	delete_metadata( 'user', 0, 'companydata_registration', '', true );
	// Orders: both the legacy postmeta store and the HPOS meta table.
	$wpdb->query( "DELETE FROM {$wpdb->postmeta} WHERE meta_key IN ('_companydata_id', '_companydata_registration')" ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	$cdwc_orders_meta = $wpdb->prefix . 'wc_orders_meta';
	if ( $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $cdwc_orders_meta ) ) === $cdwc_orders_meta ) { // phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->query( $wpdb->prepare( 'DELETE FROM %i WHERE meta_key IN (%s, %s)', $cdwc_orders_meta, '_companydata_id', '_companydata_registration' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}
}
