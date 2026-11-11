<?php
/**
 * [wanaan_fare_finder]
 *
 * Attributes:
 *   service   airport | chauffeur | ksa | all      (default: airport)
 *   lang      en | ar                              (default: site locale)
 *   theme     dark | light                         (default: dark)
 *   headline  overrides the default headline
 *   sub       overrides the default sub-line
 *
 * Assets load only on pages where this shortcode actually ran.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WNF_Shortcode {

	/** @var bool */
	private static $used = false;

	/** @var int */
	private static $count = 0;

	/** @var bool Did any widget on this page render in Arabic? */
	private static $rtl = false;

	public static function init() {
		add_shortcode( 'wanaan_fare_finder', array( __CLASS__, 'render' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
		add_action( 'wp_footer', array( __CLASS__, 'maybe_enqueue' ), 5 );
	}

	public static function register() {
		wp_register_style( 'wanaan-fare-finder', WNF_URL . 'assets/wanaan-fare-finder.css', array(), WNF_VERSION );
		wp_register_script( 'wanaan-fare-finder', WNF_URL . 'assets/wanaan-fare-finder.js', array(), WNF_VERSION, true );

		/*
		 * Tajawal, for the Arabic widget. Registered always, enqueued only when
		 * an Arabic widget actually rendered — an English page should not pay
		 * for a font it will never draw a glyph with.
		 */
		wp_register_style(
			'wanaan-fare-finder-ar',
			'https://fonts.googleapis.com/css2?family=Tajawal:wght@400;500;700;800&display=swap',
			array(),
			null // phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Google serves its own.
		);
	}

	public static function maybe_enqueue() {
		if ( ! self::$used ) {
			return;
		}
		if ( self::$rtl ) {
			wp_enqueue_style( 'wanaan-fare-finder-ar' );
		}
		wp_enqueue_style( 'wanaan-fare-finder' );
		wp_enqueue_script( 'wanaan-fare-finder' );
	}

	/**
	 * @param array $atts Shortcode attributes.
	 * @return string
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'service'  => 'airport',
				'lang'     => '',
				'theme'    => 'dark',
				'sticky'   => '',
				'headline' => '',
				'sub'      => '',
				'faq_schema' => '',
				/*
				 * On by default. Lets the widget escape a Boxed Elementor
				 * container so every service page matches the airport one
				 * without the same setting being changed by hand five times.
				 * full="0" leaves the page's own boxed width alone.
				 */
				'full'     => '1',
			),
			$atts,
			'wanaan_fare_finder'
		);

		// Nothing this shortcode does is allowed to break the page it sits on.
		try {
			$payload = self::payload( $atts['service'] );
		} catch ( Exception $e ) {
			return '';
		}

		if ( empty( $payload['services'] ) ) {
			return '';
		}

		self::$used = true;
		++self::$count;

		/*
		 * Which language this widget speaks. The shortcode wins if it says so;
		 * otherwise ask Polylang, which knows the language of the page being
		 * viewed, and only fall back to the site locale when it is absent.
		 * get_locale() alone is not enough: on a multilingual site it can still
		 * report the default language while the visitor reads the Arabic page.
		 */
		$lang = $atts['lang'];
		if ( 'en' !== $lang && 'ar' !== $lang ) {
			$current = '';
			if ( function_exists( 'pll_current_language' ) ) {
				$current = (string) pll_current_language( 'slug' );
			}
			if ( '' === $current && defined( 'ICL_LANGUAGE_CODE' ) ) {
				$current = (string) ICL_LANGUAGE_CODE;
			}
			if ( '' === $current ) {
				$current = get_locale();
			}
			$lang = ( 0 === strpos( strtolower( $current ), 'ar' ) ) ? 'ar' : 'en';
		}

		if ( 'ar' === $lang ) {
			self::$rtl = true;
		}

		$theme = ( 'light' === $atts['theme'] ) ? 'light' : 'dark';
		$id    = 'wnf-' . self::$count;

		// Follows the customer down a desktop page; on a phone it just sits in
		// the flow, because there is no room beside the content.
		$sticky = in_array( strtolower( (string) $atts['sticky'] ), array( '1', 'yes', 'true', 'on' ), true )
			? ' wnf-sticky'
			: '';

		$config = array(
			'lang'     => $lang,
			'headline' => wp_strip_all_tags( $atts['headline'] ),
			'sub'      => wp_strip_all_tags( $atts['sub'] ),
			/*
			 * Where "Book this car" goes. The widget appends the trip; the
			 * server prices it and forwards to checkout. Empty without
			 * WooCommerce, and then the button falls back to the product page.
			 */
			'book_url' => class_exists( 'WooCommerce' ) ? home_url( '/' ) : '',
			'data'     => $payload,
		);

		$json = wp_json_encode( $config );
		if ( false === $json ) {
			return '';
		}

		/*
		 * The widget itself is built in JavaScript, so on its own it leaves an
		 * empty <div> in the HTML — nothing for a search engine to read on a page
		 * whose whole problem is search. So the server also renders the complete
		 * fare table as real markup, and the structured data to go with it. The
		 * JavaScript builds the interactive card ABOVE that table rather than
		 * replacing it: people get the calculator, crawlers get the prices, and
		 * anyone with JavaScript off still sees every fare.
		 */
		/*
		 * FAQ structured data is OFF unless asked for. The airport page already
		 * carries a FAQPage block from its own Elementor accordion, and two of
		 * them on one page is how you get Google to trust neither.
		 */
		$faq_schema = in_array( strtolower( (string) $atts['faq_schema'] ), array( '1', 'yes', 'true', 'on' ), true );

		$seo = self::seo_block( $payload, $lang, $faq_schema );

		/*
		 * With a fare table to show, the widget becomes two columns on a desktop:
		 * the calculator at two thirds, the price list beside it at one third.
		 * The class is set here rather than sniffed in CSS so the stylesheet
		 * needs no :has(), and a widget with no table is left exactly as it was.
		 */
		$side  = $seo ? '<div class="wnf-side">' . $seo . '</div>' : '';
		$split = $seo ? ' wnf-split' : '';

		/*
		 * The hook the stylesheet uses to lift a boxed container's 1140px cap.
		 * The CSS only acts when that container holds this widget and nothing
		 * else, so the class is safe to print on every instance.
		 */
		if ( ! in_array( strtolower( (string) $atts['full'] ), array( '0', 'no', 'false', 'off' ), true ) ) {
			$split .= ' wnf-bleed';
		}

		return sprintf(
			'<div id="%1$s" class="wnf-root wnf-theme-%2$s%5$s%7$s" dir="%3$s" data-wnf="%4$s">'
				. '<div class="wnf-app"></div>%6$s</div>',
			esc_attr( $id ),
			esc_attr( $theme ),
			'ar' === $lang ? 'rtl' : 'ltr',
			esc_attr( $json ),
			esc_attr( $sticky ),
			$side,
			esc_attr( $split )
		);
	}

	/**
	 * The crawlable half: a real heading, a real fare table, and JSON-LD.
	 *
	 * @param array  $payload Fare data.
	 * @param string $lang    en|ar.
	 * @return string
	 */
	private static function seo_block( $payload, $lang, $faq_schema = false ) {
		$cur  = isset( $payload['currency'] ) ? $payload['currency'] : 'BHD';
		/*
		 * What the customer reads versus what a search engine reads. The table
		 * shows the currency the way Bahrain writes it; the structured data below
		 * keeps the ISO code, which is what priceCurrency has to be.
		 */
		$cur_text = ( 'ar' === $lang && 'BHD' === $cur ) ? 'د.ب' : $cur;
		$cars = isset( $payload['cars'] ) ? $payload['cars'] : array();
		$out  = '';
		$ld   = array();

		foreach ( $payload['services'] as $service ) {
			$name = self::t( $service, '', $lang );
			$note = self::t( $service, 'note', $lang );

			// Only the vehicles this service offers, in order.
			$fleet = array();
			foreach ( $cars as $car ) {
				if ( empty( $service['cars'] ) || in_array( $car['id'], $service['cars'], true ) ) {
					$fleet[] = $car;
				}
			}
			if ( ! $fleet || empty( $service['options'] ) ) {
				continue;
			}

			$head = '';
			foreach ( $fleet as $car ) {
				$head .= '<th scope="col">' . esc_html( self::t( $car, '', $lang ) );
				if ( ! empty( $car['model'] ) ) {
					// Wrapped so the narrow desktop column can drop the model while
					// leaving it in the page for search engines.
					$head .= ' <span class="wnf-seo-model">(' . esc_html( $car['model'] ) . ')</span>';
				}
				$head .= '</th>';
			}

			$rows  = '';
			$offers = array();
			foreach ( $service['options'] as $option ) {
				$rows .= '<tr><th scope="row">' . esc_html( self::t( $option, '', $lang ) ) . '</th>';
				foreach ( $fleet as $car ) {
					$amount = isset( $option['prices'][ $car['id'] ] ) ? $option['prices'][ $car['id'] ] : null;
					if ( null === $amount || $amount <= 0 ) {
						$rows .= '<td>&mdash;</td>';
						continue;
					}
					$rows .= '<td>' . esc_html( $amount )
						. ' <span class="wnf-seo-cur">' . esc_html( $cur_text ) . '</span></td>';

					$offers[] = array(
						'@type'         => 'Offer',
						'name'          => self::t( $car, '', $lang ) . ' — ' . self::t( $option, '', $lang ),
						'price'         => (string) $amount,
						'priceCurrency' => $cur,
					);
				}
				$rows .= '</tr>';
			}

			/*
			 * Where a service has written copy, that is what the page shows: real
			 * sentences about the service with the fares inside them, which is
			 * worth far more to a search engine than a grid of numbers. A service
			 * with no copy yet still gets the fare table, so nothing goes blank.
			 */
			$copy = array();
			if ( ! empty( $service[ 'copy_' . $lang ] ) && is_array( $service[ 'copy_' . $lang ] ) ) {
				$copy = $service[ 'copy_' . $lang ];
			} elseif ( ! empty( $service['copy_en'] ) && is_array( $service['copy_en'] ) ) {
				$copy = $service['copy_en'];
			}

			if ( $copy ) {
				$out .= '<section class="wnf-seo wnf-seo-copy">'
					. self::article( $copy, $service, $payload )
					. ( $note ? '<p class="wnf-seo-note">' . esc_html( $note ) . '</p>' : '' )
					. '</section>';

				if ( $faq_schema ) {
					$faq = self::faq_ld( $copy, $service, $payload );
					if ( $faq ) {
						$ld[] = $faq;
					}
				}
			} else {
				$out .= '<section class="wnf-seo">'
					. '<h2 class="wnf-seo-h">' . esc_html( $name ) . ' — ' . esc_html( 'ar' === $lang ? 'الأسعار' : 'prices' ) . '</h2>'
					. '<div class="wnf-seo-scroll"><table class="wnf-seo-table">'
					. '<thead><tr><th scope="col">' . esc_html( self::t( $service, 'ask', $lang ) ) . '</th>' . $head . '</tr></thead>'
					. '<tbody>' . $rows . '</tbody>'
					. '</table></div>'
					. ( $note ? '<p class="wnf-seo-note">' . esc_html( $note ) . '</p>' : '' )
					. '</section>';
			}

			if ( $offers ) {
				$ld[] = array(
					'@context'       => 'https://schema.org',
					'@type'          => 'Service',
					'name'           => $name,
					'serviceType'    => $name,
					'areaServed'     => array(
						'@type' => 'Country',
						'name'  => 'Bahrain',
					),
					'provider'       => array(
						'@type'     => 'LocalBusiness',
						'name'      => 'Wanaan',
						'url'       => home_url( '/' ),
						'telephone' => '+' . preg_replace( '/\D/', '', (string) ( isset( $payload['whatsapp'] ) ? $payload['whatsapp'] : '' ) ),
					),
					'hasOfferCatalog' => array(
						'@type'           => 'OfferCatalog',
						'name'            => $name,
						'itemListElement' => $offers,
					),
				);
			}
		}

		foreach ( $ld as $block ) {
			$json = wp_json_encode( $block, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );
			if ( $json ) {
				$out .= '<script type="application/ld+json">' . $json . '</script>';
			}
		}

		return $out;
	}

	/**
	 * The written block: heading, intro, sections, questions.
	 *
	 * @param array $copy    Copy for this language.
	 * @param array $service Service definition, for the fares.
	 * @param array $payload Whole payload, for the notice period.
	 * @return string
	 */
	private static function article( $copy, $service, $payload ) {
		$html = '';

		if ( ! empty( $copy['title'] ) ) {
			$html .= '<h2 class="wnf-seo-h">' . esc_html( self::fill( $copy['title'], $service, $payload ) ) . '</h2>';
		}
		if ( ! empty( $copy['intro'] ) ) {
			$html .= '<p class="wnf-seo-lead">' . esc_html( self::fill( $copy['intro'], $service, $payload ) ) . '</p>';
		}

		foreach ( (array) ( isset( $copy['sections'] ) ? $copy['sections'] : array() ) as $section ) {
			if ( ! empty( $section['h'] ) ) {
				$html .= '<h3 class="wnf-seo-h3">' . esc_html( self::fill( $section['h'], $service, $payload ) ) . '</h3>';
			}
			foreach ( (array) ( isset( $section['p'] ) ? $section['p'] : array() ) as $para ) {
				$html .= '<p class="wnf-seo-p">' . esc_html( self::fill( $para, $service, $payload ) ) . '</p>';
			}
		}

		$faq = isset( $copy['faq'] ) ? (array) $copy['faq'] : array();
		if ( $faq ) {
			if ( ! empty( $copy['faq_title'] ) ) {
				$html .= '<h3 class="wnf-seo-h3">' . esc_html( $copy['faq_title'] ) . '</h3>';
			}
			/*
			 * <details>, which is exactly what the site's own Elementor accordion
			 * is built on — same open-and-close behaviour, and it works with the
			 * keyboard and with JavaScript switched off. The answers stay in the
			 * HTML whether or not they are open, so a crawler still reads them.
			 */
			$html .= '<div class="wnf-faq">';
			foreach ( $faq as $item ) {
				if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
					continue;
				}
				$html .= '<details class="wnf-faq-item">'
					. '<summary class="wnf-faq-q">'
						. '<span>' . esc_html( self::fill( $item['q'], $service, $payload ) ) . '</span>'
						. '<span class="wnf-faq-x" aria-hidden="true"></span>'
					. '</summary>'
					. '<div class="wnf-faq-a"><p>' . esc_html( self::fill( $item['a'], $service, $payload ) ) . '</p></div>'
					. '</details>';
			}
			$html .= '</div>';
		}

		return $html;
	}

	/**
	 * FAQPage structured data, built from the same questions the page shows.
	 *
	 * @param array $copy    Copy for this language.
	 * @param array $service Service definition.
	 * @param array $payload Whole payload.
	 * @return array|null
	 */
	private static function faq_ld( $copy, $service, $payload ) {
		$items = array();
		foreach ( (array) ( isset( $copy['faq'] ) ? $copy['faq'] : array() ) as $item ) {
			if ( empty( $item['q'] ) || empty( $item['a'] ) ) {
				continue;
			}
			$items[] = array(
				'@type'          => 'Question',
				'name'           => self::fill( $item['q'], $service, $payload ),
				'acceptedAnswer' => array(
					'@type' => 'Answer',
					'text'  => self::fill( $item['a'], $service, $payload ),
				),
			);
		}
		if ( ! $items ) {
			return null;
		}
		return array(
			'@context'   => 'https://schema.org',
			'@type'      => 'FAQPage',
			'mainEntity' => $items,
		);
	}

	/**
	 * Put today's real numbers into the copy.
	 *
	 * {p:option:car} becomes the live fare, {lead} the notice period. The prose
	 * therefore cannot drift from the widget: change a fare in the ERP and the
	 * sentence that quotes it changes with it.
	 *
	 * @param string $text    Copy with placeholders.
	 * @param array  $service Service definition.
	 * @param array  $payload Whole payload.
	 * @return string
	 */
	private static function fill( $text, $service, $payload ) {
		$text = str_replace(
			'{lead}',
			(string) ( isset( $payload['lead_hours'] ) ? (int) $payload['lead_hours'] : 12 ),
			(string) $text
		);

		return preg_replace_callback(
			'/\{p:([A-Za-z0-9_]+):([A-Za-z0-9_]+)\}/',
			function ( $m ) use ( $service ) {
				foreach ( (array) ( isset( $service['options'] ) ? $service['options'] : array() ) as $option ) {
					if ( ! isset( $option['code'] ) || $option['code'] !== $m[1] ) {
						continue;
					}
					if ( ! isset( $option['prices'][ $m[2] ] ) ) {
						return '';
					}
					$amount = (float) $option['prices'][ $m[2] ];
					return rtrim( rtrim( number_format( $amount, 3, '.', '' ), '0' ), '.' );
				}
				return '';
			},
			$text
		);
	}

	/**
	 * A localised label off any of the payload's objects.
	 *
	 * @param array  $obj  Object with en/ar or <key>_en/<key>_ar.
	 * @param string $key  Optional key prefix.
	 * @param string $lang en|ar.
	 * @return string
	 */
	private static function t( $obj, $key, $lang ) {
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

	/**
	 * Does this service price anything at all?
	 *
	 * @param array $service Service definition.
	 * @return bool
	 */
	private static function has_a_price( $service ) {
		if ( empty( $service['options'] ) || ! is_array( $service['options'] ) ) {
			return false;
		}
		foreach ( $service['options'] as $option ) {
			if ( empty( $option['prices'] ) || ! is_array( $option['prices'] ) ) {
				continue;
			}
			foreach ( $option['prices'] as $amount ) {
				if ( is_numeric( $amount ) && $amount > 0 ) {
					return true;
				}
			}
		}
		return false;
	}

	/**
	 * Fare data, narrowed to the requested service.
	 *
	 * @param string $only Service id or 'all'.
	 * @return array
	 */
	private static function payload( $only ) {
		$data = WNF_Pricing::get_payload();
		$only = sanitize_key( $only );

		if ( 'all' !== $only && '' !== $only ) {
			$kept = array();
			foreach ( $data['services'] as $service ) {
				if ( isset( $service['id'] ) && $service['id'] === $only ) {
					$kept[] = $service;
				}
			}
			$data['services'] = $kept;
		}

		/*
		 * A service reaches a customer only if it is switched on AND has at least
		 * one real price. A fare table full of zeroes is worse than no widget:
		 * "0 BHD" is a promise you cannot keep.
		 */
		$kept = array();
		foreach ( $data['services'] as $service ) {
			if ( isset( $service['active'] ) && ! $service['active'] ) {
				continue;
			}
			if ( ! self::has_a_price( $service ) ) {
				continue;
			}
			unset( $service['slugs'] );
			$kept[] = $service;
		}
		$data['services'] = $kept;

		return $data;
	}
}
