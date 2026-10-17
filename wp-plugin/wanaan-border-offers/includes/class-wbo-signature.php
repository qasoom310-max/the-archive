<?php
/**
 * Signs a read of the Wanaan ERP.
 *
 * The same scheme as the ERP's PortalSignature::signRequest():
 *   hash_hmac('sha256', "<METHOD>\n<PATH?QUERY>\n<timestamp>.<raw-body>", $secret)
 * For this endpoint the ERP signs the query string too, so the PATH here is
 * exactly the path and query the request is sent to, in the same order.
 *
 * No WordPress calls in this file, so it can be tested on its own.
 */

defined( 'ABSPATH' ) || exit;

final class WBO_Signature {

	const TIMESTAMP_HEADER = 'X-Wanaan-Timestamp';
	const SIGNATURE_HEADER = 'X-Wanaan-Signature';

	/**
	 * @param string $method        GET.
	 * @param string $path_and_query e.g. /api/v1/workspaces/7/border-offers?from=SA&to=BH&date=2026-10-21
	 * @param string $body          Empty for a GET.
	 * @param string $timestamp     Unix seconds.
	 * @param string $secret        WANAAN_PORTAL_SECRET.
	 * @return string hex HMAC-SHA256
	 */
	public static function sign( $method, $path_and_query, $body, $timestamp, $secret ) {
		return hash_hmac( 'sha256', strtoupper( (string) $method ) . "\n" . $path_and_query . "\n" . $timestamp . '.' . $body, (string) $secret );
	}

	/** @return array<string,string> the two headers for a request signed now. */
	public static function headers( $method, $path_and_query, $secret, $now = null ) {
		$timestamp = (string) ( null === $now ? time() : (int) $now );

		return array(
			self::TIMESTAMP_HEADER => $timestamp,
			self::SIGNATURE_HEADER => self::sign( $method, $path_and_query, '', $timestamp, $secret ),
		);
	}
}
