/**
 * Wanaan Border Offers — the search box's logic, with no page access, so it
 * can be tested on its own (tests/core.test.js, `node --test`).
 */
(function (root, factory) {
	if (typeof module === 'object' && module.exports) {
		module.exports = factory();
	} else {
		root.WBOCore = factory();
	}
})(typeof self !== 'undefined' ? self : this, function () {
	'use strict';

	function pad2(n) {
		return (n < 10 ? '0' : '') + n;
	}

	function hourLabel(h) {
		return pad2(h) + ':00';
	}

	/** "YYYY-MM-DD" → [y, m, d], or null when it is not a real day. */
	function parseYmd(s) {
		var m = /^(\d{4})-(\d{2})-(\d{2})$/.exec(String(s || ''));
		if (!m) {
			return null;
		}
		var y = +m[1], mo = +m[2], d = +m[3];
		var t = new Date(Date.UTC(y, mo - 1, d));
		if (t.getUTCFullYear() !== y || t.getUTCMonth() !== mo - 1 || t.getUTCDate() !== d) {
			return null;
		}
		return [y, mo, d];
	}

	function addDays(ymd, n) {
		var p = parseYmd(ymd);
		if (!p) {
			return ymd;
		}
		var t = new Date(Date.UTC(p[0], p[1] - 1, p[2] + n));
		return t.getUTCFullYear() + '-' + pad2(t.getUTCMonth() + 1) + '-' + pad2(t.getUTCDate());
	}

	/** Keep a day between the first and last that may be searched. */
	function clampDate(ymd, min, max) {
		if (!parseYmd(ymd)) {
			return min;
		}
		if (ymd < min) {
			return min;
		}
		if (max && ymd > max) {
			return max;
		}
		return ymd;
	}

	/** Today in a time zone ("Asia/Bahrain"), as YYYY-MM-DD. */
	function todayIn(tz, now) {
		var parts = new Intl.DateTimeFormat('en-CA', { timeZone: tz, year: 'numeric', month: '2-digit', day: '2-digit' })
			.formatToParts(now || new Date());
		var get = function (type) {
			for (var i = 0; i < parts.length; i++) {
				if (parts[i].type === type) {
					return parts[i].value;
				}
			}
			return '';
		};
		return get('year') + '-' + get('month') + '-' + get('day');
	}

	/** "Wed 21 Oct" (or the Arabic), read as a calendar day — never shifted by the visitor's time zone. */
	function formatDate(ymd, lang) {
		var p = parseYmd(ymd);
		if (!p) {
			return '';
		}
		var locale = lang === 'ar' ? 'ar-BH-u-nu-latn' : 'en-GB';
		return new Intl.DateTimeFormat(locale, { weekday: 'short', day: 'numeric', month: 'short', timeZone: 'UTC' })
			.format(new Date(Date.UTC(p[0], p[1] - 1, p[2])));
	}

	/** A string with its :placeholders filled in. */
	function t(strings, key, vars) {
		var s = strings && strings[key] !== undefined ? String(strings[key]) : key;
		if (vars) {
			Object.keys(vars).forEach(function (k) {
				s = s.split(':' + k).join(String(vars[k]));
			});
		}
		return s;
	}

	function percent(p) {
		var n = Number(p) || 0;
		return Math.round(n) === n ? String(n) : String(Math.round(n * 10) / 10);
	}

	/** The route arrow, pointing the way the line is read. */
	function arrow(lang) {
		return lang === 'ar' ? ' ← ' : ' → ';
	}

	function countryName(countries, code) {
		for (var i = 0; i < (countries || []).length; i++) {
			if (countries[i].code === code) {
				return countries[i].name;
			}
		}
		return code;
	}

	/** Why a search cannot run yet (a strings key), or null. */
	function validate(state) {
		if (!state.from || !state.to || state.from === state.to) {
			return 'same_country';
		}
		if (state.trip === 'round' && state.ret < state.dep) {
			return 'need_return';
		}
		return null;
	}

	/** The one or two directions to search. */
	function legsFor(state) {
		var legs = [{ key: 'out', from: state.from, to: state.to, date: state.dep }];
		if (state.trip === 'round') {
			legs.push({ key: 'back', from: state.to, to: state.from, date: state.ret });
		}
		return legs;
	}

	function buildUrl(endpoint, leg) {
		var sep = String(endpoint).indexOf('?') >= 0 ? '&' : '?';
		return endpoint + sep + 'from=' + encodeURIComponent(leg.from) + '&to=' + encodeURIComponent(leg.to) + '&date=' + encodeURIComponent(leg.date);
	}

	function hourOf(hours, h) {
		for (var i = 0; i < (hours || []).length; i++) {
			if (hours[i].hour === h) {
				return hours[i];
			}
		}
		return null;
	}

	function hasOffer(hours) {
		return (hours || []).some(function (h) {
			return h.discount && h.bookable;
		});
	}

	/**
	 * The discounted hour closest to the one chosen, or null. On a tie the
	 * LATER hour wins: the car is there later, so waiting is the trade.
	 */
	function nearestOffer(hours, chosen) {
		if (chosen === null || chosen === undefined) {
			return null;
		}
		var own = hourOf(hours, chosen);
		if (own && own.discount && own.bookable) {
			return null;
		}
		var best = null;
		(hours || []).forEach(function (h) {
			if (!h.discount || !h.bookable || h.hour === chosen) {
				return;
			}
			var d = Math.abs(h.hour - chosen);
			if (best === null || d < best.d || (d === best.d && h.hour > best.hour)) {
				best = { hour: h.hour, d: d };
			}
		});
		return best ? best.hour : null;
	}

	/** Every searched direction has a bookable hour picked. */
	function canBook(state, results) {
		var legs = legsFor(state);
		for (var i = 0; i < legs.length; i++) {
			var r = results[legs[i].key];
			var picked = state.selected[legs[i].key];
			var h = r ? hourOf(r.hours, picked) : null;
			if (!h || !h.bookable) {
				return false;
			}
		}
		return true;
	}

	function legLine(leg, result, picked, cfg) {
		var s = cfg.strings;
		var h = hourOf(result.hours, picked);
		var price = h && h.discount
			? t(s, 'msg_offer', { p: percent(result.discount_percent) })
			: t(s, 'msg_full');
		return countryName(cfg.countries, leg.from) + arrow(cfg.lang) + countryName(cfg.countries, leg.to) + ', ' +
			formatDate(leg.date, cfg.lang) + ' ' + t(s, 'at') + ' ' + hourLabel(picked) + ' (' + price + ')';
	}

	/** The WhatsApp message for the chosen trip. */
	function buildMessage(state, results, cfg) {
		var s = cfg.strings;
		var legs = legsFor(state);
		var lines = [t(s, 'msg_hello')];
		lines.push('• ' + legLine(legs[0], results.out, state.selected.out, cfg));
		if (legs[1]) {
			lines.push('• ' + t(s, 'msg_return') + ': ' + legLine(legs[1], results.back, state.selected.back, cfg));
		}
		lines.push('• ' + t(s, 'msg_passengers') + ': ' + state.passengers);
		return lines.join('\n');
	}

	function waLink(number, text) {
		var digits = String(number || '').replace(/\D+/g, '');
		return 'https://wa.me/' + digits + '?text=' + encodeURIComponent(text);
	}

	/** Passengers label: "1 passenger" / ":n passengers". */
	function passengersLabel(strings, n) {
		return n === 1 ? t(strings, 'passenger_one') : t(strings, 'passenger_many', { n: n });
	}

	return {
		pad2: pad2,
		hourLabel: hourLabel,
		parseYmd: parseYmd,
		addDays: addDays,
		clampDate: clampDate,
		todayIn: todayIn,
		formatDate: formatDate,
		t: t,
		percent: percent,
		arrow: arrow,
		countryName: countryName,
		validate: validate,
		legsFor: legsFor,
		buildUrl: buildUrl,
		hourOf: hourOf,
		hasOffer: hasOffer,
		nearestOffer: nearestOffer,
		canBook: canBook,
		buildMessage: buildMessage,
		waLink: waLink,
		passengersLabel: passengersLabel
	};
});
