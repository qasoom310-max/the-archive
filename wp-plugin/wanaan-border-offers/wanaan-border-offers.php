<?php
/**
 * Plugin Name:       Wanaan Border Offers
 * Plugin URI:        https://www.wanaan-bh.com
 * Description:       A search box for rides between Bahrain and Saudi Arabia. The Wanaan ERP knows where every booked car will be; when one has to drive that way empty, the hours it can take a passenger are shown at a discount (30% by default, set in the ERP). Shortcode: [wanaan_border_offers]
 * Version:           1.1.0
 * Requires at least: 6.0
 * Requires PHP:      7.4
 * Author:            Wanaan
 * License:           GPL-2.0-or-later
 * Text Domain:       wanaan-border-offers
 *
 * Contract with the ERP:
 *   GET {ERP}/api/v1/workspaces/{ws}/border-offers?from=SA&to=BH&date=YYYY-MM-DD
 *   signed with X-Wanaan-Timestamp + X-Wanaan-Signature =
 *   hash_hmac('sha256', "GET\n<path?query>\n<timestamp>.", WANAAN_PORTAL_SECRET)
 * The secret lives only in wp-config.php (WANAAN_PORTAL_SECRET), as for the
 * other Wanaan plugins.
 */

defined( 'ABSPATH' ) || exit;

define( 'WBO_VERSION', '1.1.0' );
define( 'WBO_FILE', __FILE__ );
define( 'WBO_DIR', plugin_dir_path( __FILE__ ) );
define( 'WBO_URL', plugin_dir_url( __FILE__ ) );

require_once WBO_DIR . 'includes/class-wbo-signature.php';
require_once WBO_DIR . 'includes/class-wbo-query.php';
require_once WBO_DIR . 'includes/class-wbo-strings.php';
require_once WBO_DIR . 'includes/class-wbo-client.php';
require_once WBO_DIR . 'includes/class-wbo-rest.php';
require_once WBO_DIR . 'includes/class-wbo-shortcode.php';
require_once WBO_DIR . 'includes/class-wbo-admin.php';

add_action( 'plugins_loaded', 'wbo_boot' );

function wbo_boot() {
	WBO_Rest::init();
	WBO_Shortcode::init();

	if ( is_admin() ) {
		WBO_Admin::init();
	}
}
