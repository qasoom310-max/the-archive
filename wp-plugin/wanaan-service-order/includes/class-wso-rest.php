<?php
/**
 * The inbound REST receiver: POST /wp-json/wanaan/v1/booking.
 *
 * The ERP signs its request; we verify the HMAC over the RAW body before
 * trusting a single field, then upsert the service order and answer with the
 * public URL + token the ERP stores against the link.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			'wanaan/v1',
			'/booking',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'receive' ),
				// Public transport; the HMAC signature is the only credential.
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * @param WP_REST_Request $request
	 * @return WP_REST_Response
	 */
	public static function receive( WP_REST_Request $request ) {
		$raw    = $request->get_body();
		$secret = WSO_Signature::secret();

		$verified = WSO_Signature::verify(
			$raw,
			(string) $request->get_header( WSO_Signature::TIMESTAMP_HEADER ),
			(string) $request->get_header( WSO_Signature::SIGNATURE_HEADER ),
			$secret
		);

		if ( ! $verified ) {
			return new WP_REST_Response( array( 'error' => 'invalid signature' ), 401 );
		}

		$data = json_decode( $raw, true );
		if ( ! is_array( $data ) ) {
			return new WP_REST_Response( array( 'error' => 'bad request' ), 400 );
		}

		$row = WSO_Repository::upsert_from_erp( $data );
		if ( ! $row ) {
			return new WP_REST_Response( array( 'error' => 'could not store' ), 422 );
		}

		return new WP_REST_Response(
			array(
				'url'   => WSO_Page::url( $row->token ),
				'token' => $row->token,
			),
			200
		);
	}
}
