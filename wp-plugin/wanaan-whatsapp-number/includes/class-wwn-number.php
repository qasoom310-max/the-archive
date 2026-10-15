<?php
/**
 * Where the WhatsApp number comes from.
 *
 * By default it is read from the Wanaan ERP — the WhatsApp field on the ERP's
 * Fares screen (Settings box), the same number the fare finder already shows —
 * so it is changed in ONE place for the whole site. The read is the ERP's
 * signed pricing endpoint, using the WANAAN_PORTAL_SECRET constant the
 * service-order plugin already defines in wp-config.php.
 *
 * The answer is kept for 10 minutes. If the ERP cannot be reached, the last
 * number it gave is used, then the number typed on the settings page — the
 * site never shows no number because the ERP had a bad minute.
 */

defined( 'ABSPATH' ) || exit;

final class WWN_Number {

	const OPTION        = 'wwn_settings';
	const LAST_GOOD     = 'wwn_last_erp_number';
	const LAST_CHECKED  = 'wwn_last_erp_check';
	const LAST_ERROR    = 'wwn_last_erp_error';
	const CACHE         = 'wwn_erp_number';
	const CACHE_SECONDS = 600;
	const RETRY_SECONDS = 120;

	/** @return array{source:string,erp_url:string,workspace:int,number:string,others:string,enabled:bool} */
	public static function settings() {
		$saved = get_option( self::OPTION, array() );

		return array_merge(
			array(
				'source'    => 'erp',
				'erp_url'   => 'https://erp.wanaan-bh.com',
				'workspace' => 7,
				'number'    => '97339991869',
				'others'    => "+973 3999 1869",
				'enabled'   => true,
			),
			is_array( $saved ) ? $saved : array()
		);
	}

	/** The number to show right now, digits with country code. Never empty when one was ever set. */
	public static function current() {
		$settings = self::settings();

		if ( 'erp' === $settings['source'] ) {
			$fromErp = self::fromErp( $settings );
			if ( '' !== $fromErp ) {
				return $fromErp;
			}
		}

		return WWN_Rewriter::digits( $settings['number'] );
	}

	/** Where the current number came from, for the settings page. */
	public static function origin() {
		$settings = self::settings();
		if ( 'erp' !== $settings['source'] ) {
			return 'typed';
		}

		return '' !== (string) get_option( self::LAST_GOOD, '' ) ? 'erp' : 'typed-fallback';
	}

	/** @return string[] the "show as WhatsApp" numbers, one per line on the settings page */
	public static function others() {
		$lines = preg_split( '~[\r\n,]+~', (string) self::settings()['others'] );

		return array_values( array_filter( array_map( 'trim', is_array( $lines ) ? $lines : array() ) ) );
	}

	/** Forget the kept answer, so the next page asks the ERP again. */
	public static function refresh() {
		delete_transient( self::CACHE );
	}

	private static function fromErp( array $settings ) {
		$cached = get_transient( self::CACHE );
		if ( false !== $cached ) {
			return (string) $cached;
		}

		$lastGood = (string) get_option( self::LAST_GOOD, '' );
		$fetched  = self::fetch( $settings );

		update_option( self::LAST_CHECKED, time(), false );

		if ( null === $fetched ) {
			// Keep serving the last good answer, and don't ask again for a bit.
			set_transient( self::CACHE, $lastGood, self::RETRY_SECONDS );

			return $lastGood;
		}

		delete_option( self::LAST_ERROR );
		set_transient( self::CACHE, $fetched, self::CACHE_SECONDS );

		if ( $fetched !== $lastGood ) {
			update_option( self::LAST_GOOD, $fetched, false );
			self::purgeCaches();
		}

		return $fetched;
	}

	/** The WhatsApp number from the ERP, or null (and the reason saved) when it can't be had. */
	private static function fetch( array $settings ) {
		$secret = defined( 'WANAAN_PORTAL_SECRET' ) ? (string) WANAAN_PORTAL_SECRET : '';
		if ( '' === $secret ) {
			update_option( self::LAST_ERROR, 'WANAAN_PORTAL_SECRET is not defined in wp-config.php.', false );

			return null;
		}

		$base      = rtrim( (string) $settings['erp_url'], '/' );
		$workspace = (int) $settings['workspace'];
		if ( '' === $base || $workspace < 1 ) {
			update_option( self::LAST_ERROR, 'The ERP address or database number is missing.', false );

			return null;
		}

		$path      = '/api/v1/workspaces/' . $workspace . '/pricing';
		$timestamp = (string) time();
		// Same scheme as the ERP's PortalSignature::signRequest():
		// "<METHOD>\n<PATH>\n<timestamp>.<raw-body>", body empty for a GET.
		$signature = hash_hmac( 'sha256', "GET\n" . $path . "\n" . $timestamp . '.', $secret );

		$response = wp_remote_get(
			$base . $path,
			array(
				'timeout' => 4,
				'headers' => array(
					'Accept'             => 'application/json',
					'X-Wanaan-Timestamp' => $timestamp,
					'X-Wanaan-Signature' => $signature,
				),
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

		$body   = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$number = is_array( $body ) ? WWN_Rewriter::digits( $body['settings']['whatsapp'] ?? '' ) : '';

		if ( strlen( $number ) < 8 ) {
			update_option( self::LAST_ERROR, 'The ERP has no WhatsApp number set (Fares → Settings).', false );

			return null;
		}

		return $number;
	}

	/** A cached page would go on showing the old number, so empty the page caches we know of. */
	public static function purgeCaches() {
		do_action( 'litespeed_purge_all' );
		do_action( 'wp_cache_clear_cache' );
		if ( function_exists( 'rocket_clean_domain' ) ) {
			rocket_clean_domain();
		}
		if ( function_exists( 'wp_cache_flush' ) ) {
			wp_cache_flush();
		}
		do_action( 'wwn_number_changed' );
	}
}
