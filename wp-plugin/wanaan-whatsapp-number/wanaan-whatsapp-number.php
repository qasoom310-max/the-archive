<?php
/**
 * Plugin Name:       Wanaan WhatsApp Number
 * Plugin URI:        https://www.wanaan-bh.com
 * Description:       One WhatsApp number for the whole site. Every WhatsApp button, icon and link — Elementor included — and any number you list are shown as the number set in the Wanaan ERP (or typed in Settings → WhatsApp number). Pages are not edited; the number is swapped as each page is sent.
 * Version:           1.0.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Wanaan
 * License:           GPL-2.0-or-later
 * Text Domain:       wanaan-whatsapp-number
 */

defined( 'ABSPATH' ) || exit;

define( 'WWN_VERSION', '1.0.0' );
define( 'WWN_FILE', __FILE__ );
define( 'WWN_DIR', plugin_dir_path( __FILE__ ) );

require_once WWN_DIR . 'includes/class-wwn-rewriter.php';
require_once WWN_DIR . 'includes/class-wwn-number.php';
require_once WWN_DIR . 'includes/class-wwn-admin.php';

add_action( 'plugins_loaded', 'wwn_boot' );

function wwn_boot() {
	if ( is_admin() ) {
		WWN_Admin::init();

		return;
	}

	// Early, so the swap happens before a page cache stores the page.
	add_action( 'template_redirect', 'wwn_start_buffer', 0 );
}

/**
 * Catch the whole front-end page and swap the numbers on its way out. Skipped
 * for the Elementor editor's preview (the editor must see what is really
 * saved), the customizer, AJAX, REST, cron and feeds.
 */
function wwn_start_buffer() {
	if ( wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) || is_feed() || is_customize_preview() ) {
		return;
	}
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended
	if ( isset( $_GET['elementor-preview'] ) || isset( $_GET['elementor_library'] ) ) {
		return;
	}

	$settings = WWN_Number::settings();
	if ( empty( $settings['enabled'] ) ) {
		return;
	}

	$number = WWN_Number::current();
	if ( strlen( $number ) < 8 ) {
		return;
	}

	$others = WWN_Number::others();

	ob_start(
		static function ( $html ) use ( $number, $others ) {
			return WWN_Rewriter::rewrite( (string) $html, $number, $others );
		}
	);
}
