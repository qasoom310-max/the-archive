<?php
/**
 * Asks the Wanaan ERP which departure hours carry the empty-car discount.
 *
 * Server to server, signed with the WANAAN_PORTAL_SECRET constant the other
 * Wanaan plugins already define in wp-config.php. Each answer is kept for a
 * minute: every visitor's search comes through this one server, and the ERP
 * only needs to be asked once a minute per direction and day.
 */

defined( 'ABSPATH' ) || exit;

final class WBO_Client {

	const OPTION        = 'wbo_settings';
	const LAST_ERROR    = 'wbo_last_error';
	const CACHE_SECONDS = 60;
	const TIMEZONE      = 'Asia/Bahrain';

	/** @return array{erp_url:string,workspace:int,whatsapp:string} */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );

		return array_merge(
			array(
				'erp_url'   => 'https://erp.wanaan-bh.com',
				'workspace' => 7,
				'whatsapp'  => '',
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/** Today in Bahrain, YYYY-MM-DD. */
	public static function today() {
		$now = new DateTimeImmutable( 'now', new DateTimeZone( self::TIMEZONE ) );

		return $now->format( 'Y-m-d' );
	}

	/**
	 * The WhatsApp number to fall back on when the ERP cannot be asked: the
	 * one set here, else the site-wide WhatsApp Number plugin's.
	 */
	public static function fallback_whatsapp() {
		$typed = preg_replace( '/\D+/', '', (string) self::settings()['whatsapp'] );
		if ( strlen( $typed ) >= 8 ) {
			return $typed;
		}

		if ( class_exists( 'WWN_Number' ) ) {
			return (string) WWN_Number::current();
		}

		return '';
	}

	/**
	 * @param array{from:string,to:string,date:string,hours:int} $search A checked search.
	 * @return array<string,mixed>|null the public answer, or null when the ERP could not be asked
	 */
	public static function search( array $search ) {
		$settings = self::settings();
		$key      = 'wbo_' . md5( $settings['erp_url'] . '|' . $settings['workspace'] . '|' . WBO_Query::query_string( $search ) );

		$cached = get_transient( $key );
		if ( is_array( $cached ) ) {
			return $cached;
		}

		$answer = self::fetch( $settings, $search );
		if ( null !== $answer ) {
			set_transient( $key, $answer, self::CACHE_SECONDS );
		}

		return $answer;
	}

	private static function fetch( array $settings, array $search ) {
		$secret = defined( 'WANAAN_PORTAL_SECRET' ) ? (string) WANAAN_PORTAL_SECRET : '';
		if ( '' === $secret ) {
			update_option( self::LAST_ERROR, 'WANAAN_PORTAL_SECRET is not defined in wp-config.php.', false );

			return null;
		}

		$base = rtrim( (string) $settings['erp_url'], '/' );
		if ( '' === $base || (int) $settings['workspace'] < 1 ) {
			update_option( self::LAST_ERROR, 'The ERP address or database number is missing.', false );

			return null;
		}

		$path     = WBO_Query::path( $settings['workspace'], $search );
		$response = wp_remote_get(
			$base . $path,
			array(
				'timeout' => 6,
				'headers' => array( 'Accept' => 'application/json' ) + WBO_Signature::headers( 'GET', $path, $secret ),
			)
		);

		if ( is_wp_error( $response ) ) {
			update_option( self::LAST_ERROR, 'Could not reach the ERP: ' . $response->get_error_message(), false );

			return null;
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			update_option( self::LAST_ERROR, 'The ERP answered ' . $code . '.', false );

			return null;
		}

		$answer = WBO_Query::public_answer( json_decode( (string) wp_remote_retrieve_body( $response ), true ) );
		if ( null === $answer ) {
			update_option( self::LAST_ERROR, 'The ERP sent an answer this plugin does not understand.', false );

			return null;
		}

		delete_option( self::LAST_ERROR );

		return $answer;
	}
}
