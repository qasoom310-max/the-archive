<?php
/**
 * Plugin Name:       Wanaan Service Order
 * Plugin URI:        https://www.wanaan-bh.com
 * Description:        Receives limousine service orders from the Wanaan ERP, shows the customer a private Service Order page with a terms-and-conditions consent, takes payment through WooCommerce + Tap, and reports the paid status back to the ERP.
 * Version:           1.0.6
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Wanaan
 * License:           GPL-2.0-or-later
 * Text Domain:       wanaan-service-order
 *
 * The one shared contract with the ERP (Modules/Limousine on the Laravel side):
 *   - Inbound  : POST /wp-json/wanaan/v1/booking   (ERP -> WP, creates the link)
 *   - Outbound : POST {ERP}/limousine/payment-callback (WP -> ERP, marks it paid)
 *   - Auth     : X-Wanaan-Timestamp + X-Wanaan-Signature, where the signature is
 *                hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret), the
 *                timestamp is fresh within 5 minutes, and $secret is the shared
 *                key held in the WANAAN_PORTAL_SECRET wp-config constant.
 *
 * The secret NEVER lives in the database or in this file. Define it in wp-config.php:
 *   define( 'WANAAN_PORTAL_SECRET', 'the-same-string-the-ERP-holds' );
 */

defined( 'ABSPATH' ) || exit;

define( 'WANAAN_SO_VERSION', '1.0.6' );
define( 'WANAAN_SO_FILE', __FILE__ );
define( 'WANAAN_SO_DIR', plugin_dir_path( __FILE__ ) );
define( 'WANAAN_SO_URL', plugin_dir_url( __FILE__ ) );

/** The custom table name, always resolved through $wpdb->prefix. */
function wanaan_so_table() {
	global $wpdb;
	return $wpdb->prefix . 'wanaan_service_orders';
}

require_once WANAAN_SO_DIR . 'includes/class-wso-signature.php';
require_once WANAAN_SO_DIR . 'includes/class-wso-install.php';
require_once WANAAN_SO_DIR . 'includes/class-wso-repository.php';
require_once WANAAN_SO_DIR . 'includes/class-wso-callback.php';
require_once WANAAN_SO_DIR . 'includes/class-wso-rest.php';
require_once WANAAN_SO_DIR . 'includes/class-wso-page.php';
require_once WANAAN_SO_DIR . 'includes/class-wso-woo.php';
require_once WANAAN_SO_DIR . 'includes/class-wso-admin.php';

register_activation_hook( __FILE__, array( 'WSO_Install', 'activate' ) );
register_deactivation_hook( __FILE__, array( 'WSO_Install', 'deactivate' ) );

add_action( 'plugins_loaded', 'wanaan_so_boot' );

/**
 * Wire every piece up once WordPress (and WooCommerce, if present) is ready.
 */
function wanaan_so_boot() {
	// A schema bump between versions self-heals without a reactivation.
	WSO_Install::maybe_upgrade();

	WSO_Rest::init();      // /wp-json/wanaan/v1/booking receiver
	WSO_Page::init();      // /service-order/{token} public page + pay handler
	WSO_Woo::init();       // WooCommerce order creation + payment-complete -> callback
	WSO_Callback::init();  // WP-Cron retry of a failed callback
	WSO_Admin::init();     // wp-admin list + settings (callback URL)
}
