<?php
/**
 * Plugin Name:       Wanaan Web Booking
 * Description:       Sends WooCommerce car-rental bookings to the Wanaan ERP, signed and once each.
 * Version:           1.0.0
 * Author:            Wanaan
 * License:           GPL-2.0+
 * Text Domain:       wanaan-web-booking
 *
 * Replaces woocom-integ-wanaan. Three things are different, and each one fixes
 * something that was going wrong:
 *
 *  1. It sends when the ORDER IS PLACED, not when the customer happens to see
 *     the thank-you page. The old plugin hooked `woocommerce_thankyou`, so a
 *     customer who paid and closed the tab never reached the ERP at all — and
 *     one who refreshed that page sent the booking again, which is why a single
 *     order appears nine times over on the old system's pending list.
 *
 *  2. Every line item carries a reference of its own ({order}-{item}), so a
 *     delivery that does arrive twice updates one booking instead of making
 *     another.
 *
 *  3. It signs with a shared secret held in wp-config.php, not a username and
 *     password written into this file. The old plugin shipped its credentials
 *     inside the zip; anybody holding a copy could post bookings.
 *
 * wp-config.php needs:
 *
 *     define( 'WANAAN_ERP_URL',       'https://erp.wanaan-bh.com/rental/web-booking' );
 *     define( 'WANAAN_ERP_SECRET',    '…the secret generated in Settings → Web Bookings…' );
 *     define( 'WANAAN_ERP_WORKSPACE', 7 );
 */

if ( ! defined( 'WPINC' ) ) {
	die;
}

/**
 * Fired once, server-side, the moment the order exists — whether or not the
 * customer ever comes back to the site.
 */
add_action( 'woocommerce_checkout_order_processed', 'wanaan_wb_send_order', 10, 1 );

/**
 * A second chance for orders that arrive by another road (a bank redirect that
 * completes later, an order created in wp-admin). Sending twice is safe: the
 * ERP keys on the reference and updates rather than duplicating.
 */
add_action( 'woocommerce_order_status_processing', 'wanaan_wb_send_order', 10, 1 );
add_action( 'woocommerce_order_status_completed', 'wanaan_wb_send_order', 10, 1 );

function wanaan_wb_send_order( $order_id ) {
	if ( ! $order_id || ! function_exists( 'wc_get_order' ) ) {
		return;
	}

	$order = wc_get_order( $order_id );
	if ( ! $order ) {
		return;
	}

	foreach ( wanaan_wb_map_order( $order ) as $payload ) {
		wanaan_wb_post( $payload );
	}
}

/**
 * One booking per line item, in the field names the ERP reads.
 *
 * The names are deliberately those the previous integration used, so nothing
 * the website already collects is lost in the change of plugin. `source_reference`
 * and `ws` are the only additions.
 *
 * @return array<int, array<string, mixed>>
 */
function wanaan_wb_map_order( $order ) {
	$billing = $order->get_data()['billing'];
	$paid    = $order->is_paid() ? 'paid' : '';
	$result  = array();

	foreach ( $order->get_items() as $item_id => $item ) {
		$product = $item->get_product();

		// Pickup and return come from the booking product's own options, and
		// the site has used more than one naming over the years. Each is tried
		// in turn rather than assuming today's spelling.
		$pickup_location  = wanaan_wb_meta( $item, array( 'Pickup Locations', 'Pickup Location' ) );
		$dropoff_location = wanaan_wb_meta( $item, array( 'Return Locations', 'Dropoff Location' ) );
		$pickup_time      = wanaan_wb_meta( $item, array( 'Pickup Date&amp;Time', 'Pickup Date & Time' ) );
		$dropoff_time     = wanaan_wb_meta( $item, array( 'Return Date&amp;Time', 'Dropoff Date & Time' ) );

		// The Extra Product Options plugin keeps its answers somewhere else
		// again, and when it is in use those are the ones the customer saw.
		$epo = $item->get_meta( '_tmcartepo_data' );
		if ( is_array( $epo ) ) {
			$fields = array();
			foreach ( $epo as $option ) {
				if ( isset( $option['name'] ) ) {
					$fields[ $option['name'] ] = isset( $option['value'] ) ? $option['value'] : '';
				}
			}

			$pickup_location  = trim( wanaan_wb_pick( $fields, 'Pickup From' ) . ' ' . wanaan_wb_pick( $fields, 'Pickup Location' ) );
			$dropoff_location = wanaan_wb_pick( $fields, 'Dropoff Location' );
			$pickup_time      = trim( wanaan_wb_pick( $fields, 'Pickup Date' ) . ' ' . wanaan_wb_pick( $fields, 'Pickup Time' ) );
		}

		$result[] = array(
			// {order}-{line item}: one booking, one reference, for ever.
			'source_reference'           => $order->get_id() . '-' . $item_id,
			'ws'                         => defined( 'WANAAN_ERP_WORKSPACE' ) ? (int) WANAAN_ERP_WORKSPACE : null,

			'email'                      => $billing['email'],
			'phone'                      => $billing['phone'],
			'first_name'                 => $billing['first_name'],
			'last_name'                  => $billing['last_name'],
			'street_address'             => trim( $billing['address_1'] . ' ' . $billing['address_2'] ),
			'town'                       => $billing['city'],
			'booking_notes'              => $order->get_customer_note(),
			'driving_license_number'     => null,
			'driving_license_expiry_date' => null,

			'product'                    => 'O#' . $order->get_id() . ' P#' . ( $product ? $product->get_id() : 0 ) . ' ' . $item->get_name(),
			'product_quantity'           => (int) $item->get_quantity(),

			'pickup_location'            => html_entity_decode( wp_strip_all_tags( (string) $pickup_location ) ),
			'dropoff_location'           => html_entity_decode( wp_strip_all_tags( (string) $dropoff_location ) ),
			'pickup_datetime'            => wanaan_wb_date( $pickup_time ),
			'dropoff_datetime'           => wanaan_wb_date( $dropoff_time ),

			'sub_total'                  => (float) $item->get_subtotal(),
			'total'                      => (float) $item->get_total(),
			'payment_mode'               => trim( $order->get_payment_method_title() ),
			'payment_status'             => $paid,
		);
	}

	return $result;
}

