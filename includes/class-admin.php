<?php
/**
 * Settings and usage screens under the WooCommerce menu.
 *
 * @package CompanyData_WC
 */

namespace CompanyData_WC;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Admin {

	const PAGE = 'companydata';

	public static function init(): void {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ), 60 );
		add_action( 'admin_init', array( __CLASS__, 'register_settings' ) );
		add_filter( 'option_page_capability_companydata', fn() => 'manage_woocommerce' );
		add_filter( 'plugin_action_links_' . plugin_basename( COMPANYDATA_WC_FILE ), array( __CLASS__, 'action_links' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'assets' ) );
		add_action( 'admin_post_companydata_test', array( __CLASS__, 'test_connection' ) );
		add_action( 'admin_notices', array( __CLASS__, 'connect_notice' ) );
	}

	public static function menu(): void {
		add_submenu_page( 'woocommerce', __( 'CompanyData', 'companydata-company-lookup' ), __( 'CompanyData', 'companydata-company-lookup' ), 'manage_woocommerce', self::PAGE, array( __CLASS__, 'render_page' ) );
	}

	public static function register_settings(): void {
		register_setting( 'companydata', Settings::OPTION, array(
			'type'              => 'array',
			'sanitize_callback' => array( 'CompanyData_WC\\Settings', 'sanitize' ),
		) );
	}

	public static function action_links( array $links ): array {
		array_unshift( $links, '<a href="' . esc_url( self::url() ) . '">' . esc_html__( 'Settings', 'companydata-company-lookup' ) . '</a>' );
		return $links;
	}

	public static function url( string $tab = '' ): string {
		return admin_url( 'admin.php?page=' . self::PAGE . ( $tab ? '&tab=' . $tab : '' ) );
	}

	public static function assets( string $hook ): void {
		if ( false !== strpos( $hook, self::PAGE ) ) {
			wp_enqueue_style( 'companydata-admin', COMPANYDATA_WC_URL . 'assets/css/admin.css', array(), COMPANYDATA_WC_VERSION );
		}
	}

	/**
	 * One notice on WooCommerce screens until a key is connected.
	 */
	public static function connect_notice(): void {
		$screen = get_current_screen();
		if ( Api_Client::is_connected() || ! current_user_can( 'manage_woocommerce' ) || ! $screen || false === strpos( (string) $screen->id, 'woocommerce' ) || false !== strpos( (string) $screen->id, self::PAGE ) ) {
			return;
		}
		echo '<div class="notice notice-info"><p>' . wp_kses_post( sprintf(
			/* translators: 1: settings URL, 2: signup URL */
			__( '<strong>CompanyData Company Lookup</strong> is active but has no API key yet. <a href="%1$s">Connect a key</a> or <a href="%2$s" target="_blank" rel="noopener">start a free trial</a>.', 'companydata-company-lookup' ),
			esc_url( self::url() ),
			esc_url( 'https://app.companydata.com/signup-api?source=wordpress-plugin' )
		) ) . '</p></div>';
	}

	/* ------------------------------------------------------------- Actions */

	public static function test_connection(): void {
		check_admin_referer( 'companydata_test' );
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'companydata-company-lookup' ) );
		}
		$res = Api_Client::test();
		set_transient( 'companydata_test_' . get_current_user_id(), is_wp_error( $res ) ? array( 'ok' => false, 'error' => $res->get_error_message(), 'code' => $res->get_error_code() ) : array( 'ok' => true ), MINUTE_IN_SECONDS );
		wp_safe_redirect( self::url() );
		exit;
	}

	/* --------------------------------------------------------------- Pages */

	public static function render_page(): void {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		?>
		<div class="wrap companydata-admin">
			<h1><?php esc_html_e( 'CompanyData Company Lookup', 'companydata-company-lookup' ); ?></h1>
			<nav class="nav-tab-wrapper">
				<a class="nav-tab <?php echo 'settings' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url() ); ?>"><?php esc_html_e( 'Settings', 'companydata-company-lookup' ); ?></a>
				<a class="nav-tab <?php echo 'usage' === $tab ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( self::url( 'usage' ) ); ?>"><?php esc_html_e( 'Usage', 'companydata-company-lookup' ); ?></a>
			</nav>
			<?php 'usage' === $tab ? self::render_usage() : self::render_settings(); ?>
		</div>
		<?php
	}

	private static function render_settings(): void {
		$s         = Settings::all();
		$name      = Settings::OPTION;
		$connected = Api_Client::is_connected();
		$test      = get_transient( 'companydata_test_' . get_current_user_id() );
		delete_transient( 'companydata_test_' . get_current_user_id() );
		$company_hidden = 'hidden' === get_option( 'woocommerce_checkout_company_field', 'optional' );
		?>
		<?php if ( is_array( $test ) ) : ?>
			<div class="notice <?php echo $test['ok'] ? 'notice-success' : 'notice-error'; ?> inline"><p>
			<?php
			if ( $test['ok'] ) {
				esc_html_e( 'Connected. The API answered a test search.', 'companydata-company-lookup' );
			} else {
				echo esc_html__( 'Test failed:', 'companydata-company-lookup' ) . ' ' . esc_html( $test['error'] );
				if ( 'companydata_bad_key' === $test['code'] ) {
					echo ' ' . esc_html__( 'Check the key in your CompanyData dashboard.', 'companydata-company-lookup' );
				}
			}
			?>
			</p></div>
		<?php endif; ?>
		<?php if ( $company_hidden ) : ?>
			<div class="notice notice-warning inline"><p><?php
				/* translators: %s: WooCommerce settings URL */
				echo wp_kses_post( sprintf( __( 'The Company field is hidden on your checkout, so there is nothing to look up. Set it to Optional or Required under <a href="%s">WooCommerce &gt; Settings &gt; Advanced &gt; Checkout</a>.', 'companydata-company-lookup' ), esc_url( admin_url( 'admin.php?page=wc-settings&tab=advanced' ) ) ) );
			?></p></div>
		<?php endif; ?>

		<form method="post" action="options.php">
			<?php settings_fields( 'companydata' ); ?>

			<h2><?php esc_html_e( 'API connection', 'companydata-company-lookup' ); ?></h2>
			<div class="companydata-card">
				<?php if ( $connected ) : ?>
					<p class="companydata-status companydata-status-ok"><span class="dashicons dashicons-yes-alt"></span> <?php esc_html_e( 'Key saved', 'companydata-company-lookup' ); ?> <code><?php echo esc_html( Settings::masked_key() ); ?></code>
						<?php if ( Settings::has_constant_key() ) : ?><em><?php esc_html_e( '(set via COMPANYDATA_API_KEY in wp-config.php)', 'companydata-company-lookup' ); ?></em><?php endif; ?>
					</p>
				<?php else : ?>
					<p class="companydata-status"><span class="dashicons dashicons-marker"></span> <?php esc_html_e( 'Not connected. Company lookup stays off until a key is saved; checkout works as normal.', 'companydata-company-lookup' ); ?></p>
					<p><a class="button button-primary" target="_blank" rel="noopener" href="https://app.companydata.com/signup-api?source=wordpress-plugin"><?php esc_html_e( 'Get a free CompanyData API key', 'companydata-company-lookup' ); ?></a>
					<span class="description"><?php esc_html_e( 'The trial needs no credit card. Each completed lookup at checkout uses one search from your plan.', 'companydata-company-lookup' ); ?></span></p>
				<?php endif; ?>
				<?php if ( ! Settings::has_constant_key() ) : ?>
				<table class="form-table" role="presentation">
					<tr><th scope="row"><label for="companydata-api-key"><?php esc_html_e( 'API key', 'companydata-company-lookup' ); ?></label></th>
						<td><input type="password" id="companydata-api-key" name="<?php echo esc_attr( $name ); ?>[api_key]" class="regular-text" autocomplete="off" placeholder="<?php echo $connected ? esc_attr__( 'Leave empty to keep the current key', 'companydata-company-lookup' ) : ''; ?>">
						<?php if ( $connected ) : ?><label class="companydata-inline"><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[clear_api_key]" value="1"> <?php esc_html_e( 'Disconnect', 'companydata-company-lookup' ); ?></label><?php endif; ?>
						<p class="description"><?php esc_html_e( 'Stored in this site\'s database, encrypted with your site\'s salts, and only ever sent from your server to app.companydata.com.', 'companydata-company-lookup' ); ?></p></td></tr>
				</table>
				<?php endif; ?>
			</div>

			<h2><?php esc_html_e( 'Lookup', 'companydata-company-lookup' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Countries', 'companydata-company-lookup' ); ?></th>
					<td><fieldset>
						<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[countries_mode]" value="shop" <?php checked( $s['countries_mode'], 'shop' ); ?>> <?php esc_html_e( 'The countries this shop sells to (WooCommerce > Settings > General)', 'companydata-company-lookup' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[countries_mode]" value="all" <?php checked( $s['countries_mode'], 'all' ); ?>> <?php esc_html_e( 'Every country', 'companydata-company-lookup' ); ?></label><br>
						<label><input type="radio" name="<?php echo esc_attr( $name ); ?>[countries_mode]" value="list" <?php checked( $s['countries_mode'], 'list' ); ?>> <?php esc_html_e( 'Only these:', 'companydata-company-lookup' ); ?></label>
						<input type="text" name="<?php echo esc_attr( $name ); ?>[countries_list]" value="<?php echo esc_attr( implode( ', ', (array) $s['countries_list'] ) ); ?>" class="regular-text" placeholder="NL, BE, DE">
						<p class="description"><?php esc_html_e( 'The lookup searches the country the customer selected on the checkout. Two-letter codes.', 'companydata-company-lookup' ); ?></p>
					</fieldset></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Behaviour', 'companydata-company-lookup' ); ?></th>
					<td class="companydata-limits">
						<label><?php esc_html_e( 'Start after', 'companydata-company-lookup' ); ?> <input type="number" min="2" max="6" name="<?php echo esc_attr( $name ); ?>[min_chars]" value="<?php echo esc_attr( $s['min_chars'] ); ?>" class="small-text"> <?php esc_html_e( 'characters', 'companydata-company-lookup' ); ?></label>
						<label><?php esc_html_e( 'Wait', 'companydata-company-lookup' ); ?> <input type="number" min="150" max="1500" step="50" name="<?php echo esc_attr( $name ); ?>[debounce_ms]" value="<?php echo esc_attr( $s['debounce_ms'] ); ?>" class="small-text"> <?php esc_html_e( 'ms after typing stops', 'companydata-company-lookup' ); ?></label>
						<label><?php esc_html_e( 'Show', 'companydata-company-lookup' ); ?> <input type="number" min="3" max="20" name="<?php echo esc_attr( $name ); ?>[results]" value="<?php echo esc_attr( $s['results'] ); ?>" class="small-text"> <?php esc_html_e( 'suggestions', 'companydata-company-lookup' ); ?></label>
						<label><?php esc_html_e( 'Cache results for', 'companydata-company-lookup' ); ?> <input type="number" min="0" max="365" name="<?php echo esc_attr( $name ); ?>[cache_days]" value="<?php echo esc_attr( $s['cache_days'] ); ?>" class="small-text"> <?php esc_html_e( 'days', 'companydata-company-lookup' ); ?></label>
						<p class="description"><?php esc_html_e( 'Each request to the API uses one search from your plan. A longer wait and a higher character minimum mean fewer searches per customer; cached text costs nothing.', 'companydata-company-lookup' ); ?></p>
					</td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Limits', 'companydata-company-lookup' ); ?></th>
					<td class="companydata-limits">
						<label><?php esc_html_e( 'Lookups per visitor per day', 'companydata-company-lookup' ); ?> <input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[limit_ip_day]" value="<?php echo esc_attr( $s['limit_ip_day'] ); ?>" class="small-text"></label>
						<label><?php esc_html_e( 'Searches per day, whole site', 'companydata-company-lookup' ); ?> <input type="number" min="0" name="<?php echo esc_attr( $name ); ?>[daily_cap]" value="<?php echo esc_attr( $s['daily_cap'] ); ?>" class="small-text"></label>
						<p class="description"><?php esc_html_e( '0 = no limit. When a limit is reached the lookup switches off quietly and customers type their address as usual.', 'companydata-company-lookup' ); ?></p>
					</td></tr>
			</table>

			<h2><?php esc_html_e( 'Checkout', 'companydata-company-lookup' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Fill in', 'companydata-company-lookup' ); ?></th>
					<td><fieldset class="companydata-fields">
					<?php
					$labels = array(
						'address_1' => __( 'Street address', 'companydata-company-lookup' ),
						'address_2' => __( 'Address line 2', 'companydata-company-lookup' ),
						'postcode'  => __( 'Postcode', 'companydata-company-lookup' ),
						'city'      => __( 'City', 'companydata-company-lookup' ),
						'state'     => __( 'State / province', 'companydata-company-lookup' ),
						'country'   => __( 'Country (when the match is registered elsewhere)', 'companydata-company-lookup' ),
					);
					foreach ( $labels as $key => $label ) :
						?>
						<label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[fill_fields][]" value="<?php echo esc_attr( $key ); ?>" <?php checked( in_array( $key, (array) $s['fill_fields'], true ) ); ?>> <?php echo esc_html( $label ); ?></label>
					<?php endforeach; ?>
					<p class="description"><?php esc_html_e( 'The company name always fills in. Phone and email are not available from the lookup.', 'companydata-company-lookup' ); ?></p>
					</fieldset></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Shipping address', 'companydata-company-lookup' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[shipping]" value="1" <?php checked( $s['shipping'] ); ?>> <?php esc_html_e( 'Also offer the lookup on the shipping Company field', 'companydata-company-lookup' ); ?></label>
					<p class="description"><?php esc_html_e( 'Classic checkout only. The block checkout asks for the shipping address first and always offers the lookup there.', 'companydata-company-lookup' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Field order', 'companydata-company-lookup' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[country_first]" value="1" <?php checked( $s['country_first'] ); ?>> <?php esc_html_e( 'Ask for the country before the company name', 'companydata-company-lookup' ); ?></label>
					<p class="description"><?php esc_html_e( 'The lookup searches the selected country, so customers get the right suggestions from the first letter.', 'companydata-company-lookup' ); ?></p></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'Registration number field', 'companydata-company-lookup' ); ?></th>
					<td><select name="<?php echo esc_attr( $name ); ?>[registration_field]">
						<option value="hidden" <?php selected( $s['registration_field'], 'hidden' ); ?>><?php esc_html_e( 'Not shown - saved on the order when a company is picked', 'companydata-company-lookup' ); ?></option>
						<option value="optional" <?php selected( $s['registration_field'], 'optional' ); ?>><?php esc_html_e( 'Shown, optional', 'companydata-company-lookup' ); ?></option>
						<option value="required" <?php selected( $s['registration_field'], 'required' ); ?>><?php esc_html_e( 'Shown, required', 'companydata-company-lookup' ); ?></option>
					</select>
					<p class="description"><?php esc_html_e( 'A visible field lets customers type a KvK or Companies House number and get the company filled in from it. Either way the number is shown on the order in WooCommerce.', 'companydata-company-lookup' ); ?></p></td></tr>
			</table>

			<h2><?php esc_html_e( 'Credit and data', 'companydata-company-lookup' ); ?></h2>
			<table class="form-table" role="presentation">
				<tr><th scope="row"><?php esc_html_e( 'Credit link', 'companydata-company-lookup' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[credit_link]" value="1" <?php checked( $s['credit_link'] ); ?>> <?php esc_html_e( 'Show a small "Company lookup by CompanyData" line under the Company field. Optional.', 'companydata-company-lookup' ); ?></label></td></tr>
				<tr><th scope="row"><?php esc_html_e( 'On uninstall', 'companydata-company-lookup' ); ?></th>
					<td><label><input type="checkbox" name="<?php echo esc_attr( $name ); ?>[delete_on_uninstall]" value="1" <?php checked( $s['delete_on_uninstall'] ); ?>> <?php esc_html_e( 'Also delete the registration numbers and IDs saved on orders and customers.', 'companydata-company-lookup' ); ?></label></td></tr>
			</table>

			<?php submit_button(); ?>
		</form>

		<?php if ( $connected ) : ?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>" class="companydata-test">
			<input type="hidden" name="action" value="companydata_test">
			<?php wp_nonce_field( 'companydata_test' ); ?>
			<?php submit_button( __( 'Test connection', 'companydata-company-lookup' ), 'secondary', 'submit', false ); ?>
			<span class="description"><?php esc_html_e( 'Runs one small search against the API.', 'companydata-company-lookup' ); ?></span>
		</form>
		<?php endif; ?>
		<?php
	}

	private static function render_usage(): void {
		$counters = Api_Client::counters();
		krsort( $counters );
		$counters = array_slice( $counters, 0, 30, true );
		?>
		<div class="companydata-card">
			<h2><?php esc_html_e( 'API searches sent by this site', 'companydata-company-lookup' ); ?></h2>
			<p class="description"><?php esc_html_e( 'Counted when a request actually reaches the API. Cached lookups are free and not counted. Your plan\'s allowance and remaining balance are in the CompanyData dashboard.', 'companydata-company-lookup' ); ?></p>
			<?php if ( ! $counters ) : ?>
				<p><?php esc_html_e( 'No lookups yet.', 'companydata-company-lookup' ); ?></p>
			<?php else : ?>
			<table class="widefat striped companydata-usage"><thead><tr>
				<th><?php esc_html_e( 'Day', 'companydata-company-lookup' ); ?></th><th><?php esc_html_e( 'Name searches', 'companydata-company-lookup' ); ?></th><th><?php esc_html_e( 'Number lookups', 'companydata-company-lookup' ); ?></th>
			</tr></thead><tbody>
			<?php foreach ( $counters as $day => $c ) : ?>
				<tr><td><?php echo esc_html( $day ); ?></td><td><?php echo (int) ( $c['search'] ?? 0 ); ?></td><td><?php echo (int) ( $c['lookup'] ?? 0 ); ?></td></tr>
			<?php endforeach; ?>
			</tbody></table>
			<?php endif; ?>
			<p><a href="https://app.companydata.com/" target="_blank" rel="noopener"><?php esc_html_e( 'Open the CompanyData dashboard', 'companydata-company-lookup' ); ?></a></p>
		</div>
		<?php
	}
}
