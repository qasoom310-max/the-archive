/* A fake ERP for the browser tests and the screenshots: Saudi → Bahrain has an
   empty car 03:00–07:00 (and 05:00 is too soon to book); Bahrain → Saudi has
   none that day, with the next one three days later at 09:00. */
(function () {
	'use strict';

	var core = window.WBOCore;

	function day(from, to, date, opts) {
		var hours = [];
		for (var h = 0; h < 24; h++) {
			var offer = from === 'SA' && h >= 3 && h <= 7;
			var closed = h === 5 && opts.closedFive;
			hours.push({ hour: h, bookable: !closed, discount: offer && !closed, cars: offer && !closed ? 1 : 0 });
		}
		return {
			enabled: true,
			discount_percent: 30,
			today: date,
			max_date: core.addDays(date, 90),
			whatsapp: '97339991869',
			query: { from: from, to: to, date: date },
			hours: hours,
			upcoming: from === 'BH' ? [{ date: core.addDays(date, 3), hour: 9 }] : []
		};
	}

	window.wboFakeFetch = function (opts) {
		opts = opts || {};
		var calls = [];
		var fn = function (url) {
			calls.push(url);
			var q = {};
			String(url).split('?')[1].split('&').forEach(function (pair) {
				var kv = pair.split('=');
				q[kv[0]] = decodeURIComponent(kv[1]);
			});
			var status = opts.status || 200;
			var body = status === 200 ? day(q.from, q.to, q.date, opts) : { error: 'unavailable', whatsapp: '97300000000' };
			return Promise.resolve({ ok: status === 200, status: status, json: function () { return Promise.resolve(body); } });
		};
		fn.calls = calls;
		return fn;
	};

	window.wboConfig = function (lang, extra) {
		var ar = lang === 'ar';
		var cfg = {
			endpoint: 'https://www.wanaan-bh.com/wp-json/wanaan/v1/border-offers',
			lang: lang,
			from: 'SA',
			to: 'BH',
			trip: 'round',
			maxDays: 90,
			timezone: 'Asia/Bahrain',
			countries: [
				{ code: 'BH', name: ar ? 'البحرين' : 'Bahrain' },
				{ code: 'SA', name: ar ? 'السعودية' : 'Saudi Arabia' }
			],
			strings: ar ? window.WBO_STRINGS_AR : window.WBO_STRINGS_EN,
			whatsapp: '97300000000'
		};
		Object.keys(extra || {}).forEach(function (k) {
			cfg[k] = extra[k];
		});
		return cfg;
	};

	/** Draw a box into a new div, with its own fake ERP. */
	window.wboMount = function (lang, fetchOpts, extra, theme) {
		var div = document.createElement('div');
		div.className = 'wbo wbo--' + (theme || 'light');
		div.dir = lang === 'ar' ? 'rtl' : 'ltr';
		div.setAttribute('data-wbo', JSON.stringify(window.wboConfig(lang, extra)));
		div.__wboFetch = window.wboFakeFetch(fetchOpts);
		document.getElementById('mount').appendChild(div);
		window.WBOApp.boot();
		return div;
	};
})();
