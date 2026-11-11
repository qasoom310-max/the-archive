<?php
/**
 * Booking → cart → checkout.
 *
 * The widget already knows the fare. Rather than hand the customer to a product
 * page and hope its dropdowns produce the same number, the plugin puts the line
 * in the cart itself and sends them straight to checkout.
 *
 * The price is ALWAYS recalculated here, on the server, from the plugin's own
 * fare table. Nothing about the money comes from the link. A customer who edits
 * the URL changes which trip they asked for, never what it costs.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WNF_Cart {

	/** Query flag that turns a page load into a booking. */
	const FLAG = 'wnf_book';

	/** Key the booking rides under inside the cart item. */
	const KEY = 'wnf';

	/** Session key holding the customer's choice: 'deposit' or 'full'. */
	const PAY = 'wnf_pay';

	/**
	 * Services that leave Bahrain. A trip on one of these pays the "outside
	 * Bahrain" deposit, every other service the "inside Bahrain" one, whichever
	 * way it runs (Saudi to Bahrain still crosses the border).
	 */
	const OUTSIDE_BAHRAIN = array( 'ksa', 'ksa_bus' );

	public static function init() {
		// After WooCommerce has built the cart and the session on this request.
		add_action( 'wp_loaded', array( __CLASS__, 'handle' ), 30 );

		// Pay part now / pay in full: chosen at checkout, the rest in the car.
		add_action( 'woocommerce_review_order_before_payment', array( __CLASS__, 'payment_choice' ) );
		add_action( 'woocommerce_checkout_update_order_review', array( __CLASS__, 'remember_choice' ) );
		add_action( 'woocommerce_checkout_process', array( __CLASS__, 'require_choice' ) );
		add_action( 'woocommerce_review_order_after_order_total', array( __CLASS__, 'review_rows' ) );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'order_meta' ), 10, 2 );
		add_filter( 'woocommerce_get_order_item_totals', array( __CLASS__, 'order_totals' ), 10, 2 );
		// After a deposit plugin (Acowebs adds its own at 20): a booking's
		// deposit is decided here, never twice.
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'no_other_deposit' ), 30 );

		add_action( 'woocommerce_before_calculate_totals', array( __CLASS__, 'price' ), 20 );
		// Late, so anything another plugin added is already in the list.
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'show' ), 99, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'store' ), 10, 4 );
		add_filter( 'woocommerce_cart_item_name', array( __CLASS__, 'name' ), 10, 3 );
	}

	/* ---------------------------------------------------------------------
	 * The request
	 * ------------------------------------------------------------------ */

	/**
	 * Turn ?wnf_book=1&... into a cart line and a redirect to checkout.
	 */
	public static function handle() {
		if ( empty( $_GET[ self::FLAG ] ) ) { // phpcs:ignore WordPress.Security.NonceVerification
			return;
		}
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) ) {
			return;
		}
		// Some requests reach wp_loaded without a cart; ask for one.
		if ( is_null( WC()->cart ) && function_exists( 'wc_load_cart' ) ) {
			wc_load_cart();
		}
		if ( ! WC()->cart ) {
			return;
		}

		$args = self::read_request();
		$quote = self::quote( $args );

		if ( is_wp_error( $quote ) ) {
			self::bail( $quote->get_error_message() );
			return;
		}

		$product_id = self::product_id();
		if ( ! $product_id ) {
			self::bail( __( 'Online booking is not set up yet. Please message us on WhatsApp.', 'wanaan-fare-finder' ) );
			return;
		}

		/*
		 * One booking at a time. Clicking "Book this car" a second time replaces
		 * the first rather than stacking two fares on one order — an accidental
		 * double charge is far worse than making someone book twice on purpose.
		 */
		self::clear_bookings();

		// A new booking is a new choice: nothing carried over from the last one.
		if ( WC()->session ) {
			WC()->session->set( self::PAY, null );
		}

		$added = WC()->cart->add_to_cart(
			$product_id,
			1,
			0,
			array(),
			array( self::KEY => $quote )
		);

		if ( ! $added ) {
			self::bail( __( 'Sorry, we could not start that booking. Please message us on WhatsApp.', 'wanaan-fare-finder' ) );
			return;
		}

		wp_safe_redirect( wc_get_checkout_url() );
		exit;
	}

	/**
	 * Everything the link is allowed to say. Note what is absent: any price.
	 *
	 * @return array
	 */
	private static function read_request() {
		$get = function ( $key ) {
			// phpcs:ignore WordPress.Security.NonceVerification
			return isset( $_GET[ $key ] ) ? sanitize_text_field( wp_unslash( $_GET[ $key ] ) ) : '';
		};

		return array(
			'service'  => $get( 'svc' ),
			'option'   => $get( 'opt' ),
			'car'      => $get( 'car' ),
			'dir'      => $get( 'dir' ),
			'trip'     => $get( 'trip' ),
			'date'     => $get( 'date' ),
			'time'     => $get( 'time' ),
			'flight'   => $get( 'flight' ),
			'ftime'    => $get( 'ftime' ),
			'pickup'   => $get( 'pickup' ),
			'dropoff'  => $get( 'dropoff' ),
			'lang'     => 'ar' === $get( 'lang' ) ? 'ar' : 'en',
		);
	}

	/**
	 * Send the customer somewhere useful with a reason, never to a blank page.
	 *
	 * @param string $message Why the booking did not start.
	 */
	private static function bail( $message ) {
		if ( function_exists( 'wc_add_notice' ) ) {
			wc_add_notice( $message, 'error' );
		}
		$back = wp_get_referer();
		wp_safe_redirect( $back ? $back : home_url( '/' ) );
		exit;
	}

	/* ---------------------------------------------------------------------
	 * The money
	 * ------------------------------------------------------------------ */

	/**
	 * Price a trip from the plugin's own fare table.
	 *
	 * This mirrors the widget's arithmetic exactly — base, return factor, offer
	 * — but it is this copy that decides what the customer pays.
	 *
	 * @param array $args Sanitised request.
	 * @return array|WP_Error
	 */
	public static function quote( $args ) {
		$data = WNF_Pricing::get_payload();
		if ( empty( $data['services'] ) ) {
			return new WP_Error( 'wnf_no_data', __( 'Fares are unavailable right now.', 'wanaan-fare-finder' ) );
		}

		$service = null;
		foreach ( $data['services'] as $s ) {
			if ( isset( $s['id'] ) && $s['id'] === $args['service'] ) {
				$service = $s;
				break;
			}
		}
		if ( ! $service || ( isset( $service['active'] ) && ! $service['active'] ) ) {
			return new WP_Error( 'wnf_no_service', __( 'That service is not available.', 'wanaan-fare-finder' ) );
		}

		// The car must be one this service actually offers — no coaster on an
		// airport transfer because someone typed it into the address bar.
		if ( ! empty( $service['cars'] ) && ! in_array( $args['car'], (array) $service['cars'], true ) ) {
			return new WP_Error( 'wnf_no_car', __( 'That vehicle is not available for this service.', 'wanaan-fare-finder' ) );
		}

		$option = null;
		foreach ( (array) $service['options'] as $o ) {
			if ( isset( $o['code'] ) && $o['code'] === $args['option'] ) {
				$option = $o;
				break;
			}
		}
		if ( ! $option ) {
			return new WP_Error( 'wnf_no_option', __( 'That destination is not available.', 'wanaan-fare-finder' ) );
		}

		$base = isset( $option['prices'][ $args['car'] ] ) ? (float) $option['prices'][ $args['car'] ] : 0;
		if ( $base <= 0 ) {
			return new WP_Error( 'wnf_no_price', __( 'We do not have an online price for that trip. Please message us on WhatsApp.', 'wanaan-fare-finder' ) );
		}

		$fields = isset( $service['fields'] ) ? (array) $service['fields'] : array();
		$lang   = $args['lang'];

		/*
		 * A direction the service does not offer is a hand-edited link, not a
		 * customer. Refuse it rather than quietly booking the opposite trip.
		 */
		if ( in_array( 'direction', $fields, true ) && ! empty( $service['directions'] ) ) {
			$valid = false;
			foreach ( (array) $service['directions'] as $d ) {
				if ( isset( $d['v'] ) && $d['v'] === $args['dir'] ) {
					$valid = true;
					break;
				}
			}
			if ( ! $valid ) {
				return new WP_Error( 'wnf_no_dir', __( 'That direction is not available for this service.', 'wanaan-fare-finder' ) );
			}
		}

		// Return trip.
		$is_return = in_array( 'trip', $fields, true ) && 'return' === $args['trip'];
		$factor    = isset( $service['return_factor'] ) ? (float) $service['return_factor'] : 2;
		if ( $factor <= 0 ) {
			$factor = 2;
		}
		$full = $is_return ? $base * $factor : $base;

		/*
		 * Offer, decided against the DATE OF THIS TRIP and the car chosen —
		 * the same rule the widget displays, run again here because this is
		 * the number the customer actually pays.
		 *
		 * It has to be re-run rather than trusted: everything in the booking
		 * link is typed by the browser, so a customer who noticed a National
		 * Day discount could otherwise hand-edit the date to 30 September and
		 * keep the 25% off. WNF_Pricing::offer_applies() reads the window from
		 * the fare table, which the browser cannot touch.
		 */
		$offer   = isset( $service['offer'] ) ? $service['offer'] : array();
		$percent = WNF_Pricing::offer_applies( $offer, $args['date'], $args['car'] );
		$paid    = $percent > 0 ? $full * ( 1 - $percent / 100 ) : $full;

		$paid = round( $paid, 3 );
		$full = round( $full, 3 );

		// Minimum notice, checked here as well as in the browser.
		$lead = isset( $data['lead_hours'] ) ? (int) $data['lead_hours'] : 0;
		$when = self::trip_time( $args, $fields );
		if ( $lead > 0 && $when && $when < ( current_time( 'timestamp' ) + $lead * HOUR_IN_SECONDS ) ) { // phpcs:ignore WordPress.DateTime.CurrentTimeTimestamp
			return new WP_Error(
				'wnf_too_soon',
				sprintf(
					/* translators: %d: hours of notice required. */
					__( 'We need %d hours\' notice to book online. Please message us on WhatsApp and we will confirm availability.', 'wanaan-fare-finder' ),
					$lead
				)
			);
		}

		/*
		 * Keep only what this service actually asks for. An airport transfer has
		 * no pick-up time, so a "time" in the link is noise at best and a
		 * contradiction on the driver's job sheet at worst.
		 */
		$asks = function ( $field ) use ( $fields ) {
			return in_array( $field, $fields, true );
		};

		return array(
			'service'      => $args['service'],
			'service_name' => self::label( $service, '', $lang ),
			'option'       => $args['option'],
			'option_name'  => self::label( $option, '', $lang ),
			'car'          => $args['car'],
			'car_name'     => self::car_name( $data, $args['car'], $lang ),
			'dir'          => $asks( 'direction' ) ? $args['dir'] : '',
			'dir_name'     => $asks( 'direction' ) ? self::dir_name( $service, $args['dir'], $lang ) : '',
			'trip'         => $asks( 'trip' ) ? ( $is_return ? 'return' : 'oneway' ) : '',
			'date'         => $asks( 'date' ) ? $args['date'] : '',
			'time'         => $asks( 'time' ) ? $args['time'] : '',
			'flight'       => $asks( 'flight' ) ? $args['flight'] : '',
			'ftime'        => $asks( 'flight' ) ? $args['ftime'] : '',
			'pickup'       => $asks( 'pickup' ) ? $args['pickup'] : '',
			'dropoff'      => $asks( 'dropoff' ) ? $args['dropoff'] : '',
			'lang'         => $lang,
			'currency'     => isset( $data['currency'] ) ? $data['currency'] : 'BHD',
			'price_full'   => $full,
			'price'        => $paid,
			'offer'        => $percent,
			// What part of the fare may be paid now, the rest in the car.
			// 0 = no choice offered for this trip: it is paid in full.
			'deposit'      => self::deposit_percent( $args['service'] ),
		);
	}

	/**
	 * The deposit for a service, from the plugin's settings: one figure for a
	 * trip inside Bahrain, another for one that leaves it.
	 *
	 * @param string $service Service id.
	 * @return float 0 when no deposit is offered.
	 */
	public static function deposit_percent( $service ) {
		$settings = wnf_settings();
		$outside  = in_array( $service, (array) apply_filters( 'wnf_outside_bahrain_services', self::OUTSIDE_BAHRAIN ), true );
		$percent  = (float) ( $outside ? $settings['deposit_outside'] : $settings['deposit_inside'] );

		return ( $percent > 0 && $percent < 100 ) ? $percent : 0.0;
	}

	/* ---------------------------------------------------------------------
	 * Pay part now, or in full
	 * ------------------------------------------------------------------ */

	/**
	 * The fare finder booking in the cart, or null.
	 *
	 * @return array|null
	 */
	private static function cart_booking() {
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return null;
		}
		foreach ( WC()->cart->get_cart() as $item ) {
			if ( ! empty( $item[ self::KEY ]['price'] ) ) {
				return $item[ self::KEY ];
			}
		}
		return null;
	}

	/**
	 * What the customer chose, or '' when they have not chosen yet.
	 *
	 * @return string 'deposit' | 'full' | ''
	 */
	private static function choice() {
		$choice = ( function_exists( 'WC' ) && WC()->session ) ? WC()->session->get( self::PAY ) : '';
		return in_array( $choice, array( 'deposit', 'full' ), true ) ? $choice : '';
	}

	/**
	 * The amounts for a booking: the fare, what is paid now, what is left for
	 * the car. A booking with no deposit, or not paying one, pays it all now.
	 *
	 * @param array  $b      Booking.
	 * @param string $choice 'deposit' | 'full' | ''.
	 * @return array{price: float, now: float, later: float, percent: float}
	 */
	public static function amounts( $b, $choice ) {
		$price   = (float) $b['price'];
		$percent = isset( $b['deposit'] ) ? (float) $b['deposit'] : 0.0;

		if ( 'deposit' !== $choice || $percent <= 0 ) {
			return array( 'price' => $price, 'now' => $price, 'later' => 0.0, 'percent' => 0.0 );
		}

		$decimals = function_exists( 'wc_get_price_decimals' ) ? wc_get_price_decimals() : 3;
		$now      = round( $price * $percent / 100, $decimals );

		return array( 'price' => $price, 'now' => $now, 'later' => round( $price - $now, 3 ), 'percent' => $percent );
	}

	/**
	 * The two choices, above the payment methods. Re-drawn by WooCommerce on
	 * every checkout refresh, so the ticked one comes from the session.
	 */
	public static function payment_choice() {
		$b = self::cart_booking();
		if ( ! $b || empty( $b['deposit'] ) ) {
			return;
		}

		$ar     = isset( $b['lang'] ) && 'ar' === $b['lang'];
		$choice = self::choice();
		$part   = self::amounts( $b, 'deposit' );
		$pct    = rtrim( rtrim( number_format( $part['percent'], 2, '.', '' ), '0' ), '.' ) . '%';

		$deposit_text = $ar
			/* translators: 1: percent, 2: amount now, 3: amount in the car. */
			? sprintf( 'ادفع %1$s الآن (%2$s)، والباقي (%3$s) عند ركوب السيارة', $pct, wc_price( $part['now'] ), wc_price( $part['later'] ) )
			: sprintf( 'Pay %1$s now (%2$s), the rest (%3$s) when you get in the car', $pct, wc_price( $part['now'] ), wc_price( $part['later'] ) );
		$full_text = $ar
			? sprintf( 'ادفع المبلغ كاملاً الآن (%s)', wc_price( $part['price'] ) )
			: sprintf( 'Pay the full amount now (%s)', wc_price( $part['price'] ) );
		?>
		<div class="wnf-pay-choice" <?php echo $ar ? 'dir="rtl"' : ''; ?> style="margin:0 0 1.5em;padding:1em 1.2em;border:1px solid #ddd;border-radius:6px">
			<p style="margin:0 0 .6em;font-weight:600"><?php echo esc_html( $ar ? 'كيف تريد الدفع؟' : 'How would you like to pay?' ); ?> <abbr class="required" title="required">*</abbr></p>
			<label style="display:block;margin:.4em 0;cursor:pointer">
				<input type="radio" name="wnf_pay" value="deposit" <?php checked( $choice, 'deposit' ); ?>>
				<?php echo wp_kses_post( $deposit_text ); ?>
			</label>
			<label style="display:block;margin:.4em 0;cursor:pointer">
				<input type="radio" name="wnf_pay" value="full" <?php checked( $choice, 'full' ); ?>>
				<?php echo wp_kses_post( $full_text ); ?>
			</label>
		</div>
		<script>
		( function () {
			if ( window.wnfPayBound || ! window.jQuery ) { return; }
			window.wnfPayBound = true;
			jQuery( document.body ).on( 'change', 'input[name="wnf_pay"]', function () {
				jQuery( document.body ).trigger( 'update_checkout' );
			} );
		} )();
		</script>
		<?php
	}

	/**
	 * Keep the choice when the checkout refreshes its totals.
	 *
	 * @param string $posted The checkout form, URL-encoded.
	 */
	public static function remember_choice( $posted ) {
		$fields = array();
		parse_str( (string) $posted, $fields );
		self::set_choice( isset( $fields['wnf_pay'] ) ? $fields['wnf_pay'] : '' );
	}

	/**
	 * Place order: a choice is required, and it is the one the totals use.
	 */
	public static function require_choice() {
		$b = self::cart_booking();
		if ( ! $b || empty( $b['deposit'] ) ) {
			return;
		}
		// phpcs:ignore WordPress.Security.NonceVerification -- WooCommerce checked the checkout nonce.
		$posted = isset( $_POST['wnf_pay'] ) ? sanitize_text_field( wp_unslash( $_POST['wnf_pay'] ) ) : '';
		self::set_choice( $posted );

		if ( '' === self::choice() ) {
			$ar = isset( $b['lang'] ) && 'ar' === $b['lang'];
			wc_add_notice( $ar ? 'يرجى اختيار طريقة الدفع: جزء الآن أو المبلغ كاملاً.' : 'Please choose how you would like to pay: part now, or the full amount.', 'error' );
		}
	}

	/**
	 * An amount as plain text with the shop's currency ("40.000 د.ب."), for
	 * line details that are stored and later escaped, where wc_price()'s HTML
	 * would print as entities.
	 *
	 * @param float $amount Amount.
	 * @return string
	 */
	private static function plain_price( $amount ) {
		return html_entity_decode( wp_strip_all_tags( wc_price( $amount ) ), ENT_QUOTES, 'UTF-8' );
	}

	/** @param string $value Posted value. */
	private static function set_choice( $value ) {
		if ( function_exists( 'WC' ) && WC()->session ) {
			WC()->session->set( self::PAY, in_array( $value, array( 'deposit', 'full' ), true ) ? $value : null );
		}
	}

	/**
	 * Under the total on the checkout: the fare and what is left for the car,
	 * so a deposit never reads as the price of the trip.
	 */
	public static function review_rows() {
		$b = self::cart_booking();
		if ( ! $b ) {
			return;
		}
		$a = self::amounts( $b, self::choice() );
		if ( $a['later'] <= 0 ) {
			return;
		}
		$ar = isset( $b['lang'] ) && 'ar' === $b['lang'];
		printf(
			'<tr class="wnf-trip-price"><th>%1$s</th><td>%2$s</td></tr><tr class="wnf-pay-later"><th>%3$s</th><td>%4$s</td></tr>',
			esc_html( $ar ? 'سعر الرحلة' : 'Trip price' ),
			wp_kses_post( wc_price( $a['price'] ) ),
			esc_html( $ar ? 'المتبقي عند ركوب السيارة' : 'To pay in the car' ),
			wp_kses_post( wc_price( $a['later'] ) )
		);
	}

	/**
	 * The amounts on the order, for the emails and the thank-you page.
	 *
	 * @param WC_Order $order Order.
	 * @param array    $data  Posted data.
	 */
	public static function order_meta( $order, $data ) {
		unset( $data );
		$b = self::cart_booking();
		if ( ! $b ) {
			return;
		}
		$a = self::amounts( $b, self::choice() );
		$order->update_meta_data( '_wnf_trip_price', $a['price'] );
		$order->update_meta_data( '_wnf_pay_in_car', $a['later'] );
		$order->update_meta_data( '_wnf_lang', isset( $b['lang'] ) ? $b['lang'] : 'en' );
	}

	/**
	 * Order totals in emails and on the thank-you page: the trip price and
	 * what the driver collects, under the amount paid.
	 *
	 * @param array    $rows  Total rows.
	 * @param WC_Order $order Order.
	 * @return array
	 */
	public static function order_totals( $rows, $order ) {
		$later = (float) $order->get_meta( '_wnf_pay_in_car' );
		if ( $later <= 0 ) {
			return $rows;
		}
		$ar  = 'ar' === $order->get_meta( '_wnf_lang' );
		$out = array();
		foreach ( $rows as $key => $row ) {
			$out[ $key ] = $row;
			if ( 'order_total' === $key ) {
				$out['wnf_trip_price'] = array(
					'label' => $ar ? 'سعر الرحلة:' : 'Trip price:',
					'value' => wc_price( (float) $order->get_meta( '_wnf_trip_price' ), array( 'currency' => $order->get_currency() ) ),
				);
				$out['wnf_pay_in_car'] = array(
					'label' => $ar ? 'المتبقي عند ركوب السيارة:' : 'To pay in the car:',
					'value' => wc_price( $later, array( 'currency' => $order->get_currency() ) ),
				);
			}
		}
		return $out;
	}

	/**
	 * Another deposit plugin must not take its own deposit off a booking that
	 * already has one (the customer would pay a deposit of a deposit).
	 *
	 * @param array $data Cart item data.
	 * @return array
	 */
	public static function no_other_deposit( $data ) {
		if ( ! empty( $data[ self::KEY ] ) ) {
			unset( $data['awcdp_deposit'] );
		}
		return $data;
	}

	/**
	 * When the trip actually starts, as a timestamp, or 0 if we cannot tell.
	 *
	 * @param array $args   Sanitised request.
	 * @param array $fields Which inputs this service asks for.
	 * @return int
	 */
	private static function trip_time( $args, $fields ) {
		if ( ! $args['date'] || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $args['date'] ) ) {
			return 0;
		}
		$clock = in_array( 'flight', $fields, true ) ? $args['ftime'] : $args['time'];
		if ( ! $clock || ! preg_match( '/^\d{1,2}:\d{2}$/', $clock ) ) {
			$clock = '00:00';
		}
		$ts = strtotime( $args['date'] . ' ' . $clock );
		return $ts ? $ts : 0;
	}

	/* ---------------------------------------------------------------------
	 * Labels
	 * ------------------------------------------------------------------ */

	private static function label( $obj, $key, $lang ) {
		if ( '' === $key ) {
			if ( ! empty( $obj[ $lang ] ) ) {
				return (string) $obj[ $lang ];
			}
			return isset( $obj['en'] ) ? (string) $obj['en'] : '';
		}
		if ( ! empty( $obj[ $key . '_' . $lang ] ) ) {
			return (string) $obj[ $key . '_' . $lang ];
		}
		return isset( $obj[ $key . '_en' ] ) ? (string) $obj[ $key . '_en' ] : '';
	}

	private static function car_name( $data, $id, $lang ) {
		foreach ( (array) $data['cars'] as $car ) {
			if ( isset( $car['id'] ) && $car['id'] === $id ) {
				$name = self::label( $car, '', $lang );
				if ( ! empty( $car['model'] ) ) {
					$name .= ' (' . $car['model'] . ')';
				}
				return $name;
			}
		}
		return $id;
	}

	private static function dir_name( $service, $value, $lang ) {
		foreach ( (array) ( isset( $service['directions'] ) ? $service['directions'] : array() ) as $d ) {
			if ( isset( $d['v'] ) && $d['v'] === $value ) {
				return self::label( $d, '', $lang );
			}
		}
		return $value;
	}

	/**
	 * The trip, written out for a human: cart, checkout, email and order screen.
	 *
	 * @param array $b Booking.
	 * @return array label => value
	 */
	public static function details( $b ) {
		$ar    = ( isset( $b['lang'] ) && 'ar' === $b['lang'] );
		$rows  = array();
		$L     = function ( $en, $arabic ) use ( $ar ) {
			return $ar ? $arabic : $en;
		};

		$rows[ $L( 'Service', 'الخدمة' ) ] = $b['service_name'];

		$trip = array();
		if ( ! empty( $b['dir_name'] ) ) {
			$trip[] = $b['dir_name'];
		}
		if ( ! empty( $b['option_name'] ) ) {
			$trip[] = $b['option_name'];
		}
		if ( ! empty( $b['trip'] ) ) {
			$trip[] = 'return' === $b['trip'] ? $L( 'Return', 'ذهاب وعودة' ) : $L( 'One way', 'ذهاب فقط' );
		}
		if ( $trip ) {
			$rows[ $L( 'Trip', 'الرحلة' ) ] = implode( ' — ', $trip );
		}

		$rows[ $L( 'Vehicle', 'السيارة' ) ] = $b['car_name'];

		if ( ! empty( $b['date'] ) ) {
			$rows[ $L( 'Date', 'التاريخ' ) ] = $b['date'];
		}
		if ( ! empty( $b['flight'] ) ) {
			$rows[ $L( 'Flight number', 'رقم الرحلة' ) ] = $b['flight'];
		}
		if ( ! empty( $b['ftime'] ) ) {
			$rows[ 'to' === $b['dir'] ? $L( 'Departure time', 'وقت الإقلاع' ) : $L( 'Landing time', 'وقت الوصول' ) ] = $b['ftime'];
		}
		if ( ! empty( $b['time'] ) ) {
			$rows[ $L( 'Pick-up time', 'وقت الانطلاق' ) ] = $b['time'];
		}
		if ( ! empty( $b['pickup'] ) ) {
			$rows[ $L( 'Pick-up', 'مكان الانطلاق' ) ] = $b['pickup'];
		}
		if ( ! empty( $b['dropoff'] ) ) {
			$rows[ $L( 'Drop-off', 'الوجهة' ) ] = $b['dropoff'];
		}
		if ( ! empty( $b['offer'] ) ) {
			$rows[ $L( 'Offer applied', 'الخصم' ) ] = rtrim( rtrim( number_format( (float) $b['offer'], 2, '.', '' ), '0' ), '.' ) . '%';
		}

		return $rows;
	}

	/* ---------------------------------------------------------------------
	 * WooCommerce hooks
	 * ------------------------------------------------------------------ */

	/**
	 * The quoted fare is the price. Recalculated on every cart calculation so a
	 * stale session can never resurrect an old number.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function price( $cart ) {
		if ( is_admin() && ! wp_doing_ajax() ) {
			return;
		}
		$choice = self::choice();
		foreach ( $cart->get_cart() as $item ) {
			if ( empty( $item[ self::KEY ]['price'] ) ) {
				continue;
			}
			/*
			 * The line carries what is paid NOW. Tap builds its charge from the
			 * product lines, so a deposit has to be the line's price — a negative
			 * fee would be ignored and the full fare charged.
			 */
			$amounts = self::amounts( $item[ self::KEY ], $choice );
			$item['data']->set_price( $amounts['now'] );
		}
	}

	/**
	 * Show the trip under the line on cart and checkout.
	 *
	 * @param array $data Existing rows.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function show( $data, $item ) {
		if ( empty( $item[ self::KEY ] ) ) {
			return $data;
		}

		/*
		 * The add-ons plugin adds a blank row for a product that has no add-ons,
		 * which shows up under the booking as a lone ":". Ours is not a product
		 * with options any more, so drop empty rows on the way past.
		 */
		$data = array_values(
			array_filter(
				(array) $data,
				function ( $row ) {
					$key   = isset( $row['key'] ) ? trim( wp_strip_all_tags( (string) $row['key'] ) ) : '';
					$value = isset( $row['value'] ) ? trim( wp_strip_all_tags( (string) $row['value'] ) ) : '';
					$show  = isset( $row['display'] ) ? trim( wp_strip_all_tags( (string) $row['display'] ) ) : '';
					return '' !== $key || '' !== $value || '' !== $show;
				}
			)
		);

		foreach ( self::details( $item[ self::KEY ] ) as $label => $value ) {
			if ( '' === $value ) {
				continue;
			}
			$data[] = array(
				'key'     => $label,
				'value'   => $value,
				'display' => '',
			);
		}
		return $data;
	}

	/**
	 * Name the line after the trip, not after a generic booking product.
	 *
	 * @param string $name Current name.
	 * @param array  $item Cart item.
	 * @param string $key  Cart item key.
	 * @return string
	 */
	public static function name( $name, $item, $key ) {
		unset( $key );
		if ( empty( $item[ self::KEY ] ) ) {
			return $name;
		}
		$b = $item[ self::KEY ];
		return esc_html( $b['service_name'] . ' — ' . $b['car_name'] );
	}

	/**
	 * Persist the trip onto the order line so it survives into the email, the
	 * order screen and anything reading orders from the ERP.
	 *
	 * @param WC_Order_Item_Product $item   Line item.
	 * @param string                $key    Cart item key.
	 * @param array                 $values Cart item.
	 * @param WC_Order              $order  Order.
	 */
	public static function store( $item, $key, $values, $order ) {
		unset( $key, $order );
		if ( empty( $values[ self::KEY ] ) ) {
			return;
		}
		$b = $values[ self::KEY ];

		foreach ( self::details( $b ) as $label => $value ) {
			if ( '' !== $value ) {
				$item->add_meta_data( $label, $value );
			}
		}

		// How it is being paid, for the customer's email and the driver's sheet.
		$a          = self::amounts( $b, self::choice() );
		$ar         = isset( $b['lang'] ) && 'ar' === $b['lang'];
		$b['pay']   = $a['later'] > 0 ? 'deposit' : 'full';
		$b['paid_now']   = $a['now'];
		$b['pay_in_car'] = $a['later'];
		if ( $a['later'] > 0 ) {
			$item->add_meta_data( $ar ? 'سعر الرحلة' : 'Trip price', self::plain_price( $a['price'] ) );
			$item->add_meta_data( $ar ? 'المدفوع الآن' : 'Paid now', self::plain_price( $a['now'] ) );
			$item->add_meta_data( $ar ? 'المتبقي عند ركوب السيارة' : 'To pay in the car', self::plain_price( $a['later'] ) );
		}

		// Machine-readable copy, hidden from the customer, for the ERP.
		$item->add_meta_data( '_wnf_booking', wp_json_encode( $b ) );
		$item->set_name( $b['service_name'] . ' — ' . $b['car_name'] );
	}

	/* ---------------------------------------------------------------------
	 * The booking product
	 * ------------------------------------------------------------------ */

	/**
	 * The single hidden product every booking attaches to. Created on demand.
	 *
	 * @return int
	 */
	public static function product_id() {
		$settings = wnf_settings();
		$id       = isset( $settings['booking_product'] ) ? (int) $settings['booking_product'] : 0;

		if ( $id && 'product' === get_post_type( $id ) && 'trash' !== get_post_status( $id ) ) {
			self::ensure_no_vat( $id );
			return $id;
		}

		$id = self::create_product();
		if ( $id ) {
			$settings['booking_product'] = $id;
			update_option( WNF_OPT_SETTINGS, $settings );
		}
		return $id;
	}

	/**
	 * Make the booking product: virtual, hidden from the shop, price set per
	 * booking. It exists only so WooCommerce has something to sell.
	 *
	 * @return int 0 on failure.
	 */
	public static function create_product() {
		if ( ! class_exists( 'WC_Product_Simple' ) ) {
			return 0;
		}

		// Never make a second one.
		$found = get_posts(
			array(
				'post_type'      => 'product',
				'post_status'    => array( 'publish', 'private', 'draft' ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => '_wnf_booking_product', // phpcs:ignore WordPress.DB.SlowDBQuery
				'meta_value'     => '1',                    // phpcs:ignore WordPress.DB.SlowDBQuery
			)
		);
		if ( $found ) {
			return (int) $found[0];
		}

		$product = new WC_Product_Simple();
		$product->set_name( __( 'Wanaan Booking', 'wanaan-fare-finder' ) );
		$product->set_status( 'publish' );
		$product->set_catalog_visibility( 'hidden' );
		$product->set_virtual( true );
		$product->set_sold_individually( false );
		/*
		 * No VAT. Every service this widget sells comes with a driver — airport
		 * pick and drop, chauffeur, Saudi runs, city to city, buses. VAT applies
		 * to self-drive car rental, which is not booked through here. Qassim,
		 * 9 Sep 2026. The fare the customer was quoted is the fare he pays.
		 */
		$product->set_tax_status( 'none' );
		$product->set_regular_price( '0' );
		$product->set_price( '0' );
		$product->set_description( __( 'Trips booked through the Wanaan fare finder. The price of each booking is set by the fare finder; this product carries no price of its own.', 'wanaan-fare-finder' ) );
		$id = $product->save();

		if ( $id ) {
			update_post_meta( $id, '_wnf_booking_product', '1' );
		}
		return (int) $id;
	}

	/**
	 * Keep the booking product VAT-free, even if someone edits it by hand or it
	 * was created by an older version of this plugin that did not set it.
	 *
	 * @param int $id Product id.
	 */
	private static function ensure_no_vat( $id ) {
		if ( ! function_exists( 'wc_get_product' ) ) {
			return;
		}
		$product = wc_get_product( $id );
		if ( ! $product || 'none' === $product->get_tax_status() ) {
			return;
		}
		$product->set_tax_status( 'none' );
		$product->save();
	}

	/**
	 * Drop any booking already sitting in the cart.
	 */
	private static function clear_bookings() {
		foreach ( WC()->cart->get_cart() as $key => $item ) {
			if ( ! empty( $item[ self::KEY ] ) ) {
				WC()->cart->remove_cart_item( $key );
			}
		}
	}
}
