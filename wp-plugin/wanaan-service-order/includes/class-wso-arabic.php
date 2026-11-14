<?php
/**
 * The trip details in Arabic, for a reader whose phone or browser asks for
 * Arabic.
 *
 * The checkout and the order-received page are English pages; there is no
 * Arabic checkout on this site. An Arabic phone therefore ran them through the
 * browser's own translator, which got the words wrong ("Drop off" came out as
 * "dozed off") and the order backwards: WooCommerce floats each label to the
 * LEFT, so in Arabic the value read first and the label last.
 *
 * So we print the trip ourselves: Arabic labels, right-to-left, label first,
 * and marked translate="no" so the browser leaves it alone. Values stay as the
 * office typed them (places, names, phone numbers), apart from the date. The
 * stored order meta keeps its English keys, so the admin screen and anything
 * reading the order are unchanged.
 */

defined( 'ABSPATH' ) || exit;

class WSO_Arabic {

	/** The customer's language at checkout, for their emails later. */
	const ORDER_META_LANG = '_wanaan_lang';

	/** English trip label (the stored meta key) => Arabic. */
	const LABELS = array(
		'Trip no.'          => 'رقم الرحلة',
		'Booking no.'       => 'رقم الحجز',
		'Date'              => 'التاريخ',
		'Pick-up time'      => 'وقت الاستلام',
		'Pick up'           => 'مكان الانطلاق',
		'Drop off'          => 'مكان الوصول',
		'Vehicle'           => 'العربة',
		'Flight no.'        => 'رقم الرحلة الجوية',
		'Customer'          => 'العميل',
		'Telephone'         => 'رقم الهاتف',
		'Passenger'         => 'الراكب',
		'Passenger contact' => 'هاتف الراكب',
		'Remarks'           => 'ملاحظات',
		// A Rent A Car order.
		'Agreement no.'     => 'رقم العقد',
		'Pick-up date'      => 'تاريخ الاستلام',
		'Return date'       => 'تاريخ الإرجاع',
		'Car'               => 'السيارة',
		'Delivery to'       => 'التوصيل إلى',
	);

	/** Labels whose value is a date, shown in Arabic for an Arabic reader. */
	const DATE_KEYS = array( 'Date', 'Pick-up date', 'Return date' );

	const DAYS = array( 'الأحد', 'الاثنين', 'الثلاثاء', 'الأربعاء', 'الخميس', 'الجمعة', 'السبت' );

	const MONTHS = array( 1 => 'يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر' );

	/** Set while a WooCommerce email renders its items: 'ar' or 'en'. */
	private static $email_lang = null;

