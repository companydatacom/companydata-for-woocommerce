<?php
/**
 * Bootstrap.
 *
 * @package CompanyData_WC
 */

namespace CompanyData_WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

final class Plugin {

	private static ?Plugin $instance = null;

	public static function instance(): Plugin {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	private function __construct() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			add_action( 'admin_notices', array( $this, 'needs_woocommerce' ) );
			return;
		}
		REST::init();
		Checkout::init();
		Checkout_Blocks::init();
		Privacy::init();
		if ( is_admin() ) {
			Admin::init();
		}
	}

	public function needs_woocommerce(): void {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		echo '<div class="notice notice-error"><p>' . esc_html__( 'CompanyData Company Lookup needs WooCommerce to be installed and active.', 'companydata-company-lookup' ) . '</p></div>';
	}
}
