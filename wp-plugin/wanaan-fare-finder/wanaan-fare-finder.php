<?php
/**
 * Plugin Name:       Wanaan Fare Finder
 * Plugin URI:        https://www.wanaan-bh.com/
 * Description:       Shows an instant fare for every Wanaan transport service. Prices come from the Wanaan ERP, with a local copy so the website never waits on it.
 * Version:           2.9.1
 * Requires at least: 5.8
 * Requires PHP:      7.2
 * Author:            Wanaan
 * License:           GPL-2.0-or-later
 * Text Domain:       wanaan-fare-finder
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * IMPORTANT: bump this on EVERY asset change. WordPress enqueues the CSS and JS
 * as ?ver=WNF_VERSION, so leaving it unchanged means browsers and any CDN keep
 * serving the previous file even though the new one is on the server. Cost us a
 * wasted re-upload on 9 Sep 2026.
 */
define( 'WNF_VERSION', '2.9.1' );
define( 'WNF_FILE', __FILE__ );
define( 'WNF_DIR', plugin_dir_path( __FILE__ ) );
define( 'WNF_URL', plugin_dir_url( __FILE__ ) );

/* Option / transient keys — all in one place so nothing is spelled twice. */
define( 'WNF_OPT_SETTINGS', 'wnf_settings' );      // source switch, whatsapp number, local fares
define( 'WNF_OPT_LAST_GOOD', 'wnf_erp_last_good' ); // last valid ERP payload — never expires
define( 'WNF_OPT_STATUS', 'wnf_erp_status' );       // last sync result, for the admin screen
define( 'WNF_TR_CACHE', 'wnf_erp_cache' );          // short-lived fresh copy
define( 'WNF_TR_PRODUCTS', 'wnf_products' );        // resolved WooCommerce product URLs + images
define( 'WNF_CRON_HOOK', 'wnf_pricing_refresh' );

require_once WNF_DIR . 'includes/wnf-defaults.php';
require_once WNF_DIR . 'includes/class-wnf-signature.php';
require_once WNF_DIR . 'includes/class-wnf-pricing.php';
require_once WNF_DIR . 'includes/class-wnf-shortcode.php';
require_once WNF_DIR . 'includes/class-wnf-rest.php';
require_once WNF_DIR . 'includes/class-wnf-cart.php';

if ( is_admin() ) {
	require_once WNF_DIR . 'includes/class-wnf-admin.php';
	WNF_Admin::init();
}

WNF_Shortcode::init();
WNF_Rest::init();

/*
 * One-time repair of the ERP endpoint.
 *
 * Earlier versions shipped https://erp.wanaan-bh.com/api/v1/pricing, which was
 * a guess and turned out to 404 — the ERP is multi-tenant and carries the
 * workspace as a path segment. A site that saved the old URL has it stored in
 * the database, where the corrected default cannot reach it, so it would go on
 * asking for a page that does not exist. Rewrite it once, and only if it is
 * still exactly the wrong value: a URL Qassim or Mohammed has since edited by
 * hand is left alone.
 */
add_action(
	'admin_init',
	function () {
		$saved = get_option( WNF_OPT_SETTINGS, array() );
		if ( ! is_array( $saved ) || empty( $saved['erp_url'] ) ) {
			return;
		}
		$old = 'https://erp.wanaan-bh.com/api/v1/pricing';
		if ( untrailingslashit( $saved['erp_url'] ) !== $old ) {
			return;
		}
		$saved['erp_url'] = 'https://erp.wanaan-bh.com/api/v1/workspaces/7/pricing';
		update_option( WNF_OPT_SETTINGS, $saved );
	}
);

/*
 * Booking straight to checkout. Needs WooCommerce — and NOT at this moment:
 * plugin files load alphabetically, so "wanaan-fare-finder" is included before
 * "woocommerce" and the class does not exist yet. Waiting for plugins_loaded is
 * the difference between the booking working and the link doing nothing at all.
 */
add_action(
	'plugins_loaded',
	function () {
		if ( class_exists( 'WooCommerce' ) ) {
			WNF_Cart::init();
		}
	}
);

/* ---------------------------------------------------------------------------
 * Scheduled refresh.
 * The ERP ping makes updates instant; this is the safety net for when the ping
 * never arrives. It runs in WP-Cron, never in a customer's page load.
 * ------------------------------------------------------------------------- */
add_action( WNF_CRON_HOOK, array( 'WNF_Pricing', 'refresh_from_erp' ) );

register_activation_hook(
	WNF_FILE,
	function () {
		if ( ! wp_next_scheduled( WNF_CRON_HOOK ) ) {
			wp_schedule_event( time() + 60, 'hourly', WNF_CRON_HOOK );
		}
	}
);

register_deactivation_hook(
	WNF_FILE,
	function () {
		$ts = wp_next_scheduled( WNF_CRON_HOOK );
		if ( $ts ) {
			wp_unschedule_event( $ts, WNF_CRON_HOOK );
		}
		delete_transient( WNF_TR_CACHE );
		delete_transient( WNF_TR_PRODUCTS );
	}
);
