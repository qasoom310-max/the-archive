<?php
/**
 * PortalSignature — the one HMAC scheme between this site and the ERP.
 *
 * The ERP already signs the payment / service-order portal this way, so the
 * fare feed reuses it rather than inventing a second scheme with a second
 * secret to lose. This class is the WordPress end of that contract.
 *
 * THE PATH-BOUND VARIANT, AND WHY IT MATTERS
 * -----------------------------------------
 * The original scheme signed only the body. A GET has no body, so the
 * workspace id — which lives in the URL — went unsigned, and a signature
 * minted for workspace 7 would verify just as happily against workspace 3:
 * another business's price list, readable with Wanaan's key. Folding the
 * path into the signed string closes that. self::vectors() carries the two
 * ERP regression vectors that prove it, and they are checked, not trusted.
 *
 *   canonical = "<METHOD>\n<PATH>\n<timestamp>.<raw body>"
 *
 *   METHOD     upper case
 *   PATH       path only — no scheme, host, query or trailing slash
 *   timestamp  unix seconds, inside the signed string so it cannot be swapped
 *   raw body   the bytes as sent; a GET leaves the slot empty after the dot
 *
 *   X-Wanaan-Timestamp: <unix seconds>
 *   X-Wanaan-Signature: hex( HMAC-SHA256( canonical, secret ) )
 *
 * Tolerance is five minutes either way.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WNF_Signature {

	const HEADER_SIG  = 'X-Wanaan-Signature';
	const HEADER_TIME = 'X-Wanaan-Timestamp';

	/** Seconds either side of now that a timestamp may fall. */
	const TOLERANCE = 300;

	/**
	 * The shared secret.
	 *
	 * The ERP's rule is one secret for one scheme, so this is the SAME value
	 * the service-order portal already uses — not a new key. Whichever
	 * constant that site defines it under, we find it; the filter is there for
	 * a setup that keeps it somewhere else entirely.
	 *
	 * @return string Empty when nothing is configured.
	 */
	public static function secret() {
		$candidates = array(
			'WANAAN_PORTAL_SECRET',
			'WANAAN_ERP_KEY',
			'WANAAN_SHARED_SECRET',
			'WANAAN_PORTAL_SHARED_SECRET',
		);

		$secret = '';
		foreach ( $candidates as $name ) {
			if ( defined( $name ) && '' !== (string) constant( $name ) ) {
				$secret = (string) constant( $name );
				break;
			}
		}

		/**
		 * Filter the shared secret used to sign and verify ERP traffic.
		 *
		 * @param string $secret Secret found in wp-config.php, or ''.
		 */
		return (string) apply_filters( 'wnf_portal_secret', $secret );
	}

	/**
	 * Which wp-config constant answered. For the admin screen — never the value.
	 *
	 * @return string
	 */
	public static function secret_source() {
		foreach ( array( 'WANAAN_PORTAL_SECRET', 'WANAAN_ERP_KEY', 'WANAAN_SHARED_SECRET', 'WANAAN_PORTAL_SHARED_SECRET' ) as $name ) {
			if ( defined( $name ) && '' !== (string) constant( $name ) ) {
				return $name;
			}
		}
		return has_filter( 'wnf_portal_secret' ) ? 'wnf_portal_secret filter' : '';
	}

	/**
	 * Reduce anything URL-shaped to the path the ERP signs.
	 *
	 * A full URL, a path with a query, a path with a trailing slash and a bare
	 * path must all produce the same string, or a signature made from the
	 * configured URL would not verify against the received request.
	 *
	 * @param string $url Full URL or path.
	 * @return string
	 */
	public static function path( $url ) {
		$url  = (string) $url;
		$path = wp_parse_url( $url, PHP_URL_PATH );

		if ( ! is_string( $path ) || '' === $path ) {
			$path = '/';
		}
		if ( '/' !== $path ) {
			$path = rtrim( $path, '/' );
		}
		if ( '' === $path ) {
			$path = '/';
		}

		return $path;
	}

	/**
	 * The exact string that gets signed.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Full URL or path.
	 * @param int    $ts     Unix seconds.
	 * @param string $body   Raw body, '' for a GET.
	 * @return string
	 */
	public static function canonical( $method, $url, $ts, $body = '' ) {
		return strtoupper( (string) $method )
			. "\n" . self::path( $url )
			. "\n" . (int) $ts . '.' . (string) $body;
	}

	/**
	 * @param string $method HTTP method.
	 * @param string $url    Full URL or path.
	 * @param int    $ts     Unix seconds.
	 * @param string $body   Raw body.
	 * @param string $secret Shared secret; defaults to the configured one.
	 * @return string Hex digest, or '' with no secret.
	 */
	public static function sign( $method, $url, $ts, $body = '', $secret = null ) {
		$secret = ( null === $secret ) ? self::secret() : (string) $secret;
		if ( '' === $secret ) {
			return '';
		}
		return hash_hmac( 'sha256', self::canonical( $method, $url, $ts, $body ), $secret );
	}

	/**
	 * Signed headers for an outbound request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Full URL.
	 * @param string $body   Raw body.
	 * @return array Empty when no secret is configured.
	 */
	public static function headers( $method, $url, $body = '' ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return array();
		}

		// The ERP compares against its own clock, so use real time, not
		// current_time() — that one is shifted by the site's timezone.
		$ts = time();

		return array(
			self::HEADER_TIME => (string) $ts,
			self::HEADER_SIG  => self::sign( $method, $url, $ts, $body, $secret ),
		);
	}

	/**
	 * Verify an inbound request.
	 *
	 * @param string $method HTTP method.
	 * @param string $url    Path or URL the request arrived on.
	 * @param string $body   Raw body exactly as received.
	 * @param string $ts     Timestamp header.
	 * @param string $sig    Signature header.
	 * @return true|WP_Error
	 */
	public static function verify( $method, $url, $body, $ts, $sig ) {
		$secret = self::secret();
		if ( '' === $secret ) {
			return new WP_Error(
				'wnf_no_secret',
				'The shared secret is not configured on this site.',
				array( 'status' => 503 )
			);
		}

		$ts  = trim( (string) $ts );
		$sig = trim( (string) $sig );

		if ( '' === $ts || '' === $sig ) {
			return new WP_Error( 'wnf_unsigned', 'Unsigned request.', array( 'status' => 401 ) );
		}
		if ( ! ctype_digit( $ts ) ) {
			return new WP_Error( 'wnf_bad_timestamp', 'Bad timestamp.', array( 'status' => 401 ) );
		}

		// Checked before the HMAC so a stale replay costs nothing to reject.
		if ( abs( time() - (int) $ts ) > self::TOLERANCE ) {
			return new WP_Error(
				'wnf_stale',
				'Timestamp outside the five-minute window.',
				array( 'status' => 401 )
			);
		}

		$expected = self::sign( $method, $url, (int) $ts, (string) $body, $secret );

		/*
		 * hash_equals, not ===. A short-circuiting comparison leaks how much of
		 * a guess was right through its own timing.
		 *
		 * There is deliberately no nonce store. The timestamp is inside the
		 * signed string and the window is five minutes, and this endpoint
		 * carries no prices — the worst a replay achieves is asking WordPress
		 * to re-read a feed it already reads hourly. A nonce cache would also
		 * reject the ERP's own retry of a push whose response it never saw.
		 */
		if ( '' === $expected || ! hash_equals( $expected, $sig ) ) {
			return new WP_Error( 'wnf_bad_signature', 'Bad signature.', array( 'status' => 401 ) );
		}

		return true;
	}

	/* ---------------------------------------------------------------------
	 * Self-test
	 * ------------------------------------------------------------------ */

	/**
	 * The ERP's own regression vectors, with its published test secret.
	 *
	 * Vectors 1 and 2 differ only in the workspace id in the path. They exist
	 * to prove the binding: if the two ever produce the same digest, the path
	 * has fallen out of the canonical string and this site's key could read
	 * another workspace's prices.
	 *
	 * @return array
	 */
	public static function vectors() {
		return array(
			'secret'  => 'pricing-test-secret',
			'cases'   => array(
				array(
					'method' => 'GET',
					'path'   => '/api/v1/workspaces/7/pricing',
					'body'   => '',
					'ts'     => 1757500000,
					'expect' => 'af0497cac2a226b04e078a893a1c8f0876cbe7222b2811d09a17344e61a393a4',
				),
				array(
					'method' => 'GET',
					'path'   => '/api/v1/workspaces/3/pricing',
					'body'   => '',
					'ts'     => 1757500000,
					'expect' => '9345b9f83d959741d0a4a0848a3b8361b67036f7cb91f88011aba40f9af9e99e',
				),
				array(
					'method' => 'POST',
					'path'   => '/wp-json/wanaan/v1/pricing/refresh',
					'body'   => '{"version":2,"ws":7}',
					'ts'     => 1757500000,
					'expect' => '63d32dfc80490f96c3c2046d8149d6d050f4928f3ae67074bc70098ab30964a1',
				),
			),
		);
	}

	/**
	 * Run the vectors. Shown on the plugin's settings screen so a signature
	 * that has drifted from the ERP's is visible before a customer finds it.
	 *
	 * @return array {ok:bool, passed:int, total:int, bound:bool, failures:array}
	 */
	public static function self_test() {
		$v        = self::vectors();
		$passed   = 0;
		$failures = array();

		foreach ( $v['cases'] as $i => $c ) {
			$got = self::sign( $c['method'], $c['path'], $c['ts'], $c['body'], $v['secret'] );
			if ( hash_equals( $c['expect'], $got ) ) {
				++$passed;
			} else {
				$failures[] = sprintf( '#%d %s %s', $i + 1, $c['method'], $c['path'] );
			}
		}

		// Same secret, same timestamp, different workspace — must differ.
		$a = self::sign( 'GET', '/api/v1/workspaces/7/pricing', 1757500000, '', $v['secret'] );
		$b = self::sign( 'GET', '/api/v1/workspaces/3/pricing', 1757500000, '', $v['secret'] );

		$bound = ( '' !== $a && $a !== $b );
		if ( ! $bound ) {
			$failures[] = 'path binding broken — a workspace 7 signature verifies for workspace 3';
		}

		$total = count( $v['cases'] );

		return array(
			'ok'       => ( $passed === $total && $bound ),
			'passed'   => $passed,
			'total'    => $total,
			'bound'    => $bound,
			'failures' => $failures,
		);
	}
}
