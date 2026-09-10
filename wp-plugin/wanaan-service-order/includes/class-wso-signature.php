<?php
/**
 * The signing scheme shared with the ERP. This MUST match
 * Modules\Limousine\Support\PortalSignature on the Laravel side byte-for-byte,
 * or nothing verifies in either direction.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Signature {

	const TIMESTAMP_HEADER = 'X-Wanaan-Timestamp';
	const SIGNATURE_HEADER = 'X-Wanaan-Signature';

	/** How far a request's timestamp may drift from now, in seconds. */
	const MAX_SKEW_SECONDS = 300;

	/**
	 * The shared secret, from the wp-config constant. Never stored in the DB.
	 *
	 * @return string Empty string when the constant is not defined — every
	 *                signature then fails closed, so no request is trusted.
	 */
	public static function secret() {
		if ( defined( 'WANAAN_PORTAL_SECRET' ) && is_string( WANAAN_PORTAL_SECRET ) ) {
			return WANAAN_PORTAL_SECRET;
		}
		return '';
	}

	/**
	 * Hex HMAC-SHA256 of the timestamp-bound raw body.
	 *
	 * @param string $raw_body   The exact bytes that will be sent/received.
	 * @param string $timestamp  Unix seconds, as a string.
	 * @param string $secret     The shared key.
	 * @return string
	 */
	public static function sign( $raw_body, $timestamp, $secret ) {
		return hash_hmac( 'sha256', $timestamp . '.' . $raw_body, $secret );
	}

	/**
	 * True only when the signature matches AND the timestamp is fresh. Every
	 * input here is attacker-controlled, so each is checked before use and the
	 * comparison is constant-time.
	 *
	 * @param string $raw_body
	 * @param string $timestamp
	 * @param string $signature
	 * @param string $secret
	 * @return bool
	 */
	public static function verify( $raw_body, $timestamp, $signature, $secret ) {
		if ( '' === $secret || '' === $signature || '' === $timestamp ) {
			return false;
		}
		if ( ! ctype_digit( (string) $timestamp ) ) {
			return false;
		}
		if ( abs( time() - (int) $timestamp ) > self::MAX_SKEW_SECONDS ) {
			return false;
		}
		return hash_equals( self::sign( $raw_body, $timestamp, $secret ), (string) $signature );
	}
}
