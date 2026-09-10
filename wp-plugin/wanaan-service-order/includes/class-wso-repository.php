<?php
/**
 * All database access to the service-order table lives here, so escaping and
 * the field list are in one place.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Repository {

	/** Fields the ERP sends that map straight onto columns. */
	private static function payload_columns() {
		return array(
			'confirmation_no',
			'booking_no',
			'customer_name',
			'telephone',
			'service_date',
			'service_time',
			'vehicle',
			'flight_number',
			'pax_name',
			'pax_contact',
			'pickup',
			'dropoff',
			'remark',
		);
	}

	/**
	 * Create the row for a new ERP payment link, or update it in place if the
	 * ERP re-sends the same erp_payment_id. The token + status + payment fields
	 * are preserved on an update so a re-send can't unpay a link or move its URL.
	 *
	 * @param array $data Decoded ERP payload.
	 * @return object|null The stored row (with token), or null on failure.
	 */
	public static function upsert_from_erp( array $data ) {
		global $wpdb;

		$erp_payment_id = isset( $data['erp_payment_id'] ) ? absint( $data['erp_payment_id'] ) : 0;
		if ( $erp_payment_id <= 0 ) {
			return null;
		}

		$existing = self::find_by_erp_payment_id( $erp_payment_id );
		$now      = current_time( 'mysql' );

		$row = array(
			'erp_booking_id' => isset( $data['erp_booking_id'] ) ? absint( $data['erp_booking_id'] ) : null,
			'erp_leg_id'     => isset( $data['erp_leg_id'] ) ? absint( $data['erp_leg_id'] ) : null,
			'ws'             => ( isset( $data['ws'] ) && is_numeric( $data['ws'] ) ) ? (int) $data['ws'] : null,
			'customer_email' => isset( $data['email'] ) ? sanitize_email( (string) $data['email'] ) : null,
			'so_date'        => isset( $data['date'] ) ? sanitize_text_field( (string) $data['date'] ) : null,
			'amount'         => isset( $data['amount'] ) ? number_format( (float) $data['amount'], 3, '.', '' ) : '0.000',
			'currency'       => isset( $data['currency'] ) ? sanitize_text_field( (string) $data['currency'] ) : 'BHD',
			'updated_at'     => $now,
		);

		foreach ( self::payload_columns() as $col ) {
			$row[ $col ] = isset( $data[ $col ] ) ? sanitize_text_field( (string) $data[ $col ] ) : null;
		}

		if ( $existing ) {
			$wpdb->update( wanaan_so_table(), $row, array( 'id' => $existing->id ) );
			return self::find_by_erp_payment_id( $erp_payment_id );
		}

		$row['erp_payment_id'] = $erp_payment_id;
		$row['token']          = self::unique_token();
		$row['status']         = 'unpaid';
		$row['created_at']     = $now;

		$ok = $wpdb->insert( wanaan_so_table(), $row );
		if ( false === $ok ) {
			return null;
		}

		return self::find_by_erp_payment_id( $erp_payment_id );
	}

	/**
	 * A 32-char token, re-rolled on the astronomically-unlikely collision.
	 */
	private static function unique_token() {
		do {
			$token = bin2hex( random_bytes( 16 ) );
		} while ( self::find_by_token( $token ) );

		return $token;
	}

	public static function find_by_erp_payment_id( $erp_payment_id ) {
		global $wpdb;
		$table = wanaan_so_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE erp_payment_id = %d", absint( $erp_payment_id ) )
		);
	}

	public static function find_by_token( $token ) {
		global $wpdb;
		$table = wanaan_so_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE token = %s", (string) $token )
		);
	}

	public static function find_by_woo_order_id( $woo_order_id ) {
		global $wpdb;
		$table = wanaan_so_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE woo_order_id = %d", absint( $woo_order_id ) )
		);
	}

	public static function find( $id ) {
		global $wpdb;
		$table = wanaan_so_table();
		return $wpdb->get_row(
			$wpdb->prepare( "SELECT * FROM {$table} WHERE id = %d", absint( $id ) )
		);
	}

	/**
	 * @param array $fields Column => value.
	 */
	public static function update( $id, array $fields ) {
		global $wpdb;
		$fields['updated_at'] = current_time( 'mysql' );
		$wpdb->update( wanaan_so_table(), $fields, array( 'id' => absint( $id ) ) );
	}

	/**
	 * Record the customer's consent once, with a UTC timestamp, IP and user
	 * agent — the audit trail that they accepted the terms before paying.
	 */
	public static function record_consent( $id, $ip, $ua ) {
		self::update(
			$id,
			array(
				'consent_at' => gmdate( 'Y-m-d H:i:s' ),
				'consent_ip' => substr( (string) $ip, 0, 64 ),
				'consent_ua' => substr( (string) $ua, 0, 500 ),
			)
		);
	}

	/**
	 * The most recent rows for the admin list.
	 *
	 * @return array
	 */
	public static function recent( $limit = 100 ) {
		global $wpdb;
		$table = wanaan_so_table();
		return $wpdb->get_results(
			$wpdb->prepare( "SELECT * FROM {$table} ORDER BY id DESC LIMIT %d", absint( $limit ) )
		);
	}
}