	public static function init() {
		add_filter( 'woocommerce_display_item_meta', array( __CLASS__, 'item_meta' ), 20, 3 );
		add_filter( 'woocommerce_order_item_name', array( __CLASS__, 'item_name' ), 20, 2 );
		add_filter( 'woocommerce_email_order_items_args', array( __CLASS__, 'email_starts' ), 20 );
		add_action( 'woocommerce_email_after_order_table', array( __CLASS__, 'email_ends' ), 20 );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'checkout_style' ), 30 );
	}

	/**
	 * Is the person this is for reading Arabic? In an email: the customer's
	 * language saved at checkout (the office's copy stays English). On a page:
	 * the Arabic version of the site, or a browser whose first language is
	 * Arabic.
	 */
	public static function reader_is_arabic() {
		if ( null !== self::$email_lang ) {
			return 'ar' === self::$email_lang;
		}
		if ( function_exists( 'pll_current_language' ) && 'ar' === pll_current_language() ) {
			return true;
		}

		$accept = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? strtolower( (string) $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) : '';
		$first  = trim( (string) strtok( $accept, ',;' ) );

		return 'ar' === substr( $first, 0, 2 );
	}

	/** The Arabic label for a stored trip key, or the key itself. */
	public static function label( $key ) {
		return isset( self::LABELS[ $key ] ) ? self::LABELS[ $key ] : $key;
	}

	/** "Fri 6 Nov 2026" -> "الجمعة 6 نوفمبر 2026"; anything unreadable as it is. */
	public static function date( $value ) {
		$time = strtotime( (string) $value );
		if ( false === $time ) {
			return (string) $value;
		}

		return self::DAYS[ (int) gmdate( 'w', $time ) ] . ' ' . gmdate( 'j', $time ) . ' '
			. self::MONTHS[ (int) gmdate( 'n', $time ) ] . ' ' . gmdate( 'Y', $time );
	}

	/**
	 * Arabic text reads right-to-left; anything else (a phone number, a hotel
	 * typed in English) left-to-right, so "+965 6996 6012" keeps its "+" in
	 * front. "auto" would not do: a number has no letter to decide by and
	 * would take the list's right-to-left.
	 */
	public static function direction( $value ) {
		return preg_match( '/\p{Arabic}/u', (string) $value ) ? 'rtl' : 'ltr';
	}

	/** A line of an order that came through a payment link. */
	private static function is_service_item( $item ) {
		if ( ! is_object( $item ) || ! method_exists( $item, 'get_order' ) ) {
			return false;
		}
		$order = $item->get_order();

		return $order && (int) $order->get_meta( WSO_Woo::ORDER_META_ROW ) > 0;
	}

	/**
	 * The trip under the order line (order-received page, My account, emails),
	 * in Arabic when that is what the reader reads.
	 *
	 * @param string $html
	 * @param object $item
	 * @param array  $args
	 * @return string
	 */
	public static function item_meta( $html, $item, $args ) {
		if ( ! self::reader_is_arabic() || ! self::is_service_item( $item ) ) {
			return $html;
		}

		$rows = array();
		foreach ( $item->get_formatted_meta_data() as $meta ) {
			$key   = (string) $meta->key;
			$value = trim( wp_strip_all_tags( (string) $meta->display_value ) );
			if ( '' === $value ) {
				continue;
			}
			if ( in_array( $key, self::DATE_KEYS, true ) ) {
				$value = self::date( $value );
			}
			$label = isset( self::LABELS[ $key ] ) ? self::LABELS[ $key ] : wp_strip_all_tags( (string) $meta->display_key );

			// The label is not given WooCommerce's label class: its stylesheet
			// floats that to the left, which is what reversed the line.
			$rows[] = '<li style="list-style:none;margin:0 0 4px;padding:0;text-align:right">'
				. '<strong style="float:none;display:inline;margin:0 0 0 .25em">' . esc_html( $label ) . ':</strong> '
				. '<bdi dir="' . self::direction( $value ) . '">' . esc_html( $value ) . '</bdi></li>';
		}
		if ( ! $rows ) {
			return $html;
		}

		return '<ul class="wc-item-meta wso-trip notranslate" dir="rtl" lang="ar" translate="no"'
			. ' style="direction:rtl;text-align:right;margin:.5em 0 0;padding:0">'
			. implode( '', $rows ) . '</ul>';
	}

	/**
	 * "Limousine Service · Trip 26115" in Arabic for an Arabic reader.
	 *
	 * @param string $name
	 * @param object $item
	 * @return string
	 */
	public static function item_name( $name, $item ) {
		if ( ! self::reader_is_arabic() || ! self::is_service_item( $item ) ) {
			return $name;
		}

		return str_replace( array( 'Limousine Service', '· Trip ', 'Car Rental' ), array( 'خدمة الليموزين', '· رحلة ', 'تأجير سيارة' ), (string) $name );
	}

	/**
	 * @param array $args
	 * @return array
	 */
	public static function email_starts( $args ) {
		$order = isset( $args['order'] ) ? $args['order'] : null;
		$ar    = empty( $args['sent_to_admin'] ) && $order && 'ar' === $order->get_meta( self::ORDER_META_LANG );

		self::$email_lang = $ar ? 'ar' : 'en';

		return $args;
	}

	public static function email_ends() {
		self::$email_lang = null;
	}

	/**
	 * On the checkout, the trip sits under the line as label/value pairs that
	 * WooCommerce's stylesheet floats left. For an Arabic reader, turn them to
	 * read right-to-left, label first.
	 */
	public static function checkout_style() {
		if ( ! function_exists( 'is_checkout' ) || ! is_checkout() || ! self::reader_is_arabic() ) {
			return;
		}
		if ( ! function_exists( 'WC' ) || ! WC()->cart ) {
			return;
		}

		$ours = false;
		foreach ( WC()->cart->get_cart() as $cart_item ) {
			if ( isset( $cart_item[ WSO_Woo::CART_ROW ] ) ) {
				$ours = true;
				break;
			}
		}
		if ( ! $ours ) {
			return;
		}

		wp_register_style( 'wso-trip-ar', false, array(), WANAAN_SO_VERSION );
		wp_enqueue_style( 'wso-trip-ar' );
		wp_add_inline_style(
			'wso-trip-ar',
			'dl.variation,.wc-block-components-product-details{direction:rtl;text-align:right}'
			. 'dl.variation dt{float:right!important;clear:both!important;margin:0 0 0 .25em!important}'
			. 'dl.variation dd{margin:0!important}'
		);
	}
}
