<?php
/**
 * One search, checked: two countries, a day and the time on the road.
 *
 * The visitor's browser sends these, so nothing reaches the ERP unless it is
 * one of our countries and a real date. Also builds the query string in ONE
 * fixed order, because the ERP checks a signature over it.
 *
 * No WordPress calls in this file, so it can be tested on its own.
 */

defined( 'ABSPATH' ) || exit;

final class WBO_Query {

	/** Offered on the website, Bahrain first. Mirrors BorderOffers::COUNTRIES in the ERP. */
	const COUNTRIES = array( 'BH', 'SA' );

	/** How far ahead a customer may search. Mirrors BorderOffers::MAX_DAYS_AHEAD. */
	const MAX_DAYS_AHEAD = 90;

	/** The customer's time on the road, whole hours. Mirrors BorderOffers::MIN/MAX_TRIP_HOURS. */
	const MIN_TRIP_HOURS = 1;
	const MAX_TRIP_HOURS = 12;

	/** Shown first: a ride to the Eastern Province. */
	const DEFAULT_TRIP_HOURS = 2;

	/**
	 * @param mixed  $from  Country code.
	 * @param mixed  $to    Country code.
	 * @param mixed  $date  YYYY-MM-DD.
	 * @param string $today YYYY-MM-DD in Bahrain.
	 * @param mixed  $hours Time on the road, 1–12 (missing = DEFAULT_TRIP_HOURS).
	 * @return array{from:string,to:string,date:string,hours:int}|string the search, or why it was refused
	 */
	public static function normalise( $from, $to, $date, $today, $hours = null ) {
		$from = strtoupper( trim( is_string( $from ) ? $from : '' ) );
		$to   = strtoupper( trim( is_string( $to ) ? $to : '' ) );
		$date = trim( is_string( $date ) ? $date : '' );

		if ( ! in_array( $from, self::COUNTRIES, true ) || ! in_array( $to, self::COUNTRIES, true ) ) {
			return 'country';
		}

		if ( $from === $to ) {
			return 'same_country';
		}

		if ( ! preg_match( '/^(\d{4})-(\d{2})-(\d{2})$/', $date, $m ) || ! checkdate( (int) $m[2], (int) $m[3], (int) $m[1] ) ) {
			return 'date';
		}

		$day   = gmmktime( 0, 0, 0, (int) $m[2], (int) $m[3], (int) $m[1] );
		$first = strtotime( $today . ' 00:00:00 UTC' );
		if ( false === $first || $day < $first || $day > $first + self::MAX_DAYS_AHEAD * 86400 ) {
			return 'date';
		}

		if ( null === $hours || '' === $hours ) {
			$hours = self::DEFAULT_TRIP_HOURS;
		} elseif ( is_string( $hours ) && ctype_digit( $hours ) ) {
			$hours = (int) $hours;
		}
		if ( ! is_int( $hours ) || $hours < self::MIN_TRIP_HOURS || $hours > self::MAX_TRIP_HOURS ) {
			return 'hours';
		}

		return array(
			'from'  => $from,
			'to'    => $to,
			'date'  => $date,
			'hours' => $hours,
		);
	}

	/** The query string, always in the order from, to, date, hours. */
	public static function query_string( array $search ) {
		return 'from=' . rawurlencode( $search['from'] ) . '&to=' . rawurlencode( $search['to'] ) . '&date=' . rawurlencode( $search['date'] )
			. '&hours=' . (int) ( $search['hours'] ?? self::DEFAULT_TRIP_HOURS );
	}

	/** The ERP path for a search. */
	public static function path( $workspace, array $search ) {
		return '/api/v1/workspaces/' . (int) $workspace . '/border-offers?' . self::query_string( $search );
	}

	/**
	 * Only what the website needs from the ERP's answer, in a fixed shape —
	 * anything unexpected is dropped rather than passed on to the browser.
	 *
	 * @param mixed $body The decoded ERP answer.
	 * @return array<string,mixed>|null
	 */
	public static function public_answer( $body ) {
		if ( ! is_array( $body ) || ! isset( $body['hours'] ) || ! is_array( $body['hours'] ) || 24 !== count( $body['hours'] ) ) {
			return null;
		}

		$hours = array();
		foreach ( $body['hours'] as $h ) {
			if ( ! is_array( $h ) || ! isset( $h['hour'] ) ) {
				return null;
			}
			$hours[] = array(
				'hour'     => (int) $h['hour'],
				'bookable' => ! empty( $h['bookable'] ),
				'discount' => ! empty( $h['discount'] ),
				'cars'     => max( 0, (int) ( $h['cars'] ?? 0 ) ),
			);
		}

		$upcoming = array();
		foreach ( is_array( $body['upcoming'] ?? null ) ? $body['upcoming'] : array() as $u ) {
			if ( is_array( $u ) && isset( $u['date'], $u['hour'] ) && preg_match( '/^\d{4}-\d{2}-\d{2}$/', (string) $u['date'] ) ) {
				$upcoming[] = array(
					'date' => (string) $u['date'],
					'hour' => max( 0, min( 23, (int) $u['hour'] ) ),
				);
			}
		}

		return array(
			'enabled'          => ! empty( $body['enabled'] ),
			'discount_percent' => max( 0, min( 100, (float) ( $body['discount_percent'] ?? 0 ) ) ),
			'today'            => (string) ( $body['today'] ?? '' ),
			'max_date'         => (string) ( $body['max_date'] ?? '' ),
			'whatsapp'         => preg_replace( '/\D+/', '', (string) ( $body['whatsapp'] ?? '' ) ),
			'query'            => array(
				'from'  => (string) ( $body['query']['from'] ?? '' ),
				'to'    => (string) ( $body['query']['to'] ?? '' ),
				'date'  => (string) ( $body['query']['date'] ?? '' ),
				'hours' => max( 0, min( self::MAX_TRIP_HOURS, (int) ( $body['query']['hours'] ?? 0 ) ) ),
			),
			'hours'            => $hours,
			'upcoming'         => array_slice( $upcoming, 0, 6 ),
		);
	}
}
