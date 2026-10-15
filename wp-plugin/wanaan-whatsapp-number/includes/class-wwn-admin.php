<?php
/**
 * Settings → WhatsApp number.
 */

defined( 'ABSPATH' ) || exit;

final class WWN_Admin {

	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'menu' ) );
		add_action( 'admin_post_wwn_save', array( __CLASS__, 'save' ) );
		add_action( 'admin_post_wwn_refresh', array( __CLASS__, 'refreshNow' ) );
		add_filter( 'plugin_action_links_' . plugin_basename( WWN_FILE ), array( __CLASS__, 'actionLinks' ) );
	}

	public static function menu() {
		add_options_page( 'WhatsApp number', 'WhatsApp number', 'manage_options', 'wanaan-whatsapp-number', array( __CLASS__, 'page' ) );
	}

	public static function actionLinks( $links ) {
		array_unshift( $links, '<a href="' . esc_url( admin_url( 'options-general.php?page=wanaan-whatsapp-number' ) ) . '">Settings</a>' );

		return $links;
	}

	public static function save() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wwn_save' );

		$old = WWN_Number::settings();
		$new = array(
			'enabled'   => ! empty( $_POST['enabled'] ),
			'source'    => ( isset( $_POST['source'] ) && 'typed' === $_POST['source'] ) ? 'typed' : 'erp',
			'erp_url'   => esc_url_raw( trim( (string) wp_unslash( $_POST['erp_url'] ?? '' ) ) ),
			'workspace' => max( 1, (int) ( $_POST['workspace'] ?? 7 ) ),
			'number'    => WWN_Rewriter::digits( wp_unslash( $_POST['number'] ?? '' ) ),
			'others'    => sanitize_textarea_field( wp_unslash( $_POST['others'] ?? '' ) ),
		);

		update_option( WWN_Number::OPTION, $new );
		WWN_Number::refresh();

		if ( $new !== $old ) {
			WWN_Number::purgeCaches();
		}

		wp_safe_redirect( admin_url( 'options-general.php?page=wanaan-whatsapp-number&saved=1' ) );
		exit;
	}

	public static function refreshNow() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( 'Not allowed.' );
		}
		check_admin_referer( 'wwn_refresh' );

		WWN_Number::refresh();
		WWN_Number::current();
		WWN_Number::purgeCaches();

		wp_safe_redirect( admin_url( 'options-general.php?page=wanaan-whatsapp-number&refreshed=1' ) );
		exit;
	}

	public static function page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$s       = WWN_Number::settings();
		$current = WWN_Number::current();
		$origin  = WWN_Number::origin();
		$error   = (string) get_option( WWN_Number::LAST_ERROR, '' );
		$checked = (int) get_option( WWN_Number::LAST_CHECKED, 0 );
		?>
		<div class="wrap">
			<h1>WhatsApp number</h1>
			<p>Every WhatsApp button, icon and link on the site, and the numbers you list below, show this one number — Elementor pages included. Your pages are not edited; the number is swapped as each page is sent.</p>

			<?php if ( isset( $_GET['saved'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Saved. The site now shows the number below.</p></div>
			<?php elseif ( isset( $_GET['refreshed'] ) ) : ?>
				<div class="notice notice-success is-dismissible"><p>Asked the ERP again.</p></div>
			<?php endif; ?>

			<div style="background:#fff;border:1px solid #c3c4c7;border-left:4px solid #25d366;padding:14px 18px;margin:16px 0;max-width:720px;">
				<p style="margin:0;font-size:13px;color:#50575e;">The site is showing</p>
				<p style="margin:4px 0 6px;font-size:22px;font-weight:600;">
					<?php echo '' !== $current ? esc_html( '+' . $current ) : '— none —'; ?>
				</p>
				<p style="margin:0;color:#50575e;">
					<?php
					if ( 'erp' === $origin ) {
						echo 'From the ERP (Fares → Settings → WhatsApp).';
					} elseif ( 'typed-fallback' === $origin ) {
						echo 'The ERP has not answered yet, so the number typed below is used.';
					} else {
						echo 'Typed on this page.';
					}
					if ( $checked > 0 && 'typed' !== $origin ) {
						echo ' Last asked ' . esc_html( human_time_diff( $checked ) ) . ' ago.';
					}
					?>
				</p>
				<?php if ( '' !== $error && 'erp' === $s['source'] ) : ?>
					<p style="margin:8px 0 0;color:#b32d2e;"><?php echo esc_html( $error ); ?></p>
				<?php endif; ?>
				<?php if ( ! $s['enabled'] ) : ?>
					<p style="margin:8px 0 0;color:#b32d2e;">Switched off — pages show whatever was typed into them.</p>
				<?php endif; ?>
			</div>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wwn_save">
				<?php wp_nonce_field( 'wwn_save' ); ?>
				<table class="form-table" role="presentation">
					<tr>
						<th scope="row">On</th>
						<td><label><input type="checkbox" name="enabled" value="1" <?php checked( $s['enabled'] ); ?>> Replace WhatsApp numbers across the site</label></td>
					</tr>
					<tr>
						<th scope="row">Take the number from</th>
						<td>
							<label><input type="radio" name="source" value="erp" <?php checked( 'erp', $s['source'] ); ?>> The ERP <span class="description">— change it once on the ERP's Fares page (Settings → WhatsApp) and the whole site follows within 10 minutes</span></label><br>
							<label><input type="radio" name="source" value="typed" <?php checked( 'typed', $s['source'] ); ?>> The number below</label>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wwn-number">WhatsApp number</label></th>
						<td>
							<input id="wwn-number" type="text" name="number" class="regular-text" dir="ltr" value="<?php echo esc_attr( $s['number'] ); ?>" placeholder="97339991869">
							<p class="description">With the country code, e.g. 97339991869. Used when "the number below" is picked, and as the fall-back if the ERP can't be reached.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wwn-others">Numbers on your pages to show as the WhatsApp number</label></th>
						<td>
							<textarea id="wwn-others" name="others" rows="4" class="large-text code" dir="ltr"><?php echo esc_textarea( $s['others'] ); ?></textarea>
							<p class="description">One per line. Wherever one of these is written on a page — as text or as a call link — the visitor sees the WhatsApp number instead, in the same style ("+973 3999 1869" or "+97339991869"). WhatsApp buttons and links are always changed and don't need listing.<br><strong>Don't list the hotline</strong> (+973 1747 4949) unless it should change with WhatsApp.</p>
						</td>
					</tr>
					<tr>
						<th scope="row"><label for="wwn-erp">ERP address</label></th>
						<td><input id="wwn-erp" type="url" name="erp_url" class="regular-text" dir="ltr" value="<?php echo esc_attr( $s['erp_url'] ); ?>"></td>
					</tr>
					<tr>
						<th scope="row"><label for="wwn-ws">ERP database number</label></th>
						<td>
							<input id="wwn-ws" type="number" min="1" name="workspace" class="small-text" value="<?php echo esc_attr( (string) $s['workspace'] ); ?>">
							<p class="description">Wanaan is 7. Signed with the WANAAN_PORTAL_SECRET already in wp-config.php.</p>
						</td>
					</tr>
				</table>
				<?php submit_button( 'Save' ); ?>
			</form>

			<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
				<input type="hidden" name="action" value="wwn_refresh">
				<?php wp_nonce_field( 'wwn_refresh' ); ?>
				<?php submit_button( 'Ask the ERP now', 'secondary', 'submit', false ); ?>
				<span class="description" style="margin-inline-start:8px;">Use this right after changing the number in the ERP.</span>
			</form>
		</div>
		<?php
	}
}
