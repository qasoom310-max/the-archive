<?php
/**
 * WooCommerce glue: turn a service order into a payable order (a single
 * "Limousine Service" fee line — no cart, no products), and when that order is
 * paid, mark our row and fire the callback to the ERP.
 *
 * Tap WebConnect is a normal WooCommerce gateway, so we never call Tap directly:
 * we build the order and send the customer to its checkout-order-pay page, where
 * Tap takes over. Payment success reaches us through the standard WooCommerce
 * hooks below.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Woo {

	const ORDER_META_ROW   = '_wanaan_service_order_id';
	const ORDER_META_TOKEN = '_wanaan_token';

	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ) );
		// Belt-and-braces: gateways that jump straight to a paid status without
		// firing payment_complete still reach us here.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ) );

		// On the pay page of an order WE created, offer online payment only —
		// hide "Pay Later" / cash / any non-online gateway. Normal store
		// checkouts are untouched.
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'restrict_gateways' ) );
	}

	/**
	 * Keep only online (Tap) gateways on our service-order pay page.
	 *
	 * Scope guard: this only fires on the checkout order-pay page for an order
	 * carrying our meta — every other checkout gets the full gateway list back
	 * unchanged. Fails OPEN: if no Tap-like gateway is found we return the
	 * original list rather than leave the customer with no way to pay.
	 *
	 * @param array $gateways id => WC_Payment_Gateway
	 * @return array
	 */
	public static function restrict_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || empty( $gateways ) || is_admin() ) {
			return $gateways;
		}
		if ( ! function_exists( 'is_checkout_pay_page' ) || ! is_checkout_pay_page() ) {
			return $gateways;
		}

		$order_id = absint( get_query_var( 'order-pay' ) );
		if ( $order_id <= 0 ) {
			return $gateways;
		}

		$order = wc_get_order( $order_id );
		if ( ! $order || '' === (string) $order->get_meta( self::ORDER_META_ROW ) ) {
			return $gateways; // not one of ours
		}

		// Keep gateways that look like Tap (id or title contains "tap").
		$online = array();
		foreach ( $gateways as $id => $gateway ) {
			$haystack = strtolower( (string) $id . ' ' . $gateway->get_title() );
			if ( false !== strpos( $haystack, 'tap' ) ) {
				$online[ $id ] = $gateway;
			}
		}

		return ! empty( $online ) ? $online : $gateways;
	}

	private static function woo_ready() {
		return function_exists( 'wc_create_order' );
	}

	/**
	 * The checkout-order-pay URL for a service-order row, creating the order the
	 * first time and reusing it while it is still unpaid.
	 *
	 * @param object $row
	 * @return string|WP_Error
	 */
	public static function checkout_url_for( $row ) {
		if ( ! self::woo_ready() ) {
			return new WP_Error( 'woo_missing', 'WooCommerce is not active.' );
		}

		$order = self::reusable_order( $row );

		if ( ! $order ) {
			$order = self::create_order( $row );
			if ( is_wp_error( $order ) ) {
				return $order;
			}
			WSO_Repository::update( $row->id, array( 'woo_order_id' => $order->get_id() ) );
		}

		return $order->get_checkout_payment_url();
	}

	/**
	 * The existing order for this row, but only if it is still awaiting payment
	 * — so a customer who bailed at Tap and came back reuses the same order
	 * instead of stacking duplicates.
	 *
	 * @param object $row
	 * @return WC_Order|null
	 */
	private static function reusable_order( $row ) {
		if ( empty( $row->woo_order_id ) ) {
			return null;
		}
		$order = wc_get_order( (int) $row->woo_order_id );
		if ( ! $order ) {
			return null;
		}
		return $order->needs_payment() ? $order : null;
	}

	/**
	 * Build a fresh order carrying a single fee line for the billed amount.
	 *
	 * @param object $row
	 * @return WC_Order|WP_Error
	 */
	private static function create_order( $row ) {
		try {
			$order = wc_create_order();

			$fee = new WC_Order_Item_Fee();
			$label = trim( 'Limousine Service ' . ( $row->confirmation_no ? '#' . $row->confirmation_no : '' ) );
			$fee->set_name( $label );
			$fee->set_amount( (float) $row->amount );
			$fee->set_total( (float) $row->amount );
			$fee->set_tax_status( 'none' );
			$order->add_item( $fee );

			if ( $row->currency ) {
				$order->set_currency( $row->currency );
			}
			if ( $row->customer_name ) {
				$order->set_billing_first_name( $row->customer_name );
			}
			if ( $row->telephone ) {
				$order->set_billing_phone( $row->telephone );
			}

			$order->update_meta_data( self::ORDER_META_ROW, (int) $row->id );
			$order->update_meta_data( self::ORDER_META_TOKEN, (string) $row->token );
			$order->set_created_via( 'wanaan-service-order' );

			$order->calculate_totals();
			$order->save();

			return $order;
		} catch ( Exception $e ) {
			return new WP_Error( 'woo_create_failed', $e->getMessage() );
		}
	}

	/**
	 * A WooCommerce order tied to a service order has been paid. Mark the row
	 * paid once and hand the callback to the ERP off to WSO_Callback.
	 *
	 * @param int $order_id
	 */
	public static function on_paid( $order_id ) {
		if ( ! self::woo_ready() ) {
			return;
		}
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}

		$row_id = (int) $order->get_meta( self::ORDER_META_ROW );
		if ( $row_id <= 0 ) {
			$row = WSO_Repository::find_by_woo_order_id( (int) $order_id );
			if ( ! $row ) {
				return; // not one of ours
			}
			$row_id = (int) $row->id;
		} else {
			$row = WSO_Repository::find( $row_id );
			if ( ! $row ) {
				return;
			}
		}

		if ( 'paid' === $row->status ) {
			return; // already handled — idempotent
		}

		$txn = $order->get_transaction_id();
		if ( '' === $txn ) {
			$txn = 'wc_' . $order->get_id();
		}

		WSO_Repository::update(
			$row_id,
			array(
				'status'          => 'paid',
				'transaction_ref' => substr( (string) $txn, 0, 191 ),
				'paid_at'         => current_time( 'mysql' ),
			)
		);

		WSO_Callback::send( $row_id );
	}
}
