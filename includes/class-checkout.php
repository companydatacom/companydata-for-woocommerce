<?php
/**
 * Classic (shortcode) checkout: the script on the company field, the
 * optional registration-number field, and the order and customer meta.
 * The block checkout adds to this in Checkout_Blocks.
 *
 * @package CompanyData_WC
 */

namespace CompanyData_WC;

use WC_Order;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Checkout {

	const META_ID  = '_companydata_id';
	const META_REG = '_companydata_registration';

	public static function init(): void {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'fields' ) );
		add_filter( 'woocommerce_default_address_fields', array( __CLASS__, 'country_first' ) );
		add_action( 'woocommerce_after_checkout_billing_form', array( __CLASS__, 'hidden_inputs' ) );
		add_action( 'woocommerce_after_checkout_shipping_form', array( __CLASS__, 'hidden_inputs_shipping' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'save_order' ), 10, 2 );
		add_action( 'woocommerce_checkout_update_customer', array( __CLASS__, 'save_customer' ), 10, 2 );
		add_action( 'woocommerce_admin_order_data_after_billing_address', array( __CLASS__, 'admin_order' ) );
		add_action( 'show_user_profile', array( __CLASS__, 'user_profile' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'user_profile' ) );
	}

	/* ---------------------------------------------------------------- Front */

	public static function assets(): void {
		if ( ! function_exists( 'is_checkout' ) || is_wc_endpoint_url( 'order-received' ) || ! Api_Client::is_connected() ) {
			return;
		}
		$blocks = has_block( 'woocommerce/checkout' );
		if ( ! $blocks && ! is_checkout() ) {
			return;
		}
		wp_enqueue_style( 'companydata-checkout', COMPANYDATA_WC_URL . 'assets/css/checkout.css', array(), COMPANYDATA_WC_VERSION );
		if ( $blocks ) {
			wp_enqueue_script( 'companydata-checkout', COMPANYDATA_WC_URL . 'assets/js/checkout-blocks.js', array( 'wp-data', 'wc-settings' ), COMPANYDATA_WC_VERSION, true );
		} else {
			wp_enqueue_script( 'companydata-checkout', COMPANYDATA_WC_URL . 'assets/js/checkout.js', array( 'jquery', 'wc-checkout' ), COMPANYDATA_WC_VERSION, true );
		}
		wp_add_inline_script( 'companydata-checkout', 'window.companydataConfig = ' . wp_json_encode( array(
			'restUrl'   => esc_url_raw( rest_url( REST::NS . '/lookup' ) ),
			'nonce'     => wp_create_nonce( 'wp_rest' ),
			'countries' => allowed_countries(),
			'minChars'  => (int) Settings::get( 'min_chars', 3 ),
			'debounce'  => (int) Settings::get( 'debounce_ms', 350 ),
			'fill'      => (array) Settings::get( 'fill_fields', array() ),
			'shipping'  => (bool) Settings::get( 'shipping', 1 ),
			'regField'  => 'hidden' !== Settings::get( 'registration_field', 'hidden' ),
			'credit'    => credit_html(),
			'i18n'      => array(
				'label'       => __( 'Company suggestions', 'companydata-company-lookup' ),
				'filled'      => __( 'Address filled in from the company register. Please check it.', 'companydata-company-lookup' ),
				'noMatch'     => __( 'No company found. You can type the address yourself.', 'companydata-company-lookup' ),
				'other'       => __( 'Registered in another country', 'companydata-company-lookup' ),
				'unavailable' => __( 'Company lookup is unavailable right now. Please type the address.', 'companydata-company-lookup' ),
				'reg'         => __( 'reg.', 'companydata-company-lookup' ),
			),
		) ) . ';', 'before' );
	}

	/**
	 * Lookups search the selected country, so ask for it before Company
	 * (priority 30). Set on the default address fields so WooCommerce's
	 * per-country reordering keeps it there.
	 */
	public static function country_first( array $fields ): array {
		if ( Settings::get( 'country_first' ) && isset( $fields['country'] ) ) {
			$fields['country']['priority'] = 25;
		}
		return $fields;
	}

	/**
	 * Optional visible registration-number field under Company.
	 */
	public static function fields( array $fields ): array {
		$mode = (string) Settings::get( 'registration_field', 'hidden' );
		if ( 'hidden' === $mode || ! isset( $fields['billing']['billing_company'] ) ) {
			return $fields;
		}
		$fields['billing']['billing_company_registration'] = array(
			'label'        => __( 'Company registration number', 'companydata-company-lookup' ),
			'placeholder'  => __( 'KvK, Companies House, SIREN...', 'companydata-company-lookup' ),
			'required'     => 'required' === $mode,
			'class'        => array( 'form-row-wide', 'companydata-registration' ),
			'autocomplete' => 'off',
			'priority'     => (int) ( $fields['billing']['billing_company']['priority'] ?? 30 ) + 1,
		);
		return $fields;
	}

	public static function hidden_inputs(): void {
		self::hidden( 'billing' );
	}

	public static function hidden_inputs_shipping(): void {
		if ( Settings::get( 'shipping', 1 ) ) {
			self::hidden( 'shipping' );
		}
	}

	private static function hidden( string $type ): void {
		echo '<input type="hidden" name="companydata_' . esc_attr( $type ) . '_id" class="companydata-id" value="">';
		echo '<input type="hidden" name="companydata_' . esc_attr( $type ) . '_registration" class="companydata-reg" value="">';
	}

	/* ---------------------------------------------------------------- Order */

	/**
	 * Store the CompanyData ID and registration number on the order. The
	 * checkout nonce was verified by WooCommerce before this fires.
	 */
	public static function save_order( WC_Order $order, array $data ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id  = sanitize_text_field( wp_unslash( $_POST['companydata_billing_id'] ?? '' ) );
		$reg = sanitize_text_field( wp_unslash( $_POST['companydata_billing_registration'] ?? '' ) );
		// phpcs:enable
		if ( '' === $reg && ! empty( $data['billing_company_registration'] ) ) {
			$reg = sanitize_text_field( (string) $data['billing_company_registration'] );
		}
		if ( '' !== $id ) {
			$order->update_meta_data( self::META_ID, $id );
		}
		if ( '' !== $reg ) {
			$order->update_meta_data( self::META_REG, $reg );
		}
	}

	/**
	 * Remember the company on the customer account as well, so the next
	 * order starts from it.
	 */
	public static function save_customer( \WC_Customer $customer, array $data ): void {
		// phpcs:disable WordPress.Security.NonceVerification.Missing
		$id  = sanitize_text_field( wp_unslash( $_POST['companydata_billing_id'] ?? '' ) );
		$reg = sanitize_text_field( wp_unslash( $_POST['companydata_billing_registration'] ?? '' ) );
		// phpcs:enable
		if ( '' === $reg && ! empty( $data['billing_company_registration'] ) ) {
			$reg = sanitize_text_field( (string) $data['billing_company_registration'] );
		}
		if ( '' !== $id ) {
			$customer->update_meta_data( 'companydata_id', $id );
		}
		if ( '' !== $reg ) {
			$customer->update_meta_data( 'companydata_registration', $reg );
		}
	}

	/* ---------------------------------------------------------------- Admin */

	public static function admin_order( WC_Order $order ): void {
		$id  = (string) $order->get_meta( self::META_ID );
		$reg = (string) $order->get_meta( self::META_REG );
		if ( '' === $id && '' === $reg ) {
			return;
		}
		echo '<p class="companydata-order-meta"><strong>' . esc_html__( 'Company register', 'companydata-company-lookup' ) . '</strong><br>';
		if ( '' !== $reg ) {
			echo esc_html__( 'Registration number:', 'companydata-company-lookup' ) . ' <code>' . esc_html( $reg ) . '</code><br>';
		}
		if ( '' !== $id ) {
			echo esc_html__( 'CompanyData ID:', 'companydata-company-lookup' ) . ' <code>' . esc_html( $id ) . '</code>';
		}
		echo '</p>';
	}

	public static function user_profile( \WP_User $user ): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$id  = (string) get_user_meta( $user->ID, 'companydata_id', true );
		$reg = (string) get_user_meta( $user->ID, 'companydata_registration', true );
		if ( '' === $id && '' === $reg ) {
			return;
		}
		?>
		<h2><?php esc_html_e( 'Company register', 'companydata-company-lookup' ); ?></h2>
		<table class="form-table" role="presentation">
			<tr><th><?php esc_html_e( 'Registration number', 'companydata-company-lookup' ); ?></th><td><code><?php echo esc_html( $reg ?: '-' ); ?></code></td></tr>
			<tr><th><?php esc_html_e( 'CompanyData ID', 'companydata-company-lookup' ); ?></th><td><code><?php echo esc_html( $id ?: '-' ); ?></code></td></tr>
		</table>
		<?php
	}
}
