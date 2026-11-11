<?php
/**
 * Where prices come from.
 *
 * THE RULE THIS FILE EXISTS TO ENFORCE:
 * a customer's page load never waits on erp.wanaan-bh.com. Not once.
 * Every path through get_payload() returns from local storage. The ERP is
 * only ever contacted from WP-Cron or from the refresh endpoint the ERP
 * itself pings — never from a front-end render.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class WNF_Pricing {

	/**
	 * The fare data, whatever it takes to get some.
	 *
	 * Ladder:
	 *   1. fresh copy from the ERP (transient, < ttl)
	 *   2. stale copy from the ERP (last-good option) + queue a background refresh
	 *   3. the table built into the plugin
	 * There is no fourth branch that returns nothing.
	 *
	 * @return array
	 */
	public static function get_payload() {
		$settings = wnf_settings();

		if ( 'erp' === $settings['source'] ) {
			$fresh = get_transient( WNF_TR_CACHE );
			if ( self::looks_valid( $fresh ) ) {
				return self::finish( $fresh, $settings, 'erp-fresh' );
			}

			$last_good = get_option( WNF_OPT_LAST_GOOD, null );
			if ( self::looks_valid( $last_good ) ) {
				self::queue_refresh();
				return self::finish( $last_good, $settings, 'erp-stale' );
			}

			// Never spoken to the ERP. Serve the built-in table and go and ask,
			// out of band, so this visitor is not the one who pays for it.
			self::queue_refresh();
		}

		return self::finish( wnf_builtin_data(), $settings, 'builtin' );
	}

	/**
	 * Apply local overrides, resolve products, compute offers.
	 *
	 * @param array  $data     Raw dataset in ERP shape.
	 * @param array  $settings Plugin settings.
	 * @param string $origin   Which rung of the ladder answered.
	 * @return array
	 */
	private static function finish( $data, $settings, $origin ) {
		$data['origin'] = $origin;

		/*
		 * When the ERP is the source it owns EVERY value the widget shows, not
		 * just the fares — the WhatsApp number and the minimum notice included.
		 * Anything it does not send falls back to this site's own setting, so a
		 * partial payload degrades instead of blanking a field.
		 */
		$from_erp = ( 0 === strpos( $origin, 'erp' ) );
		$remote   = ( $from_erp && ! empty( $data['settings'] ) && is_array( $data['settings'] ) )
			? $data['settings']
			: array();

		$whatsapp = isset( $remote['whatsapp'] ) && '' !== $remote['whatsapp']
			? $remote['whatsapp']
			: $settings['whatsapp'];
		$data['whatsapp'] = preg_replace( '/\D/', '', (string) $whatsapp );

		$lead = isset( $remote['lead_hours'] ) && is_numeric( $remote['lead_hours'] )
			? $remote['lead_hours']
			: $settings['lead_hours'];
		$data['lead_hours'] = max( 0, (int) $lead );

		$today = current_time( 'Y-m-d' );

		foreach ( $data['services'] as $i => $service ) {
			$sid = isset( $service['id'] ) ? $service['id'] : '';

			/*
			 * The ERP owns prices. It does NOT own how the widget looks, so it
			 * sends no field list and no product slugs. Fill those in from the
			 * plugin's own table, or an ERP-sourced airport service would lose
			 * its date, direction and flight inputs entirely.
			 */
			$fallback = self::builtin_service( $sid );
			if ( $fallback ) {
				$carry = array(
					'fields',
					'slugs',
					'ask_en',
					'ask_ar',
					'note_en',
					'note_ar',
					'option_style',
					'extra_hour',
					'directions',
					'return_factor',
					'cars',
					'car_label_en',
					'car_label_ar',
					'book_en',
					'book_ar',
					'copy_en',
					'copy_ar',
				);
				foreach ( $carry as $key ) {
					if ( empty( $service[ $key ] ) && ! empty( $fallback[ $key ] ) ) {
						$service[ $key ]                = $fallback[ $key ];
						$data['services'][ $i ][ $key ] = $fallback[ $key ];
					}
				}
			}

			// Fares typed into the WordPress admin only apply while the ERP is
			// not the source. Once the ERP is in charge it wins outright — two
			// systems editing the same number is the bug we are removing.
			if ( 'builtin' === $origin && ! empty( $settings['overrides'][ $sid ] ) ) {
				$over = $settings['overrides'][ $sid ];
				foreach ( $service['options'] as $j => $option ) {
					$code = $option['code'];
					if ( empty( $over[ $code ] ) ) {
						continue;
					}
					foreach ( $over[ $code ] as $car => $amount ) {
						if ( is_numeric( $amount ) ) {
							$data['services'][ $i ]['options'][ $j ]['prices'][ $car ] = 0 + $amount;
						}
					}
				}
			}

			if ( 'builtin' === $origin && ! empty( $settings['extra_hours'][ $sid ] ) ) {
				foreach ( $settings['extra_hours'][ $sid ] as $car => $amount ) {
					if ( is_numeric( $amount ) ) {
						$data['services'][ $i ]['extra_hour'][ $car ] = 0 + $amount;
					}
				}
			}

			if ( 'builtin' === $origin && isset( $settings['return_factors'][ $sid ] ) ) {
				$factor = (float) $settings['return_factors'][ $sid ];
				if ( $factor > 0 ) {
					$data['services'][ $i ]['return_factor'] = $factor;
				}
			}

			if ( 'builtin' === $origin && ! empty( $settings['offers'][ $sid ] ) ) {
				$data['services'][ $i ]['offer'] = array_merge(
					wnf_empty_offer(),
					$settings['offers'][ $sid ]
				);
			}

			/*
			 * An offer is resolved against the DATE OF THE TRIP, not today's
			 * date — so the window travels to the browser intact rather than
			 * being collapsed to a yes/no here.
			 *
			 * This used to collapse it, and both halves were wrong. A National
			 * Day offer for 16-24 Sep showed no discount at all to somebody
			 * booking on the 10th for the 18th; and from the 16th it discounted
			 * every booking, including a trip on the 30th. See offer_applies().
			 *
			 * The one thing still decided here is death: an offer whose window
			 * has entirely passed is dropped, so a finished promotion cannot be
			 * revived by a customer picking a date in the past.
			 */
			$offer = isset( $data['services'][ $i ]['offer'] )
				? $data['services'][ $i ]['offer']
				: wnf_empty_offer();
			$offer = array_merge( wnf_empty_offer(), is_array( $offer ) ? $offer : array() );

			$offer['cars'] = self::offer_cars( $offer );

			$live = ! empty( $offer['active'] ) && (float) $offer['percent'] > 0;
			if ( $live && ! empty( $offer['ends'] ) && $today > $offer['ends'] ) {
				$live = false;
			}

			$offer['active']  = $live;
			$offer['percent'] = $live ? (float) $offer['percent'] : 0;

			$data['services'][ $i ]['offer'] = $offer;

			// Booking link and photo for each car, read from the real product.
			$slugs = isset( $service['slugs'] ) ? $service['slugs'] : array();
			$data['services'][ $i ]['products'] = self::products( $slugs );
		}

		return $data;
	}

	/**
	 * The car ids an offer is limited to.
	 *
	 * The ERP may name the field any of a few reasonable things, and an offer
	 * that names no cars applies to all of them. Normalising here means every
	 * caller downstream can just read $offer['cars'].
	 *
	 * @param array $offer Offer array.
	 * @return array Car ids; empty means every car.
	 */
	private static function offer_cars( $offer ) {
		foreach ( array( 'cars', 'vehicles', 'car_ids', 'vehicle_ids' ) as $key ) {
			if ( ! empty( $offer[ $key ] ) && is_array( $offer[ $key ] ) ) {
				return array_values( array_filter( array_map( 'strval', $offer[ $key ] ) ) );
			}
		}
		return array();
	}

	/**
	 * Does this offer discount THIS trip?
	 *
	 * The single rule, used by the widget, by the checkout and by the tests, so
	 * the price a customer is shown and the price they are charged cannot
	 * disagree. The JavaScript carries a copy of this same logic for display;
	 * this one is the authority, because it is the one the money goes through.
	 *
	 * An offer applies when it is switched on, has a percent, the TRIP DATE
	 * falls inside its window, and the chosen car is one it covers.
	 *
	 * A missing trip date means no discount. That is deliberate: before the
	 * customer has said when they are travelling we cannot know whether the
	 * offer covers them, and quoting the lower number first would mean putting
	 * the price UP once they pick a date.
	 *
	 * @param array  $offer Offer array, already normalised by finish().
	 * @param string $date  Trip date, Y-m-d.
	 * @param string $car   Chosen car id.
	 * @return float Percent to take off, or 0.
	 */
	public static function offer_applies( $offer, $date, $car = '' ) {
		if ( ! is_array( $offer ) || empty( $offer['active'] ) ) {
			return 0.0;
		}

		$percent = (float) ( isset( $offer['percent'] ) ? $offer['percent'] : 0 );
		if ( $percent <= 0 ) {
			return 0.0;
		}

		$date = trim( (string) $date );
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return 0.0;
		}

		// Y-m-d sorts correctly as a string, so no date parsing is needed and
		// no timezone can shift the comparison by a day.
		if ( ! empty( $offer['starts'] ) && $date < (string) $offer['starts'] ) {
			return 0.0;
		}
		if ( ! empty( $offer['ends'] ) && $date > (string) $offer['ends'] ) {
			return 0.0;
		}

		$cars = isset( $offer['cars'] ) && is_array( $offer['cars'] ) ? $offer['cars'] : array();
		if ( $cars && ! in_array( (string) $car, $cars, true ) ) {
			return 0.0;
		}

		return $percent;
	}

	/**
	 * The plugin's own definition of a service, used to fill in anything the
	 * ERP does not send.
	 *
	 * @param string $id Service id.
	 * @return array|null
	 */
	private static function builtin_service( $id ) {
		if ( ! $id ) {
			return null;
		}
		$builtin = wnf_builtin_data();
		foreach ( $builtin['services'] as $service ) {
			if ( isset( $service['id'] ) && $service['id'] === $id ) {
				return $service;
			}
		}
		return null;
	}

	/**
	 * Resolve WooCommerce products to a permalink, a photo and the product title.
	 * Cached for 12 hours — this is the only DB work the widget does.
	 *
	 * @param array $slugs car id => product slug
	 * @return array
	 */
	private static function products( $slugs ) {
		if ( empty( $slugs ) ) {
			return array();
		}

		$cache = get_transient( WNF_TR_PRODUCTS );
		if ( ! is_array( $cache ) ) {
			$cache = array();
		}

		$out     = array();
		$changed = false;

		foreach ( $slugs as $car => $slug ) {
			if ( isset( $cache[ $slug ] ) ) {
				$out[ $car ] = $cache[ $slug ];
				continue;
			}

			$info = array(
				'url'  => '',
				'img'  => '',
				'name' => '',
			);

			$post = get_page_by_path( $slug, OBJECT, 'product' );
			if ( $post ) {
				$info['url']  = get_permalink( $post );
				$info['name'] = get_the_title( $post );
				$img          = get_the_post_thumbnail_url( $post, 'medium_large' );
				if ( $img ) {
					$info['img'] = $img;
				}
			}

			$cache[ $slug ] = $info;
			$out[ $car ]    = $info;
			$changed        = true;
		}

		if ( $changed ) {
			set_transient( WNF_TR_PRODUCTS, $cache, 12 * HOUR_IN_SECONDS );
		}

		return $out;
	}

	/* --------------------------------------------------------------------
	 * Talking to the ERP. Only ever from cron or the refresh endpoint.
	 * ------------------------------------------------------------------ */

	/**
	 * Ask WP-Cron to fetch shortly. Non-blocking — the current page has already
	 * been served from local storage by the time this runs.
	 */
	private static function queue_refresh() {
		if ( ! wp_next_scheduled( WNF_CRON_HOOK ) ) {
			wp_schedule_single_event( time() + 5, WNF_CRON_HOOK );
			return;
		}
		// An hourly event already exists; nudge a one-off only if nothing is
		// due in the next couple of minutes.
		if ( wp_next_scheduled( WNF_CRON_HOOK ) > time() + 120 ) {
			wp_schedule_single_event( time() + 5, WNF_CRON_HOOK );
		}
	}

	/**
	 * Fetch, validate, store. Safe to call at any time.
	 *
	 * @return array {ok:bool, message:string, version:int|null}
	 */
	public static function refresh_from_erp() {
		$settings = wnf_settings();
		$url      = esc_url_raw( $settings['erp_url'] );

		$result = array(
			'ok'      => false,
			'message' => '',
			'version' => null,
			'time'    => current_time( 'mysql' ),
		);

		if ( ! $url || 0 !== strpos( $url, 'https://' ) ) {
			$result['message'] = 'ERP URL is missing or not https.';
			return self::record( $result );
		}

		/*
		 * Signed with PortalSignature, the scheme the ERP already uses for the
		 * payment portal — one scheme, one secret. The signature covers the
		 * PATH, so the workspace id in the URL is signed too and this site's
		 * key cannot be pointed at another workspace's prices.
		 */
		$signed = WNF_Signature::headers( 'GET', $url );

		if ( empty( $signed ) ) {
			$result['message'] = 'The shared secret is not defined in wp-config.php.';
			return self::record( $result );
		}

		$headers = array_merge(
			$signed,
			array( 'Accept' => 'application/json' )
		);

		$status = get_option( WNF_OPT_STATUS, array() );
		if ( ! empty( $status['etag'] ) ) {
			$headers['If-None-Match'] = $status['etag'];
		}

		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 8,
				'redirection' => 2,
				'sslverify'   => true,
				'headers'     => $headers,
				'user-agent'  => 'WanaanFareFinder/' . WNF_VERSION,
			)
		);

		if ( is_wp_error( $response ) ) {
			$result['message'] = 'Could not reach the ERP: ' . $response->get_error_message();
			return self::record( $result );
		}

		$code = (int) wp_remote_retrieve_response_code( $response );

		if ( 304 === $code ) {
			$last_good = get_option( WNF_OPT_LAST_GOOD, null );
			if ( self::looks_valid( $last_good ) ) {
				set_transient( WNF_TR_CACHE, $last_good, (int) $settings['ttl'] );
				$result['ok']      = true;
				$result['message'] = 'No change (304). Local copy refreshed.';
				$result['version'] = isset( $last_good['version'] ) ? (int) $last_good['version'] : null;
				return self::record( $result, null );
			}
			$result['message'] = 'ERP said 304 but there is no local copy to keep.';
			return self::record( $result );
		}

		if ( 200 !== $code ) {
			$result['message'] = 'ERP replied HTTP ' . $code . '.';
			return self::record( $result );
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! self::looks_valid( $body ) ) {
			// Refusing bad data is the point. A half-empty payload would wipe
			// prices off every landing page on the site.
			$result['message'] = 'ERP replied 200 but the data failed validation. Kept the previous prices.';
			return self::record( $result );
		}

		$body['source'] = 'erp';

		update_option( WNF_OPT_LAST_GOOD, $body, false );
		set_transient( WNF_TR_CACHE, $body, (int) $settings['ttl'] );
		delete_transient( WNF_TR_PRODUCTS );

		$result['ok']      = true;
		$result['version'] = isset( $body['version'] ) ? (int) $body['version'] : null;
		$result['message'] = 'Updated from the ERP.';

		$etag = wp_remote_retrieve_header( $response, 'etag' );

		return self::record( $result, $etag ? $etag : null );
	}

	/**
	 * @param array       $result Outcome.
	 * @param string|null $etag   New ETag, if any.
	 * @return array
	 */
	private static function record( $result, $etag = null ) {
		$status = get_option( WNF_OPT_STATUS, array() );
		if ( ! is_array( $status ) ) {
			$status = array();
		}

		$status['last_attempt'] = $result['time'];
		$status['last_message'] = $result['message'];
		$status['last_ok']      = (bool) $result['ok'];

		if ( $result['ok'] ) {
			$status['last_success'] = $result['time'];
			$status['version']      = $result['version'];
		}
		if ( null !== $etag ) {
			$status['etag'] = $etag;
		}

		update_option( WNF_OPT_STATUS, $status, false );

		if ( ! $result['ok'] && defined( 'WP_DEBUG' ) && WP_DEBUG ) {
			error_log( '[Wanaan Fare Finder] ' . $result['message'] );
		}

		return $result;
	}

	/**
	 * Would this data be safe to show a customer?
	 *
	 * Deliberately strict. Anything that could put a blank space or a 0 where a
	 * price belongs is rejected and the previous copy is kept instead.
	 *
	 * @param mixed $d Decoded payload.
	 * @return bool
	 */
	public static function looks_valid( $d ) {
		if ( ! is_array( $d ) ) {
			return false;
		}
		if ( empty( $d['cars'] ) || ! is_array( $d['cars'] ) ) {
			return false;
		}
		if ( empty( $d['services'] ) || ! is_array( $d['services'] ) ) {
			return false;
		}

		$car_ids = array();
		foreach ( $d['cars'] as $car ) {
			if ( empty( $car['id'] ) ) {
				return false;
			}
			$car_ids[] = $car['id'];
		}

		foreach ( $d['services'] as $service ) {
			if ( empty( $service['id'] ) || empty( $service['options'] ) || ! is_array( $service['options'] ) ) {
				return false;
			}
			foreach ( $service['options'] as $option ) {
				if ( empty( $option['code'] ) || empty( $option['prices'] ) || ! is_array( $option['prices'] ) ) {
					return false;
				}
				$priced = 0;
				foreach ( $option['prices'] as $car => $amount ) {
					if ( ! in_array( $car, $car_ids, true ) ) {
						continue;
					}
					if ( ! is_numeric( $amount ) || $amount <= 0 ) {
						return false;
					}
					++$priced;
				}
				if ( 0 === $priced ) {
					return false;
				}
			}
		}

		return true;
	}
}
