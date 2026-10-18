// Run: node --test wp-plugin/wanaan-border-offers/tests/
'use strict';

const test = require('node:test');
const assert = require('node:assert/strict');
const core = require('../assets/wbo-core.js');

const strings = {
	msg_hello: 'Hello Wanaan, I would like to book a ride:',
	msg_offer: 'empty car offer, :p% off',
	msg_full: 'full fare',
	msg_return: 'Return',
	msg_passengers: 'Passengers',
	msg_trip: 'Time on the road',
	arrive: 'arrive about :time',
	next_day_short: 'next day',
	trip_hours_one: 'About 1 hour',
	trip_hours_two: 'About 2 hours',
	trip_hours_few: 'About :n hours',
	trip_hours_many: 'About :n hours',
	at: 'at',
	passenger_one: '1 passenger',
	passenger_many: ':n passengers',
};
const countries = [{ code: 'BH', name: 'Bahrain' }, { code: 'SA', name: 'Saudi Arabia' }];
const cfg = { strings, countries, lang: 'en' };

function day(discounted, closed) {
	const hours = [];
	for (let h = 0; h < 24; h++) {
		const discount = (discounted || []).includes(h);
		hours.push({ hour: h, bookable: !(closed || []).includes(h), discount, cars: discount ? 1 : 0 });
	}
	return hours;
}

test('dates are read and moved as calendar days', () => {
	assert.deepEqual(core.parseYmd('2026-10-21'), [2026, 10, 21]);
	assert.equal(core.parseYmd('2026-02-30'), null);
	assert.equal(core.parseYmd('21-10-2026'), null);
	assert.equal(core.addDays('2026-10-31', 1), '2026-11-01');
	assert.equal(core.addDays('2026-03-01', -1), '2026-02-28');
	assert.equal(core.addDays('2024-12-31', 1), '2025-01-01');
});

test('a day is kept inside what may be searched', () => {
	assert.equal(core.clampDate('2026-10-19', '2026-10-20', '2027-01-18'), '2026-10-20');
	assert.equal(core.clampDate('2027-05-01', '2026-10-20', '2027-01-18'), '2027-01-18');
	assert.equal(core.clampDate('nonsense', '2026-10-20', '2027-01-18'), '2026-10-20');
	assert.equal(core.clampDate('2026-11-02', '2026-10-20', '2027-01-18'), '2026-11-02');
});

test('today is the day in Bahrain, not on the visitor\'s clock', () => {
	// 22:30 UTC on the 20th is already 01:30 on the 21st in Bahrain (UTC+3).
	assert.equal(core.todayIn('Asia/Bahrain', new Date(Date.UTC(2026, 9, 20, 22, 30))), '2026-10-21');
	assert.equal(core.todayIn('Asia/Bahrain', new Date(Date.UTC(2026, 9, 20, 20, 59))), '2026-10-20');
});

test('dates show as short day names in both languages, with western digits', () => {
	assert.equal(core.formatDate('2026-10-21', 'en'), 'Wed 21 Oct');
	const ar = core.formatDate('2026-10-21', 'ar');
	assert.match(ar, /21/);
	assert.match(ar, /[؀-ۿ]/);
});

test('hours read as 00:00 to 23:00', () => {
	assert.equal(core.hourLabel(0), '00:00');
	assert.equal(core.hourLabel(9), '09:00');
	assert.equal(core.hourLabel(23), '23:00');
});

test('placeholders are filled in', () => {
	assert.equal(core.t({ off: ':p% off' }, 'off', { p: 30 }), '30% off');
	assert.equal(core.t({}, 'missing'), 'missing');
	assert.equal(core.percent(30), '30');
	assert.equal(core.percent(12.5), '12.5');
});

test('a search needs two different countries and a return on or after departure', () => {
	assert.equal(core.validate({ from: 'SA', to: 'SA', trip: 'oneway', dep: '2026-10-21' }), 'same_country');
	assert.equal(core.validate({ from: 'SA', to: 'BH', trip: 'round', dep: '2026-10-21', ret: '2026-10-20' }), 'need_return');
	assert.equal(core.validate({ from: 'SA', to: 'BH', trip: 'round', dep: '2026-10-21', ret: '2026-10-21' }), null);
	assert.equal(core.validate({ from: 'SA', to: 'BH', trip: 'oneway', dep: '2026-10-21', ret: '2026-10-01' }), null);
});

test('a round trip searches both ways, the return reversed', () => {
	const legs = core.legsFor({ from: 'SA', to: 'BH', trip: 'round', dep: '2026-10-21', ret: '2026-10-30', tripHours: 5 });
	assert.deepEqual(legs, [
		{ key: 'out', from: 'SA', to: 'BH', date: '2026-10-21', hours: 5 },
		{ key: 'back', from: 'BH', to: 'SA', date: '2026-10-30', hours: 5 },
	]);
	assert.equal(core.legsFor({ from: 'SA', to: 'BH', trip: 'oneway', dep: '2026-10-21' }).length, 1);
});

test('the search address works with and without pretty permalinks', () => {
	const leg = { from: 'SA', to: 'BH', date: '2026-10-21', hours: 4 };
	assert.equal(core.buildUrl('https://x.test/wp-json/wanaan/v1/border-offers', leg),
		'https://x.test/wp-json/wanaan/v1/border-offers?from=SA&to=BH&date=2026-10-21&hours=4');
	assert.equal(core.buildUrl('https://x.test/index.php?rest_route=/wanaan/v1/border-offers', leg),
		'https://x.test/index.php?rest_route=/wanaan/v1/border-offers&from=SA&to=BH&date=2026-10-21&hours=4');
});

