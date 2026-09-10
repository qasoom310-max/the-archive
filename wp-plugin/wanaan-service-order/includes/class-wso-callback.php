<?php
/**
 * Reports a paid service order back to the ERP:
 *   POST {callback_url}/limousine/payment-callback
 * signed with the same HMAC scheme the ERP verifies.
 *
 * The ERP side is idempotent (it credits a link once), so a retry is always
 * safe. If the POST fails we back off and retry a handful of times through
 * WP-Cron, so a brief ERP outage doesn't lose the "paid" signal.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Callback {

	const RETRY_HOOK  = 'wanaan_so_retry_callback';
	const MAX_RETRIES = 5;

	/** Where the ERP receives the callback — set on the admin settings screen. */
	const OPTION_CALLBACK_URL = 'wanaan_so_callback_url';

	public static function init() {
		add_action( self::RETRY_HOOK, array( __CLASS__, 'send' ), 10, 2 );
	}

	/**
	 * Send (or re-send) the paid callback for a row.
	 *
	 * @param int $row_id
	 * @param int $attempt 1-based attempt counter.
	 */
	public static function send( $row_id, $attempt = 1 ) {
		$row = WSO_Repository::find( $row_id );
		if ( ! $row || 'paid' !== $row->status ) {
			return;
		}

		$url    = self::callback_url();
		$secret = WSO_Signature::secret();
		if ( '' === $url || '' === $secret ) {
			// Nothing to send to / no key — leave it for a manual resend.
			return;
		}

		$payload = array(
			'ws'              => ( null !== $row->ws && '' !== $row->ws ) ? (int) $row->ws : null,
			'erp_payment_id'  => (int) $row->erp_payment_id,
			'erp_booking_id'  => (int) $row->erp_booking_id,
			'erp_leg_id'      => (int) $row->erp_leg_id,
			'status'          => 'paid',
			'amount'          => number_format( (float) $row->amount, 3, '.', '' ),
			'currency'        => (string) $row->currency,
			'woo_order_id'    => $row->woo_order_id ? (int) $row->woo_order_id : null,
			'transaction_ref' => (string) $row->transaction_ref,
		);

		$body      = wp_json_encode( $payload );
		$timestamp = (string) time();
		$signature = WSO_Signature::sign( $body, $timestamp, $secret );

		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Content-Type'                    => 'application/json',
					'Accept'                          => 'application/json',
					WSO_Signature::TIMESTAMP_HEADER   => $timestamp,
					WSO_Signature::SIGNATURE_HEADER   => $signature,
				),
				'body'    => $body,
			)
		);

		$code = is_wp_error( $response ) ? 0 : (int) wp_remote_retrieve_response_code( $response );

		if ( 200 === $code ) {
			WSO_Repository::update( $row_id, array() ); // touch updated_at
			return;
		}

		// Failed — schedule a backed-off retry until we run out of attempts.
		if ( $attempt < self::MAX_RETRIES ) {
			$delay = min( 3600, 60 * pow( 2, $attempt ) ); // 2,4,8,16,32 min, capped
			wp_schedule_single_event( time() + $delay, self::RETRY_HOOK, array( $row_id, $attempt + 1 ) );
		}
	}

	/**
	 * The ERP callback endpoint. The admin enters the ERP base (or the full
	 * endpoint); we normalise to .../limousine/payment-callback.
	 */
	public static function callback_url() {
		$stored = trim( (string) get_option( self::OPTION_CALLBACK_URL, '' ) );
		if ( '' === $stored ) {
			return '';
		}
		if ( false !== strpos( $stored, '/limousine/payment-callback' ) ) {
			return $stored;
		}
		return rtrim( $stored, '/' ) . '/limousine/payment-callback';
	}
}
