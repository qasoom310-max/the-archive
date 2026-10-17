<?php
/**
 * [wanaan_border_offers] — the search box.
 *
 * Attributes (all optional):
 *   lang  = auto | en | ar   (auto follows the site language)
 *   theme = light | dark
 *   from  = SA | BH          (pick-up country shown first, default SA)
 *   to    = BH | SA          (default BH)
 *   trip  = round | oneway   (default round)
 *
 * The box is drawn by assets/wbo-app.js from the settings printed here; the
 * hours come from /wp-json/wanaan/v1/border-offers.
 */

defined( 'ABSPATH' ) || exit;

final class WBO_Shortcode {

	private static $count = 0;

	public static function init() {
		add_shortcode( 'wanaan_border_offers', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		wp_register_style( 'wbo', WBO_URL . 'assets/wbo.css', array(), WBO_VERSION );
		wp_register_script( 'wbo-core', WBO_URL . 'assets/wbo-core.js', array(), WBO_VERSION, true );
		wp_register_script( 'wbo-app', WBO_URL . 'assets/wbo-app.js', array( 'wbo-core' ), WBO_VERSION, true );
	}

	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'lang'  => 'auto',
				'theme' => 'light',
				'from'  => 'SA',
				'to'    => 'BH',
				'trip'  => 'round',
			),
			is_array( $atts ) ? $atts : array(),
			'wanaan_border_offers'
		);

		$lang = in_array( $atts['lang'], array( 'en', 'ar' ), true )
			? $atts['lang']
			: ( 0 === strpos( determine_locale(), 'ar' ) ? 'ar' : 'en' );

		$from = in_array( strtoupper( $atts['from'] ), WBO_Query::COUNTRIES, true ) ? strtoupper( $atts['from'] ) : 'SA';
		$to   = in_array( strtoupper( $atts['to'] ), WBO_Query::COUNTRIES, true ) ? strtoupper( $atts['to'] ) : 'BH';
		if ( $from === $to ) {
			$to = 'SA' === $from ? 'BH' : 'SA';
		}

		$config = array(
			'endpoint'  => esc_url_raw( rest_url( 'wanaan/v1/border-offers' ) ),
			'lang'      => $lang,
			'from'      => $from,
			'to'        => $to,
			'trip'      => 'oneway' === $atts['trip'] ? 'oneway' : 'round',
			'today'     => WBO_Client::today(),
			'maxDays'   => WBO_Query::MAX_DAYS_AHEAD,
			'timezone'  => WBO_Client::TIMEZONE,
			'countries' => WBO_Strings::countries( $lang ),
			'strings'   => WBO_Strings::all( $lang ),
			'whatsapp'  => WBO_Client::fallback_whatsapp(),
		);

		wp_enqueue_style( 'wbo' );
		wp_enqueue_script( 'wbo-app' );

		++self::$count;
		$id    = 'wbo-' . self::$count;
		$theme = 'dark' === $atts['theme'] ? 'dark' : 'light';

		return sprintf(
			'<div id="%1$s" class="wbo wbo--%2$s" dir="%3$s" lang="%4$s" data-wbo="%5$s"><noscript>%6$s</noscript></div>',
			esc_attr( $id ),
			esc_attr( $theme ),
			'ar' === $lang ? 'rtl' : 'ltr',
			esc_attr( $lang ),
			esc_attr( wp_json_encode( $config ) ),
			esc_html( $config['strings']['error_down'] )
		);
	}
}
