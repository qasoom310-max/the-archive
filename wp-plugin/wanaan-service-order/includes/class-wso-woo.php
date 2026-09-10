<?php
/**
 * WooCommerce glue.
 *
 * This site renders its front end with React, and the WooCommerce
 * "order-pay" page is not one of its routes — it comes up blank. So instead of
 * building an order and sending the customer to order-pay, we do what the normal
 * store does and Tap already handles: drop a single "Limousine Service" line
 * into the cart and send the customer to the real /checkout/ page. The order is
 * then created by WooCommerce itself; we tag it with the service-order id so the
 * paid-callback can find its way home.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Woo {

	const ORDER_META_ROW   = '_wanaan_service_order_id';
	const ORDER_META_TOKEN = '_wanaan_token';

	/** Cart-item keys carrying our data through to the order. */
	const CART_ROW    = 'wanaan_so_row';
	const CART_TOKEN  = 'wanaan_so_token';
	const CART_AMOUNT = 'wanaan_so_amount';

	public static function init() {
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'on_paid' ) );
		// Belt-and-braces: gateways that jump straight to a paid status without
		// firing payment_complete still reach us here.
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'on_paid' ) );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'on_paid' ) );

		// Price our cart line at the billed amount.
		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'set_cart_item_price' ), 20 );
		// Copy our ids from the cart onto the order WooCommerce creates.
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'tag_order' ), 20, 2 );
		add_action( 'woocommerce_checkout_order_processed', array( __CLASS__, 'link_order' ), 20 );
		// Trim + prefill the checkout for a service-order payment.
		add_filter( 'woocommerce_checkout_fields', array( __CLASS__, 'simplify_checkout_fields' ) );
		add_filter( 'woocommerce_checkout_get_value', array( __CLASS__, 'prefill_checkout_value' ), 10, 2 );
		// Online payment only while our item is in the cart (hide "Pay Later").
		add_filter( 'woocommerce_available_payment_gateways', array( __CLASS__, 'restrict_gateways' ) );
	}

	private static function woo_ready() {
		return function_exists( 'WC' ) && function_exists( 'wc_get_checkout_url' );
	}

	/**
	 * Put our "Limousine Service" line in the cart and return the /checkout/ URL.
	 * Never throws — any failure comes back as a WP_Error the page turns into a
	 * friendly notice.
	 *
	 * @param object $row
	 * @return string|WP_Error
	 */
	public static function checkout_url_for( $row ) {
		if ( ! self::woo_ready() ) {
			return new WP_Error( 'woo_missing', 'WooCommerce is not active.' );
		}

		try {
			// admin-post.php doesn't boot the cart/session — load it on demand.
			if ( function_exists( 'wc_load_cart' ) && ( ! WC()->cart || ! WC()->session ) ) {
				wc_load_cart();
			}
			if ( ! WC()->cart ) {
				return new WP_Error( 'cart_missing', 'Cart is unavailable.' );
			}

			$product_id = self::service_product_id();
			if ( $product_id <= 0 ) {
				return new WP_Error( 'product_missing', 'Service product could not be prepared.' );
			}

			// A payment link is a single-item transaction — start clean so the
			// customer never pays for something else left in a shared cart.
			WC()->cart->empty_cart();

			$added = WC()->cart->add_to_cart(
				$product_id,
				1,
				0,
				array(),
				array(
					self::CART_ROW    => (int) $row->id,
					self::CART_TOKEN  => (string) $row->token,
					self::CART_AMOUNT => (float) $row->amount,
				)
			);

			if ( ! $added ) {
				return new WP_Error( 'cart_add_failed', 'Could not add the service to the cart.' );
			}

			// Stash the customer details to prefill the checkout form.
			if ( WC()->session ) {
				WC()->session->set(
					'wanaan_so_customer',
					array(
						'name'  => (string) $row->customer_name,
						'email' => (string) $row->customer_email,
						'phone' => (string) $row->telephone,
					)
				);
			}

			return wc_get_checkout_url();
		} catch ( \Throwable $e ) {
			return new WP_Error( 'checkout_failed', $e->getMessage() );
		}
	}

	/**
	 * Charge our cart line at the ERP-billed amount (its catalogue price is 0).
	 *
	 * @param WC_Cart $cart
	 */
	public static function set_cart_item_price( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		if ( ! is_a( $cart, 'WC_Cart' ) ) {
			return;
		}
		foreach ( $cart->get_cart() as $item ) {
			if ( isset( $item[ self::CART_AMOUNT ], $item['data'] ) ) {
				$item['data']->set_price( (float) $item[ self::CART_AMOUNT ] );
			}
		}
	}

	/**
	 * Copy our service-order id/token from the cart onto the order at checkout.
	 *
	 * @param WC_Order $order
	 * @param array    $data
	 */
	public static function tag_order( $order, $data ) {
		if ( ! WC()->cart ) {
			return;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item[ self::CART_ROW ] ) ) {
				$order->update_meta_data( self::ORDER_META_ROW, (int) $item[ self::CART_ROW ] );
				if ( isset( $item[ self::CART_TOKEN ] ) ) {
					$order->update_meta_data( self::ORDER_META_TOKEN, (string) $item[ self::CART_TOKEN ] );
				}
				$order->set_created_via( 'wanaan-service-order' );
				break;
			}
		}
	}

	/**
	 * Record the WooCommerce order id back on our service-order row.
	 *
	 * @param int $order_id
	 */
	public static function link_order( $order_id ) {
		$order = wc_get_order( (int) $order_id );
		if ( ! $order ) {
			return;
		}
		$row_id = (int) $order->get_meta( self::ORDER_META_ROW );
		if ( $row_id > 0 ) {
			WSO_Repository::update( $row_id, array( 'woo_order_id' => (int) $order_id ) );
		}
	}

	/**
	 * On a service-order checkout, drop the address fields (a limousine payment
	 * needs none) — keep name, email, phone, country. Other checkouts untouched.
	 *
	 * @param array $fields
	 * @return array
	 */
	public static function simplify_checkout_fields( $fields ) {
		if ( ! self::cart_has_service() ) {
			return $fields;
		}
		foreach ( array( 'billing_company', 'billing_address_1', 'billing_address_2', 'billing_city', 'billing_state', 'billing_postcode' ) as $key ) {
			unset( $fields['billing'][ $key ] );
		}
		return $fields;
	}

	/**
	 * Prefill the checkout billing fields from the stashed customer details.
	 *
	 * @param mixed  $value
	 * @param string $input
	 * @return mixed
	 */
	public static function prefill_checkout_value( $value, $input ) {
		if ( null !== $value && '' !== $value ) {
			return $value;
		}
		if ( ! self::cart_has_service() || ! WC()->session ) {
			return $value;
		}
		$customer = WC()->session->get( 'wanaan_so_customer' );
		if ( ! is_array( $customer ) ) {
			return $value;
		}

		$name  = trim( (string) ( $customer['name'] ?? '' ) );
		$parts = '' !== $name ? preg_split( '/\s+/', $name, 2 ) : array( '', '' );

		switch ( $input ) {
			case 'billing_first_name':
				return $parts[0];
			case 'billing_last_name':
				return ! empty( $parts[1] ) ? $parts[1] : $parts[0];
			case 'billing_email':
				return (string) ( $customer['email'] ?? '' );
			case 'billing_phone':
				return (string) ( $customer['phone'] ?? '' );
		}
		return $value;
	}

	/**
	 * Online payment only (hide "Pay Later"/cash) while our service item is in
	 * the cart. Fails OPEN — if no Tap-like gateway is found, the full list is
	 * returned so the customer is never left unable to pay.
	 *
	 * @param array $gateways id => WC_Payment_Gateway
	 * @return array
	 */
	public static function restrict_gateways( $gateways ) {
		if ( ! is_array( $gateways ) || empty( $gateways ) ) {
			return $gateways;
		}
		if ( is_admin() && ! wp_doing_ajax() ) {
			return $gateways;
		}
		if ( ! self::cart_has_service() ) {
			return $gateways;
		}

		$online = array();
		foreach ( $gateways as $id => $gateway ) {
			$haystack = strtolower( (string) $id . ' ' . $gateway->get_title() );
			if ( false !== strpos( $haystack, 'tap' ) ) {
				$online[ $id ] = $gateway;
			}
		}

		return ! empty( $online ) ? $online : $gateways;
	}

	/** True when the current cart holds a service-order line. */
	private static function cart_has_service() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return false;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( isset( $item[ self::CART_ROW ] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * The id of the reusable, hidden "Limousine Service" product (created once,
	 * cached in an option). Virtual + catalog-hidden so it never shows in the
	 * shop; its price is 0 and each cart line overrides it. Returns 0 on failure.
	 *
	 * @return int
	 */
	private static function service_product_id() {
		$stored = (int) get_option( 'wanaan_so_product_id', 0 );
		if ( $stored > 0 && wc_get_product( $stored ) ) {
			return $stored;
		}

		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}

		try {
			$product = new WC_Product_Simple();
			$product->set_name( 'Limousine Service' );
			$product->set_status( 'publish' );
			$product->set_catalog_visibility( 'hidden' );
			$product->set_virtual( true );
			$product->set_sold_individually( true );
			$product->set_price( 0 );
			$product->set_regular_price( 0 );
			$product->set_tax_status( 'none' );
			$product->update_meta_data( '_wanaan_service_product', 'yes' );
			$id = (int) $product->save();
		} catch ( \Throwable $e ) {
			return 0;
		}

		if ( $id > 0 ) {
			update_option( 'wanaan_so_product_id', $id );
		}

		return $id;
	}

	/**
	 * A WooCommerce order tied to a service order has been paid. Mark the row
	 * paid once and hand the callback to the ERP off to WSO_Callback.
	 *
	 * @param int $order_id
	 */
	public static function on_paid( $order_id ) {
		if ( ! function_exists( 'wc_get_order' ) ) {
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
