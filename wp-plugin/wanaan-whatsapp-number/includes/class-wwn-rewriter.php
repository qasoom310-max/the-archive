<?php
/**
 * Swaps WhatsApp numbers in a finished HTML page. Pure PHP, no WordPress calls,
 * so it can be tested on its own.
 *
 * Two jobs:
 *
 * 1. Every WhatsApp LINK — wa.me/…, api.whatsapp.com/send?phone=…,
 *    web.whatsapp.com/send?phone=…, whatsapp://send?phone=… — is pointed at the
 *    current number, whatever number it was built with. A WhatsApp link only
 *    ever means WhatsApp, so this is always safe. Covers Elementor buttons,
 *    icons and social icons, links typed into text, and the JSON-escaped
 *    copies Elementor keeps in data attributes (https:\/\/wa.me\/…).
 *
 * 2. Every number in the "show as WhatsApp" list — wherever it appears as text
 *    or in a phone-call link (tel:) — is shown as the current number, in the
 *    same style it was written in ("+973 3999 1869" stays spaced,
 *    "+97339991869" stays joined). This is opt-in per number, because the same
 *    digits can also be the hotline, which must not move.
 *
 * Nothing is saved: the pages keep what the editors typed, and only what the
 * visitor receives changes. Switch the number back and the pages follow.
 */

defined( 'ABSPATH' ) || exit;

final class WWN_Rewriter {

	/**
	 * @param string   $html    the page
	 * @param string   $number  the current WhatsApp number, digits with country code (97339991869)
	 * @param string[] $others  numbers on the pages that should show as the WhatsApp number
	 */
	public static function rewrite( $html, $number, array $others = array() ) {
		$number = self::digits( $number );
		if ( '' === $html || strlen( $number ) < 8 ) {
			return $html;
		}

		$html = self::links( $html, $number );

		foreach ( $others as $old ) {
			$old = self::digits( $old );
			if ( strlen( $old ) < 8 || self::local( $old ) === self::local( $number ) ) {
				continue;
			}
			$html = self::numbers( $html, $old, $number );
		}

		return $html;
	}

	/** Point every WhatsApp link at $number. */
	private static function links( $html, $number ) {
		$slash = '(?:\\\\\/|\/)'; // "/" or the JSON-escaped "\/"
		$plus  = '(?:\+|%2B|%2b)?';
		$num   = '[0-9][0-9 \-%20().]*[0-9]';

		// wa.me/<number>
		$html = preg_replace_callback(
			'~(wa\.me' . $slash . ')' . $plus . '(' . $num . ')~',
			static function ( $m ) use ( $number ) {
				return $m[1] . $number;
			},
			$html
		);

		// api.whatsapp.com / web.whatsapp.com / whatsapp:// … phone=<number>
		$html = preg_replace_callback(
			'~((?:(?:api|web)\.whatsapp\.com|whatsapp:' . $slash . $slash . ')[^"\'\s<>]*?[?&;]phone=)' . $plus . '(' . $num . ')~i',
			static function ( $m ) use ( $number ) {
				return $m[1] . $number;
			},
			$html
		);

		return $html;
	}

	/** Show every written form of $old as $new, keeping the way it was written. */
	private static function numbers( $html, $old, $new ) {
		$oldLocal = self::local( $old );
		$oldCode  = substr( $old, 0, strlen( $old ) - strlen( $oldLocal ) );

		$sep   = '[ \-.]?';
		$local = implode( $sep, str_split( $oldLocal ) );
		$code  = '' !== $oldCode ? '(?:(\+|00)?' . preg_quote( $oldCode, '~' ) . $sep . ')?' : '()';

		// Never inside a longer run of digits (an id, an order number, a time).
		$pattern = '~(?<![0-9])' . $code . '(' . $local . ')(?![0-9])~';

		return preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $new ) {
				return WWN_Rewriter::styled( $m[0], $m[1], $new );
			},
			$html
		);
	}

	/**
	 * $new written the way $found was: same prefix (+, 00, none), with or
	 * without the country code, spaced in blocks of four or joined.
	 */
	public static function styled( $found, $prefix, $new ) {
		$newLocal = self::local( $new );
		$newCode  = substr( $new, 0, strlen( $new ) - strlen( $newLocal ) );
		$digits   = self::digits( $found );
		$hadCode  = strlen( $digits ) > 8;
		$spaced   = (bool) preg_match( '~[0-9][ \-.][0-9]~', $found );
		$joiner   = $spaced ? ( false !== strpos( $found, '-' ) ? '-' : ' ' ) : '';

		$local = $spaced && 8 === strlen( $newLocal )
			? substr( $newLocal, 0, 4 ) . $joiner . substr( $newLocal, 4 )
			: $newLocal;

		if ( ! $hadCode || '' === $newCode ) {
			return $local;
		}

		return (string) $prefix . $newCode . ( $spaced ? $joiner : '' ) . $local;
	}

	/** The local part: the last eight digits (Gulf mobiles and landlines). */
	public static function local( $digits ) {
		$digits = self::digits( $digits );

		return strlen( $digits ) > 8 ? substr( $digits, -8 ) : $digits;
	}

	public static function digits( $value ) {
		return (string) preg_replace( '~\D~', '', (string) $value );
	}
}
