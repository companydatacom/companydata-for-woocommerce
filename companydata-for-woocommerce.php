<?php
/**
 * Plugin Name:       CompanyData for WooCommerce - Company Lookup & Address Autofill
 * Plugin URI:        https://github.com/companydatacom/companydata-for-woocommerce
 * Description:       Customers type their company name or registration number at checkout and the billing address fills itself from 400M+ company records. The registration number is saved on the order.
 * Version:           0.1.0
 * Requires at least: 6.4
 * Requires PHP:      8.0
 * Requires Plugins:  woocommerce
 * WC requires at least: 8.0
 * WC tested up to:   11.2
 * Author:            CompanyData
 * Author URI:        https://companydata.com/
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       companydata-for-woocommerce
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'COMPANYDATA_WC_VERSION', '0.1.0' );
define( 'COMPANYDATA_WC_FILE', __FILE__ );
define( 'COMPANYDATA_WC_DIR', plugin_dir_path( __FILE__ ) );
define( 'COMPANYDATA_WC_URL', plugin_dir_url( __FILE__ ) );
define( 'COMPANYDATA_WC_API_BASE', 'https://app.companydata.com' );
define( 'COMPANYDATA_WC_SITE', 'https://companydata.com' );

require_once COMPANYDATA_WC_DIR . 'includes/helpers.php';
require_once COMPANYDATA_WC_DIR . 'includes/class-settings.php';
require_once COMPANYDATA_WC_DIR . 'includes/class-api-client.php';
require_once COMPANYDATA_WC_DIR . 'includes/class-rest.php';
require_once COMPANYDATA_WC_DIR . 'includes/class-checkout.php';
require_once COMPANYDATA_WC_DIR . 'includes/class-privacy.php';
require_once COMPANYDATA_WC_DIR . 'includes/class-admin.php';
require_once COMPANYDATA_WC_DIR . 'includes/class-plugin.php';

// WooCommerce feature compatibility: HPOS yes; the block checkout is not supported in this version.
add_action( 'before_woocommerce_init', function () {
	if ( class_exists( '\Automattic\WooCommerce\Utilities\FeaturesUtil' ) ) {
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'custom_order_tables', __FILE__, true );
		\Automattic\WooCommerce\Utilities\FeaturesUtil::declare_compatibility( 'cart_checkout_blocks', __FILE__, false );
	}
} );

add_action( 'plugins_loaded', array( 'CompanyData_WC\\Plugin', 'instance' ) );
