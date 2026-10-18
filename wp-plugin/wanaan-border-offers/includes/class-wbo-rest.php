<?php
/**
 * GET /wp-json/wanaan/v1/border-offers?from=SA&to=BH&date=2026-10-21&hours=2
 *
 * What the search box calls. Public (any visitor may search), so it checks
 * the question itself, limits how often one visitor may ask, and passes on
 * only the hours — never anything else the ERP knows.
 */

defined( 'ABSPATH' ) || exit;

final class WBO_Rest {

	const PER_MINUTE = 40;

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'wanaan/v1',
			'/border-offers',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'search' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	public static function search( WP_REST_Request $request ) {
		if ( self::too_many() ) {
			return self::reply( array( 'error' => 'busy' ), 429 );
		}

		$search = WBO_Query::normalise( $request->get_param( 'from' ), $request->get_param( 'to' ), $request->get_param( 'date' ), WBO_Client::today(), $request->get_param( 'hours' ) );
		if ( ! is_array( $search ) ) {
			return self::reply( array( 'error' => $search ), 422 );
		}

		$answer = WBO_Client::search( $search );
		if ( null === $answer ) {
			return self::reply(
				array(
					'error'    => 'unavailable',
					'whatsapp' => WBO_Client::fallback_whatsapp(),
				),
				502
			);
		}

		if ( '' === $answer['whatsapp'] ) {
			$answer['whatsapp'] = WBO_Client::fallback_whatsapp();
		}

		return self::reply( $answer, 200 );
	}

	private static function reply( array $body, $status ) {
		$response = new WP_REST_Response( $body, $status );
		// Answers change as bookings come in: no page cache may keep them.
		$response->header( 'Cache-Control', 'no-store, private' );

		return $response;
	}

	/** A simple per-visitor limit, so one visitor cannot hammer the ERP. */
	private static function too_many() {
		$ip  = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) $_SERVER['REMOTE_ADDR'] : '';
		$key = 'wbo_rate_' . md5( $ip . '|' . gmdate( 'YmdHi' ) );

		$count = (int) get_transient( $key );
		set_transient( $key, $count + 1, 70 );

		return $count >= self::PER_MINUTE;
	}
}
