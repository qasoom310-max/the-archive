<?php
/**
 * The wp-admin surface: a settings form for the ERP callback URL and a
 * read-only list of received service orders. Both are capability- and
 * nonce-guarded.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Admin {

	const CAPABILITY   = 'manage_woocommerce';
	const MENU_SLUG    = 'wanaan-service-orders';
	const NONCE_ACTION = 'wanaan_so_settings';

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'add_menu' ) );
		add_action( 'admin_post_wanaan_so_save_settings', array( __CLASS__, 'save_settings' ) );
	}

	public static function add_menu() {
		add_menu_page(
			__( 'Service Orders', 'wanaan-service-order' ),
			__( 'Service Orders', 'wanaan-service-order' ),
			self::CAPABILITY,
			self::MENU_SLUG,
			array( __CLASS__, 'render' ),
			'dashicons-tickets-alt',
			56
		);
	}

	public static function save_settings() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'wanaan-service-order' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::NONCE_ACTION );

		$url = isset( $_POST['callback_url'] ) ? esc_url_raw( wp_unslash( $_POST['callback_url'] ) ) : '';
		update_option( WSO_Callback::OPTION_CALLBACK_URL, $url );

		wp_safe_redirect( add_query_arg( array( 'page' => self::MENU_SLUG, 'saved' => '1' ), admin_url( 'admin.php' ) ) );
		exit;
	}

	public static function render() {
		if ( ! current_user_can( self::CAPABILITY ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'wanaan-service-order' ), '', array( 'response' => 403 ) );
		}

		$callback_url = (string) get_option( WSO_Callback::OPTION_CALLBACK_URL, '' );
		$secret_set   = ( '' !== WSO_Signature::secret() );
		$rows         = WSO_Repository::recent( 100 );
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'Wanaan Service Orders', 'wanaan-service-order' ); ?></h1>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p><?php esc_html_e( 'Settings saved.', 'wanaan-service-order' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $secret_set ) : ?>
				<div class="notice notice-error">
					<p><strong><?php esc_html_e( 'Shared secret is not set.', 'wanaan-service-order' ); ?></strong>
					<?php esc_html_e( 'Add this line to wp-config.php (the same value the ERP holds):', 'wanaan-service-order' ); ?>
					<code>define( 'WANAAN_PORTAL_SECRET', 'your-shared-secret' );</code></p>
				</div>
			<?php endif; ?>

			<h2><?php esc_html_e( 'Settings', 'wanaan-service-order' ); ?></h2>
			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wanaan_so_save_settings">
				<?php wp_nonce_field( self::NONCE_ACTION ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="callback_url"><?php esc_html_e( 'ERP callback URL', 'wanaan-service-order' ); ?></label></th>
						<td>
							<input name="callback_url" id="callback_url" type="url" class="regular-text"
								value="<?php echo esc_attr( $callback_url ); ?>"
								placeholder="https://erp.wanaan-bh.com">
							<p class="description"><?php esc_html_e( 'The ERP base URL (or the full /limousine/payment-callback endpoint). Used to report a paid order back to the ERP.', 'wanaan-service-order' ); ?></p>
						</td>
					</tr>
					<tr>
						<th scope="row"><?php esc_html_e( 'REST endpoint (give this to the ERP)', 'wanaan-service-order' ); ?></th>
						<td><code><?php echo esc_html( rest_url( 'wanaan/v1/booking' ) ); ?></code></td>
					</tr>
				</table>
				<?php submit_button( __( 'Save settings', 'wanaan-service-order' ) ); ?>
			</form>

			<h2><?php esc_html_e( 'Recent service orders', 'wanaan-service-order' ); ?></h2>
			<table class="widefat striped">
				<thead>
					<tr>
						<th><?php esc_html_e( 'Confirmation', 'wanaan-service-order' ); ?></th>
						<th><?php esc_html_e( 'Booking', 'wanaan-service-order' ); ?></th>
						<th><?php esc_html_e( 'Customer', 'wanaan-service-order' ); ?></th>
						<th><?php esc_html_e( 'Amount', 'wanaan-service-order' ); ?></th>
						<th><?php esc_html_e( 'Status', 'wanaan-service-order' ); ?></th>
						<th><?php esc_html_e( 'WC order', 'wanaan-service-order' ); ?></th>
						<th><?php esc_html_e( 'Consent', 'wanaan-service-order' ); ?></th>
						<th><?php esc_html_e( 'Link', 'wanaan-service-order' ); ?></th>
					</tr>
				</thead>
				<tbody>
					<?php if ( empty( $rows ) ) : ?>
						<tr><td colspan="8"><?php esc_html_e( 'No service orders received yet.', 'wanaan-service-order' ); ?></td></tr>
					<?php else : ?>
						<?php foreach ( $rows as $row ) : ?>
							<tr>
								<td><?php echo esc_html( $row->confirmation_no ); ?></td>
								<td><?php echo esc_html( $row->booking_no ); ?></td>
								<td><?php echo esc_html( $row->customer_name ); ?></td>
								<td><?php echo esc_html( number_format( (float) $row->amount, 3 ) . ' ' . $row->currency ); ?></td>
								<td>
									<?php if ( 'paid' === $row->status ) : ?>
										<span style="color:#137333;font-weight:600;">&#10003; <?php esc_html_e( 'Paid', 'wanaan-service-order' ); ?></span>
									<?php else : ?>
										<span style="color:#8a6d3b;"><?php esc_html_e( 'Unpaid', 'wanaan-service-order' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( $row->woo_order_id ) : ?>
										<a href="<?php echo esc_url( admin_url( 'post.php?post=' . (int) $row->woo_order_id . '&action=edit' ) ); ?>">#<?php echo (int) $row->woo_order_id; ?></a>
									<?php else : ?>&mdash;<?php endif; ?>
								</td>
								<td><?php echo $row->consent_at ? esc_html( $row->consent_at ) . ' UTC' : '&mdash;'; ?></td>
								<td><a href="<?php echo esc_url( WSO_Page::url( $row->token ) ); ?>" target="_blank" rel="noopener"><?php esc_html_e( 'open', 'wanaan-service-order' ); ?></a></td>
							</tr>
						<?php endforeach; ?>
					<?php endif; ?>
				</tbody>
			</table>
		</div>
		<?php
	}
}