test('wanting 02:00 when the car is there at 03:00 suggests 03:00', () => {
	const hours = day([3, 4, 5]);
	assert.equal(core.nearestOffer(hours, 2), 3);
	assert.equal(core.nearestOffer(hours, 9), 5);
	assert.equal(core.nearestOffer(hours, 4), null, 'a discounted hour needs no nudge');
	assert.equal(core.nearestOffer(day([]), 2), null);
	assert.equal(core.nearestOffer(hours, null), null);
});

test('on a tie the later hour is suggested — the car arrives later, so the customer waits', () => {
	assert.equal(core.nearestOffer(day([1, 5]), 3), 5);
});

test('an hour too soon to book is never suggested', () => {
	assert.equal(core.nearestOffer(day([3, 6], [3]), 2), 6);
	assert.equal(core.hasOffer(day([3], [3])), false);
	assert.equal(core.hasOffer(day([3])), true);
});

test('booking needs a bookable hour for each way', () => {
	const state = { from: 'SA', to: 'BH', trip: 'round', dep: '2026-10-21', ret: '2026-10-30', selected: { out: 3, back: null } };
	const results = { out: { hours: day([3]) }, back: { hours: day([], [14]) } };
	assert.equal(core.canBook(state, results), false);
	state.selected.back = 14;
	assert.equal(core.canBook(state, results), false, 'a closed hour cannot be booked');
	state.selected.back = 15;
	assert.equal(core.canBook(state, results), true);
	assert.equal(core.canBook({ ...state, trip: 'oneway' }, { out: results.out }), true);
});

test('the WhatsApp message says each way, the offer and the passengers', () => {
	const state = { from: 'SA', to: 'BH', trip: 'round', passengers: 2, dep: '2026-10-21', ret: '2026-10-30', selected: { out: 3, back: 14 } };
	const results = {
		out: { discount_percent: 30, hours: day([3]) },
		back: { discount_percent: 30, hours: day([]) },
	};
	assert.equal(core.buildMessage(state, results, cfg), [
		'Hello Wanaan, I would like to book a ride:',
		'• Saudi Arabia → Bahrain, Wed 21 Oct at 03:00, arrive about 05:00 (empty car offer, 30% off)',
		'• Return: Bahrain → Saudi Arabia, Fri 30 Oct at 14:00, arrive about 16:00 (full fare)',
		'• Time on the road: About 2 hours',
		'• Passengers: 2',
	].join('\n'));
});

test('the WhatsApp link carries the number as digits and the message encoded', () => {
	assert.equal(core.waLink('+973 3999 1869', 'Hi there & bye'), 'https://wa.me/97339991869?text=Hi%20there%20%26%20bye');
});

test('passengers read naturally', () => {
	assert.equal(core.passengersLabel(strings, 1), '1 passenger');
	assert.equal(core.passengersLabel(strings, 3), '3 passengers');
});

test('the time on the road is kept to 1–12 whole hours', () => {
	assert.equal(core.tripHours(5), 5);
	assert.equal(core.tripHours('12'), 12);
	assert.equal(core.tripHours(0), 1);
	assert.equal(core.tripHours(40), 12);
	assert.equal(core.tripHours(undefined), 2);
	assert.equal(core.tripHours('x'), 2);
});

test('the time on the road reads naturally, with a form for each Arabic plural', () => {
	const ar = { trip_hours_one: 'حوالي ساعة', trip_hours_two: 'حوالي ساعتين', trip_hours_few: 'حوالي :n ساعات', trip_hours_many: 'حوالي :n ساعة' };
	assert.equal(core.tripHoursLabel(strings, 1), 'About 1 hour');
	assert.equal(core.tripHoursLabel(strings, 7), 'About 7 hours');
	assert.equal(core.tripHoursLabel(ar, 2), 'حوالي ساعتين');
	assert.equal(core.tripHoursLabel(ar, 3), 'حوالي 3 ساعات');
	assert.equal(core.tripHoursLabel(ar, 10), 'حوالي 10 ساعات');
	assert.equal(core.tripHoursLabel(ar, 11), 'حوالي 11 ساعة');
});

test('the arrival time runs past midnight into the next day', () => {
	assert.deepEqual(core.arrival(3, 4), { hour: 7, days: 0 });
	assert.deepEqual(core.arrival(22, 5), { hour: 3, days: 1 });
	assert.equal(core.arriveLabel(strings, 3, 4), 'arrive about 07:00');
	assert.equal(core.arriveLabel(strings, 22, 5), 'arrive about 03:00 (next day)');
});

test('a round trip cannot come back before it has arrived', () => {
	const state = { from: 'SA', to: 'BH', trip: 'round', tripHours: 5, dep: '2026-10-21', ret: '2026-10-21', selected: { out: 8, back: null } };
	assert.equal(core.returnTooEarly(state, 12), true, '12:00 is before the 13:00 arrival');
	assert.equal(core.returnTooEarly(state, 13), false);
	assert.equal(core.returnTooEarly({ ...state, ret: '2026-10-22' }, 0), false, 'the next day is fine');
	assert.equal(core.returnTooEarly({ ...state, selected: { out: 21, back: null }, ret: '2026-10-22' }, 1), true, 'arrives 02:00 the next day');
	assert.equal(core.returnTooEarly({ ...state, selected: { out: null, back: null } }, 0), false, 'no departure picked yet');
	assert.equal(core.returnTooEarly({ ...state, trip: 'oneway' }, 0), false);

	const results = { out: { hours: day([]) }, back: { hours: day([]) } };
	assert.equal(core.canBook({ ...state, selected: { out: 8, back: 12 } }, results), false);
	assert.equal(core.canBook({ ...state, selected: { out: 8, back: 13 } }, results), true);
});