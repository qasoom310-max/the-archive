<?php
/**
 * The endpoint the ERP pings when a price changes.
 *
 * It carries no prices. The ERP only says "there is a new version" and this
 * plugin goes and fetches it. Data flows one way, so a forged ping cannot set
 * a fare — the worst it can do is make WordPress ask the ERP a question.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WNF_Rest {

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	public static function routes() {
		register_rest_route(
			'wanaan/v1',
			'/pricing/refresh',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'refresh' ),
				'permission_callback' => array( __CLASS__, 'authorised' ),
				'args'                => array(
					'version' => array(
						'required'          => false,
						'sanitize_callback' => 'absint',
					),
				),
			)
		);

		// Read-only health check, same key. Useful when Mohammed is wiring the
		// ERP up and needs to know whether the key and URL are right.
		register_rest_route(
			'wanaan/v1',
			'/pricing/status',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'status' ),
				'permission_callback' => array( __CLASS__, 'authorised' ),
			)
		);
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return bool|WP_Error
	 */
	public static function authorised( $request ) {
		/*
		 * The signature covers METHOD, PATH, timestamp and the RAW body — so
		 * read the body exactly as it arrived. get_param() would hand back
		 * WordPress's re-decoded version, whose spacing and key order need not
		 * match the bytes the ERP hashed, and every ping would fail.
		 */
		$body = (string) $request->get_body();
		$ts   = $request->get_header( 'x-wanaan-timestamp' );
		$sig  = $request->get_header( 'x-wanaan-signature' );

		$result = null;

		foreach ( self::paths( $request ) as $path ) {
			$result = WNF_Signature::verify( $request->get_method(), $path, $body, $ts, $sig );
			if ( true === $result ) {
				return true;
			}
			// A missing secret or a stale timestamp is not about the path.
			// Stop rather than reporting it once per candidate.
			if ( is_wp_error( $result ) && 'wnf_bad_signature' !== $result->get_error_code() ) {
				return $result;
			}
		}

		return $result;
	}

	/**
	 * The path the ERP signed.
	 *
	 * The ERP signs the URL it posted to — `/wp-json/wanaan/v1/pricing/refresh`,
	 * with the `/wp-json` prefix. `$request->get_route()` gives only the part
	 * after that prefix, so signing against it would fail every time.
	 *
	 * The request URI is therefore the real answer. The second candidate is
	 * for a site whose REST prefix has been renamed or that lives in a
	 * subdirectory, where the URI seen by PHP and the URL the ERP was given
	 * can differ. Offering both weakens nothing: a caller still has to produce
	 * a valid signature for a path this endpoint actually answers on, and
	 * without the secret they cannot produce one for any path at all.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return string[]
	 */
	private static function paths( $request ) {
		$paths = array();

		if ( ! empty( $_SERVER['REQUEST_URI'] ) ) {
			$paths[] = esc_url_raw( wp_unslash( $_SERVER['REQUEST_URI'] ) ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput
		}

		$paths[] = rest_url( $request->get_route() );

		return array_values( array_unique( array_filter( $paths ) ) );
	}

	/**
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function refresh( $request ) {
		/*
		 * The ERP sends its workspace id alongside the version. Wanaan is
		 * workspace 7; the id also sits in the pricing URL this site is
		 * configured with. If a correctly signed ping arrives for a DIFFERENT
		 * workspace, something is misrouted — refuse rather than go and fetch.
		 */
		$claimed_ws = (int) $request->get_param( 'ws' );
		$settings   = wnf_settings();
		$our_ws     = self::workspace_of( $settings['erp_url'] );

		if ( $claimed_ws > 0 && $our_ws > 0 && $claimed_ws !== $our_ws ) {
			return new WP_REST_Response(
				array(
					'ok'      => false,
					'version' => null,
					'message' => 'Ping is for workspace ' . $claimed_ws . '; this site reads workspace ' . $our_ws . '.',
				),
				409
			);
		}

		$claimed = (int) $request->get_param( 'version' );
		$status  = get_option( WNF_OPT_STATUS, array() );
		$have    = isset( $status['version'] ) ? (int) $status['version'] : 0;

		// The ERP debounces its own pings, but a duplicate still costs a round
		// trip. If it is telling us about a version we already hold, stop here.
		if ( $claimed > 0 && $claimed === $have ) {
			return new WP_REST_Response(
				array(
					'ok'      => true,
					'skipped' => true,
					'version' => $have,
					'message' => 'Already on this version.',
				),
				200
			);
		}

		$result = WNF_Pricing::refresh_from_erp();

		return new WP_REST_Response(
			array(
				'ok'      => (bool) $result['ok'],
				'version' => $result['version'],
				'message' => $result['message'],
			),
			$result['ok'] ? 200 : 502
		);
	}

	/**
	 * @return WP_REST_Response
	 */
	public static function status() {
		$settings = wnf_settings();
		$status   = get_option( WNF_OPT_STATUS, array() );
		$test     = WNF_Signature::self_test();

		return new WP_REST_Response(
			array(
				'plugin_version' => WNF_VERSION,
				'source'         => $settings['source'],
				// Whether a secret is installed and which constant holds it.
				// Never the value itself.
				'has_key'        => '' !== WNF_Signature::secret(),
				'secret_source'  => WNF_Signature::secret_source(),
				'workspace'      => self::workspace_of( $settings['erp_url'] ),
				'signature_self_test' => array(
					'ok'     => $test['ok'],
					'passed' => $test['passed'] . '/' . $test['total'],
					'path_bound' => $test['bound'],
				),
				'pricing_version' => isset( $status['version'] ) ? (int) $status['version'] : null,
				'last_success'   => isset( $status['last_success'] ) ? $status['last_success'] : null,
				'last_attempt'   => isset( $status['last_attempt'] ) ? $status['last_attempt'] : null,
				'last_message'   => isset( $status['last_message'] ) ? $status['last_message'] : null,
			),
			200
		);
	}

	/**
	 * The workspace id out of a pricing URL.
	 *
	 * The ERP is multi-tenant and carries the workspace as a path segment:
	 * https://erp.wanaan-bh.com/api/v1/workspaces/7/pricing
	 *
	 * @param string $url ERP pricing URL.
	 * @return int 0 when the URL has no workspace segment.
	 */
	public static function workspace_of( $url ) {
		$path = wp_parse_url( (string) $url, PHP_URL_PATH );
		if ( ! is_string( $path ) ) {
			return 0;
		}
		return preg_match( '#/workspaces/(\d+)(?:/|$)#', $path, $m ) ? (int) $m[1] : 0;
	}
}
