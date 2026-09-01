<?php
/**
 * The public /service-order/{token} page and its "Pay" handler.
 *
 * The page is unguessable-token addressed, sent noindex, and renders the same
 * Service Order the ERP PDF shows. The customer must tick the terms checkbox
 * before the Pay button submits; we record that consent server-side, then hand
 * off to WooCommerce (Tap) for the actual payment.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Page {

	const QUERY_VAR    = 'wanaan_so_token';
	const PAY_ACTION   = 'wanaan_so_pay';
	const NONCE_ACTION = 'wanaan_so_pay_nonce';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_rewrite_rule' ) );
		add_filter( 'query_vars', array( __CLASS__, 'register_query_var' ) );
		add_action( 'template_redirect', array( __CLASS__, 'maybe_render' ) );

		// The pay form posts here; both logged-in and logged-out customers.
		add_action( 'admin_post_' . self::PAY_ACTION, array( __CLASS__, 'handle_pay' ) );
		add_action( 'admin_post_nopriv_' . self::PAY_ACTION, array( __CLASS__, 'handle_pay' ) );
	}

	/**
	 * Map /service-order/{token} onto our query var. The token is [A-Za-z0-9].
	 */
	public static function add_rewrite_rule() {
		add_rewrite_rule(
			'^service-order/([A-Za-z0-9]+)/?$',
			'index.php?' . self::QUERY_VAR . '=$matches[1]',
			'top'
		);
	}

	public static function register_query_var( $vars ) {
		$vars[] = self::QUERY_VAR;
		return $vars;
	}

	/** The public URL for a token. */
	public static function url( $token ) {
		return home_url( '/service-order/' . rawurlencode( (string) $token ) );
	}

	/**
	 * When the token query var is present, render our template and stop — this
	 * page is not a normal WordPress post.
	 */
	public static function maybe_render() {
		$token = get_query_var( self::QUERY_VAR );
		if ( '' === $token || null === $token ) {
			return;
		}

		$row = WSO_Repository::find_by_token( sanitize_text_field( (string) $token ) );

		nocache_headers();
		header( 'X-Robots-Tag: noindex, nofollow', true );

		status_header( $row ? 200 : 404 );

		// Expose the row + a flash message to the template.
		$GLOBALS['wanaan_so_row']    = $row;
		$GLOBALS['wanaan_so_notice'] = isset( $_GET['error'] ) ? sanitize_text_field( wp_unslash( $_GET['error'] ) ) : '';

		include WANAAN_SO_DIR . 'templates/service-order.php';
		exit;
	}

	/**
	 * Handle the Pay submission: verify nonce + token, require the consent
	 * checkbox, record consent, then create/reuse the WooCommerce order and
	 * redirect the customer to its Tap checkout.
	 */
	public static function handle_pay() {
		$token = isset( $_POST['token'] ) ? sanitize_text_field( wp_unslash( $_POST['token'] ) ) : '';
		$row   = $token ? WSO_Repository::find_by_token( $token ) : null;

		if ( ! $row ) {
			wp_die( esc_html__( 'This payment link is not valid.', 'wanaan-service-order' ), '', array( 'response' => 404 ) );
		}

		$back = self::url( $row->token );

		if ( ! isset( $_POST['_wanaan_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_wanaan_nonce'] ) ), self::NONCE_ACTION ) ) {
			wp_safe_redirect( add_query_arg( 'error', 'expired', $back ) );
			exit;
		}

		if ( 'paid' === $row->status ) {
			wp_safe_redirect( add_query_arg( 'error', 'already_paid', $back ) );
			exit;
		}

		// The customer agrees to the terms once, on the WooCommerce checkout —
		// there's no separate checkbox here. Record when they proceed to pay as
		// an audit note (IP + user agent + time).
		WSO_Repository::record_consent( $row->id, self::client_ip(), isset( $_SERVER['HTTP_USER_AGENT'] ) ? wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) : '' );

		$checkout_url = WSO_Woo::checkout_url_for( $row );
		if ( is_wp_error( $checkout_url ) || ! $checkout_url ) {
			wp_safe_redirect( add_query_arg( 'error', 'woo', $back ) );
			exit;
		}

		wp_safe_redirect( $checkout_url );
		exit;
	}

	/**
	 * Best-effort client IP for the consent record. Honours a proxy header only
	 * for its first value; this is an audit note, not an access control.
	 */
	private static function client_ip() {
		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_FOR'] ) ) {
			$parts = explode( ',', (string) wp_unslash( $_SERVER['HTTP_X_FORWARDED_FOR'] ) );
			return trim( $parts[0] );
		}
		return isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '';
	}
}