/** The first of these meta keys the item actually has, flattened if it is a list. */
function wanaan_wb_meta( $item, array $keys ) {
	foreach ( $keys as $key ) {
		$value = $item->get_meta( $key );

		if ( is_array( $value ) ) {
			$value = implode( ' | ', $value );
		}

		$value = trim( (string) $value );

		if ( '' !== $value ) {
			return $value;
		}
	}

	return '';
}

function wanaan_wb_pick( array $fields, $key ) {
	return isset( $fields[ $key ] ) ? (string) $fields[ $key ] : '';
}

/**
 * The site writes dates day-first ("24/09/2026 18:00", sometimes with " at ").
 * Anything that will not parse is sent as null rather than as a wrong date —
 * an empty pick-up time is obvious to the desk, a wrong one is not.
 */
function wanaan_wb_date( $value ) {
	$value = trim( str_replace( ' at ', ' ', (string) $value ) );

	if ( '' === $value ) {
		return null;
	}

	$parsed = DateTime::createFromFormat( 'd/m/Y H:i', $value );
	if ( $parsed instanceof DateTime ) {
		return $parsed->format( 'Y-m-d H:i:s' );
	}

	$stamp = strtotime( $value );

	return $stamp ? gmdate( 'Y-m-d H:i:s', $stamp ) : null;
}

/**
 * Sign and post. The signature covers the timestamp AND the body, so a captured
 * request cannot be replayed with a fresh timestamp, and the secret itself never
 * travels.
 *
 * Failures are logged, never shown: the customer has finished checking out and
 * an integration problem is ours, not theirs.
 */
function wanaan_wb_post( array $payload ) {
	if ( ! defined( 'WANAAN_ERP_URL' ) || ! defined( 'WANAAN_ERP_SECRET' ) ) {
		error_log( 'wanaan-web-booking: WANAAN_ERP_URL / WANAAN_ERP_SECRET are not set in wp-config.php' );

		return;
	}

	$body      = wp_json_encode( $payload );
	$timestamp = (string) time();
	$signature = hash_hmac( 'sha256', $timestamp . '.' . $body, WANAAN_ERP_SECRET );

	$response = wp_remote_post(
		WANAAN_ERP_URL,
		array(
			'timeout' => 15,
			'headers' => array(
				'Content-Type'       => 'application/json',
				'X-Wanaan-Timestamp' => $timestamp,
				'X-Wanaan-Signature' => $signature,
			),
			'body'    => $body,
		)
	);

	if ( is_wp_error( $response ) ) {
		error_log( 'wanaan-web-booking: ' . $payload['source_reference'] . ' could not be sent — ' . $response->get_error_message() );

		return;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );

	if ( 200 !== $code ) {
		error_log( 'wanaan-web-booking: ' . $payload['source_reference'] . ' refused with ' . $code . ' — ' . wp_remote_retrieve_body( $response ) );
	}
}
