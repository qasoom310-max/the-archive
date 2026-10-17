<?php
/**
 * Settings → Border offers.
 */

defined( 'ABSPATH' ) || exit;

final class WBO_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wbo_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_wbo_test', array( __CLASS__, 'test' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WBO_FILE ), array( __CLASS__, 'action_links' ) );
	}

	public static function menu() {
		add_options_page( 'Border offers', 'Border offers', 'manage_options', 'wanaan-border-offers', array( __CLASS__, 'page' ) );
	}

	public static function action_links( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=wanaan-border-offers' ) ) . '">Settings</a>' );

		return $links;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wbo_save' );

		update_option(
			WBO_Client::OPTION,
			array(
				'erp_url'   => esc_url_raw( trim( (string) wp_unslash( $_POST['erp_url'] ?? '' ) ) ),
				'workspace' => max( 1, (int) ( $_POST['workspace'] ?? 7 ) ),
				'whatsapp'  => preg_replace( '/\D+/', '', (string) wp_unslash( $_POST['whatsapp'] ?? '' ) ),
			)
		);

		wp_safe_redirect( admin_url( 'options-general.php?page=wanaan-border-offers&saved=1' ) );
		exit;
	}

	public static function test() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wbo_test' );

		$search = WBO_Query::normalise( 'SA', 'BH', WBO_Client::today(), WBO_Client::today() );
		$answer = is_array( $search ) ? WBO_Client::search( $search ) : null;

		wp_safe_redirect( admin_url( 'options-general.php?page=wanaan-border-offers&tested=' . ( null === $answer ? 'fail' : 'ok' ) ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s      = WBO_Client::settings();
		$error  = (string) get_option( WBO_Client::LAST_ERROR, '' );
		$secret = defined( 'WANAAN_PORTAL_SECRET' ) && '' !== (string) WANAAN_PORTAL_SECRET;
		?>
		<div class="wrap">
			<h1>Border offers</h1>
			<p>A search box for rides between Bahrain and Saudi Arabia. When one of our cars has to drive that way empty, the hours it can take a passenger are shown with the discount set in the ERP (Fares → Settings).</p>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Saved.</p></div>
			<?php elseif ( isset( $_GET['tested'] ) && 'ok' === $_GET['tested'] ) : ?>
				<div class="notice notice-success is-dismissible"><p>The ERP answered. The search box is ready.</p></div>
			<?php elseif ( isset( $_GET['tested'] ) ) : ?>
				<div class="notice notice-error is-dismissible"><p>The ERP did not answer: <?php echo esc_html( '' !== $error ? $error : 'unknown reason' ); ?></p></div>
			<?php endif; ?>

			<?php if ( ! $secret ) : ?>
				<div class="notice notice-warning"><p><code>WANAAN_PORTAL_SECRET</code> is not defined in wp-config.php, so the ERP cannot be asked.</p></div>
			<?php endif; ?>

			<h2>Put it on a page</h2>
			<p>Add this shortcode (an Elementor "Shortcode" widget works too):</p>
			<p><code>[wanaan_border_offers]</code></p>
			<p>Options: <code>lang="ar"</code> or <code>lang="en"</code> (otherwise it follows the site language), <code>theme="dark"</code>, <code>from="BH" to="SA"</code>, <code>trip="oneway"</code>.</p>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wbo_save">
				<?php wp_nonce_field( 'wbo_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row"><label for="wbo_erp_url">ERP address</label></th>
						<td><input name="erp_url" id="wbo_erp_url" type="url" class="regular-text" value="<?php echo esc_attr( $s['erp_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wbo_workspace">ERP database number</label></th>
						<td><input name="workspace" id="wbo_workspace" type="number" min="1" class="small-text" value="<?php echo esc_attr( (string) $s['workspace'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wbo_whatsapp">WhatsApp if the ERP is down</label></th>
						<td>
							<input name="whatsapp" id="wbo_whatsapp" type="text" inputmode="numeric" class="regular-text" value="<?php echo esc_attr( $s['whatsapp'] ); ?>" placeholder="97339991869">
							<p class="description">Digits with country code. Leave blank to use the site's WhatsApp number. Normally the number set in the ERP is used.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wbo_test">
				<?php wp_nonce_field( 'wbo_test' ); ?>
				<?php submit_button( 'Ask the ERP now', 'secondary' ); ?>
			</form>
		</div>
		<?php
	}
}
