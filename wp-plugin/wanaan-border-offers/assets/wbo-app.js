/**
 * Wanaan Border Offers — the search box on the page.
 *
 * Draws itself into every <div class="wbo" data-wbo="{…}"> the shortcode
 * printed, asks /wp-json/wanaan/v1/border-offers for each direction, and
 * hands the chosen trip to WhatsApp. The logic lives in wbo-core.js.
 */
(function () {
	'use strict';

	var C = window.WBOCore;

	var ICON = {
		swap: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M7 7h11l-3-3m3 3-3 3M17 17H6l3 3m-3-3 3-3" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		trip: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M4 8h14l-3-3M20 16H6l3 3" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		clock: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="8.2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M12 7.5V12l3 2" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		person: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="8" r="3.5" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M5 20c.8-3.6 3.6-5.5 7-5.5s6.2 1.9 7 5.5" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
		origin: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="12" cy="12" r="6" fill="none" stroke="currentColor" stroke-width="2.2"/></svg>',
		pin: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="M12 21s-6.5-6-6.5-11a6.5 6.5 0 0 1 13 0C18.5 15 12 21 12 21Z" fill="none" stroke="currentColor" stroke-width="1.8"/><circle cx="12" cy="10" r="2.3" fill="none" stroke="currentColor" stroke-width="1.8"/></svg>',
		calendar: '<svg viewBox="0 0 24 24" aria-hidden="true"><rect x="3.5" y="5" width="17" height="15" rx="2" fill="none" stroke="currentColor" stroke-width="1.8"/><path d="M3.5 9.5h17M8 3v4m8-4v4" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round"/></svg>',
		prev: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m14.5 6-6 6 6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		next: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m9.5 6 6 6-6 6" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/></svg>',
		caret: '<svg viewBox="0 0 24 24" aria-hidden="true"><path d="m7 10 5 5 5-5Z" fill="currentColor"/></svg>',
		search: '<svg viewBox="0 0 24 24" aria-hidden="true"><circle cx="10.5" cy="10.5" r="6" fill="none" stroke="currentColor" stroke-width="2.2"/><path d="m15 15 5 5" stroke="currentColor" stroke-width="2.2" stroke-linecap="round"/></svg>',
		whatsapp: '<svg viewBox="0 0 24 24" aria-hidden="true"><path fill="currentColor" d="M12 2.2a9.7 9.7 0 0 0-8.3 14.8L2.3 21.8l4.9-1.3A9.7 9.7 0 1 0 12 2.2Zm0 17.7a8 8 0 0 1-4.1-1.1l-.3-.2-2.9.8.8-2.8-.2-.3A8 8 0 1 1 12 19.9Zm4.4-6c-.2-.1-1.4-.7-1.7-.8-.2-.1-.4-.1-.5.1l-.8 1c-.1.2-.3.2-.5.1a6.6 6.6 0 0 1-3.3-2.9c-.2-.4.2-.4.7-1.3.1-.2 0-.3 0-.4l-.8-1.8c-.2-.5-.4-.4-.5-.4h-.5a.9.9 0 0 0-.7.3 2.8 2.8 0 0 0-.9 2.1 4.9 4.9 0 0 0 1 2.6 11.2 11.2 0 0 0 4.3 3.8c1.6.7 2.2.8 3 .6a2.6 2.6 0 0 0 1.7-1.2 2.1 2.1 0 0 0 .1-1.2c0-.1-.2-.2-.4-.3Z"/></svg>'
	};

	function esc(s) {
		return String(s).replace(/[&<>"']/g, function (c) {
			return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
		});
	}

	function App(root, cfg) {
		this.root = root;
		this.cfg = cfg;
		this.s = cfg.strings || {};
		this.today = C.todayIn(cfg.timezone || 'Asia/Bahrain');
		this.maxDate = C.addDays(this.today, cfg.maxDays || 90);
		this.fetchImpl = (root.__wboFetch || window.fetch).bind(window);
		this.seq = 0;
		this.timer = null;
		this.pending = { out: null, back: null };
		this.state = {
			trip: cfg.trip === 'oneway' ? 'oneway' : 'round',
			passengers: 1,
			tripHours: C.tripHours(cfg.hours),
			from: cfg.from || 'SA',
			to: cfg.to || 'BH',
			dep: this.today,
			ret: C.clampDate(C.addDays(this.today, 2), this.today, this.maxDate),
			selected: { out: null, back: null }
		};
		this.results = { out: null, back: null };
		this.searched = false;
		this.draw();
	}

	App.prototype.t = function (key, vars) {
		return C.t(this.s, key, vars);
	};

	App.prototype.q = function (sel) {
		return this.root.querySelector(sel);
	};

	App.prototype.draw = function () {
		var s = this.state;
		var self = this;
		var countryOptions = function (selected) {
			return (self.cfg.countries || []).map(function (c) {
				return '<option value="' + esc(c.code) + '"' + (c.code === selected ? ' selected' : '') + '>' + esc(c.name) + '</option>';
			}).join('');
		};
		var passengerOptions = '';
		for (var n = 1; n <= 12; n++) {
			passengerOptions += '<option value="' + n + '">' + esc(C.passengersLabel(this.s, n)) + '</option>';
		}
		var hourOptions = '';
		for (var k = 1; k <= 12; k++) {
			hourOptions += '<option value="' + k + '">' + esc(C.tripHoursLabel(this.s, k)) + '</option>';
		}

		this.root.innerHTML =
			'<div class="wbo__card">' +
				'<div class="wbo__opts">' +
					'<label class="wbo__pill">' + ICON.trip +
						'<span class="wbo__sr">' + esc(this.t('round_trip')) + ' / ' + esc(this.t('one_way')) + '</span>' +
						'<select data-k="trip"><option value="round">' + esc(this.t('round_trip')) + '</option><option value="oneway">' + esc(this.t('one_way')) + '</option></select>' + ICON.caret +
					'</label>' +
					'<label class="wbo__pill">' + ICON.person +
						'<span class="wbo__sr">' + esc(this.t('passengers')) + '</span>' +
						'<select data-k="passengers">' + passengerOptions + '</select>' + ICON.caret +
					'</label>' +
					'<label class="wbo__pill" title="' + esc(this.t('trip_time')) + '">' + ICON.clock +
						'<span class="wbo__sr">' + esc(this.t('trip_time')) + '</span>' +
						'<select data-k="hours" aria-label="' + esc(this.t('trip_time')) + '">' + hourOptions + '</select>' + ICON.caret +
					'</label>' +
				'</div>' +
				'<div class="wbo__row">' +
					'<div class="wbo__places">' +
						'<label class="wbo__field wbo__field--from"><span class="wbo__ico">' + ICON.origin + '</span>' +
							'<span class="wbo__cap">' + esc(this.t('pickup_country')) + '</span>' +
							'<select data-k="from" aria-label="' + esc(this.t('pickup_country')) + '">' + countryOptions(s.from) + '</select></label>' +
						'<button type="button" class="wbo__swap" data-act="swap" aria-label="' + esc(this.t('swap')) + '" title="' + esc(this.t('swap')) + '">' + ICON.swap + '</button>' +
						'<label class="wbo__field wbo__field--to"><span class="wbo__ico">' + ICON.pin + '</span>' +
							'<span class="wbo__cap">' + esc(this.t('dropoff_country')) + '</span>' +
							'<select data-k="to" aria-label="' + esc(this.t('dropoff_country')) + '">' + countryOptions(s.to) + '</select></label>' +
					'</div>' +
					'<div class="wbo__dates">' +
						this.dateBox('dep', 'departure') +
						this.dateBox('ret', 'return') +
					'</div>' +
				'</div>' +
				'<p class="wbo__error" role="alert" hidden></p>' +
				'<div class="wbo__go"><button type="button" class="wbo__search" data-act="search">' + ICON.search + '<span>' + esc(this.t('search')) + '</span></button></div>' +
			'</div>' +
			'<div class="wbo__results" aria-live="polite" hidden></div>';

		this.q('[data-k="trip"]').value = s.trip;
		this.q('[data-k="passengers"]').value = String(s.passengers);
		this.q('[data-k="hours"]').value = String(s.tripHours);
		this.syncDates();
		this.bind();
	};

	App.prototype.dateBox = function (key, label) {
		return '<div class="wbo__date wbo__date--' + key + '">' +
			'<span class="wbo__ico">' + ICON.calendar + '</span>' +
			'<label class="wbo__datefield"><span class="wbo__cap">' + esc(this.t(label)) + '</span>' +
				// The day is shown as "Sat 17 Oct"; the real date input lies
				// invisibly on top, so a tap opens the phone's own date picker.
				'<span class="wbo__dateshow" data-show="' + key + '"></span>' +
				'<input type="date" data-k="' + key + '" aria-label="' + esc(this.t(label)) + '"></label>' +
			'<button type="button" class="wbo__step" data-step="-1" data-for="' + key + '" aria-label="' + esc(this.t('prev_day')) + '">' + ICON.prev + '</button>' +
			'<button type="button" class="wbo__step" data-step="1" data-for="' + key + '" aria-label="' + esc(this.t('next_day')) + '">' + ICON.next + '</button>' +
		'</div>';
	};

	App.prototype.syncDates = function () {
		var s = this.state;
		var dep = this.q('[data-k="dep"]');
		var ret = this.q('[data-k="ret"]');
		dep.min = this.today;
		dep.max = this.maxDate;
		dep.value = s.dep;
		ret.min = s.dep;
		ret.max = this.maxDate;
		ret.value = s.ret;
		this.q('[data-show="dep"]').textContent = C.formatDate(s.dep, this.cfg.lang);
		this.q('[data-show="ret"]').textContent = C.formatDate(s.ret, this.cfg.lang);
		this.q('.wbo__date--ret').hidden = s.trip !== 'round';
		this.root.classList.toggle('wbo--oneway', s.trip !== 'round');
		this.q('[data-step="-1"][data-for="dep"]').disabled = s.dep <= this.today;
		this.q('[data-step="1"][data-for="dep"]').disabled = s.dep >= this.maxDate;
		this.q('[data-step="-1"][data-for="ret"]').disabled = s.ret <= s.dep;
		this.q('[data-step="1"][data-for="ret"]').disabled = s.ret >= this.maxDate;
		this.q('[data-k="from"]').value = s.from;
		this.q('[data-k="to"]').value = s.to;
	};

	App.prototype.bind = function () {
		var self = this;

		this.root.addEventListener('change', function (e) {
			var k = e.target.getAttribute('data-k');
			if (!k) {
				return;
			}
			self.set(k, e.target.value);
		});

		this.root.addEventListener('click', function (e) {
			// Desktop browsers open the calendar only from its small icon;
			// open it from anywhere on the date box instead.
			if (e.target.matches && e.target.matches('input[type="date"]') && typeof e.target.showPicker === 'function') {
				try {
					e.target.showPicker();
				} catch (err) {
					// Not allowed here (e.g. no user gesture): the browser's own behaviour stays.
				}
				return;
			}
			var btn = e.target.closest('button, a');
			if (!btn || !self.root.contains(btn)) {
				return;
			}
			var act = btn.getAttribute('data-act');
			if (btn.hasAttribute('data-step')) {
				var key = btn.getAttribute('data-for');
				self.set(key, C.addDays(self.state[key], parseInt(btn.getAttribute('data-step'), 10)));
			} else if (act === 'swap') {
				btn.classList.toggle('is-turned');
				self.set('swap');
			} else if (act === 'search') {
				self.search(true);
			} else if (act === 'hour') {
				self.pick(btn.getAttribute('data-leg'), parseInt(btn.getAttribute('data-hour'), 10));
			} else if (act === 'jump') {
				self.jump(btn.getAttribute('data-leg'), btn.getAttribute('data-date'), parseInt(btn.getAttribute('data-hour'), 10));
			} else if (act === 'book' && btn.getAttribute('aria-disabled') === 'true') {
				e.preventDefault();
			}
		});
	};

	/** One change to the search; re-asks once the visitor has searched. */
	App.prototype.set = function (key, value) {
		var s = this.state;
		var changesHours = true;

		if (key === 'swap') {
			var f = s.from;
			s.from = s.to;
			s.to = f;
		} else if (key === 'from' || key === 'to') {
			var other = key === 'from' ? 'to' : 'from';
			// Picking the country already on the other side swaps them, as
			// flight search boxes do, rather than leaving one country twice.
			if (value === s[other]) {
				s[other] = s[key];
			}
			s[key] = value;
		} else if (key === 'trip') {
			s.trip = value === 'oneway' ? 'oneway' : 'round';
			if (s.trip === 'round' && s.ret < s.dep) {
				s.ret = s.dep;
			}
		} else if (key === 'passengers') {
			s.passengers = Math.max(1, Math.min(12, parseInt(value, 10) || 1));
			changesHours = false;
		} else if (key === 'hours') {
			s.tripHours = C.tripHours(value);
		} else if (key === 'dep') {
			s.dep = C.clampDate(value, this.today, this.maxDate);
			if (s.ret < s.dep) {
				s.ret = s.dep;
			}
		} else if (key === 'ret') {
			s.ret = C.clampDate(value, s.dep, this.maxDate);
		}

		this.syncDates();

		if (!changesHours) {
			this.renderSummary();
			return;
		}

		if (this.searched) {
			var self = this;
			clearTimeout(this.timer);
			this.timer = setTimeout(function () {
				self.search(false);
			}, 200);
		}
	};

	App.prototype.showError = function (key) {
		var p = this.q('.wbo__error');
		p.hidden = !key;
		p.textContent = key ? this.t(key) : '';
	};

	App.prototype.search = function (byButton) {
		var self = this;
		var problem = C.validate(this.state);
		this.showError(problem);
		if (problem) {
			return Promise.resolve();
		}

		var seq = ++this.seq;
		var legs = C.legsFor(this.state);
		var button = this.q('.wbo__search');
		button.classList.add('is-busy');
		button.setAttribute('aria-busy', 'true');
		button.querySelector('span').textContent = this.t('searching');

		return Promise.all(legs.map(function (leg) {
			return self.fetchImpl(C.buildUrl(self.cfg.endpoint, leg), { headers: { Accept: 'application/json' }, credentials: 'same-origin' })
				.then(function (r) {
					return r.json().catch(function () {
						return {};
					}).then(function (body) {
						return { ok: r.ok, status: r.status, body: body };
					});
				}, function () {
					return { ok: false, status: 0, body: {} };
				});
		})).then(function (answers) {
			if (seq !== self.seq) {
				return;
			}
			button.classList.remove('is-busy');
			button.removeAttribute('aria-busy');
			button.querySelector('span').textContent = self.t('search');

			var failed = answers.filter(function (a) {
				return !a.ok;
			})[0];
			self.searched = true;
			if (failed) {
				self.results = { out: null, back: null };
				self.renderDown(failed.status === 429 ? 'error_busy' : 'error_down', failed.body && failed.body.whatsapp);
				return;
			}

			var next = { out: null, back: null };
			legs.forEach(function (leg, i) {
				next[leg.key] = answers[i].body;
				var keep = self.pending[leg.key] !== null ? self.pending[leg.key] : self.state.selected[leg.key];
				var h = C.hourOf(next[leg.key].hours, keep);
				self.state.selected[leg.key] = h && h.bookable ? keep : null;
				self.pending[leg.key] = null;
			});
			if (self.state.trip !== 'round') {
				self.state.selected.back = null;
			}
			self.results = next;
			self.renderResults();

			if (byButton) {
				var box = self.q('.wbo__results');
				if (box.scrollIntoView && box.getBoundingClientRect().top > window.innerHeight * 0.7) {
					box.scrollIntoView({ behavior: 'smooth', block: 'start' });
				}
			}
		});
	};

	App.prototype.pick = function (leg, hour) {
		var h = C.hourOf(this.hoursFor(leg), hour);
		if (!h || !h.bookable) {
			return;
		}
		this.state.selected[leg] = this.state.selected[leg] === hour ? null : hour;
		this.renderResults();
		var again = this.q('[data-leg="' + leg + '"][data-hour="' + hour + '"]');
		if (again) {
			again.focus();
		}
	};

	/** A "next empty car" chip: move that way's day there and pick the hour. */
	App.prototype.jump = function (leg, date, hour) {
		var s = this.state;
		if (leg === 'out') {
			s.dep = C.clampDate(date, this.today, this.maxDate);
			if (s.ret < s.dep) {
				s.ret = s.dep;
			}
		} else {
			s.ret = C.clampDate(date, this.today, this.maxDate);
			if (s.ret < s.dep) {
				s.dep = s.ret;
			}
		}
		this.pending[leg] = hour;
		this.syncDates();
		return this.search(false);
	};

	App.prototype.legSection = function (leg) {
		var r = this.results[leg.key];
		var picked = this.state.selected[leg.key];
		var pct = C.percent(r.discount_percent);
		var self = this;
		var hours = this.hoursFor(leg.key);
		var early = hours.filter(function (h) {
			return h.early;
		}).length;
		var offerOn = r.enabled && C.hasOffer(hours);

		var chips = hours.map(function (h) {
			var cls = 'wbo__hour' + (h.discount ? ' is-offer' : '') + (!h.bookable ? ' is-closed' : '') + (picked === h.hour ? ' is-selected' : '');
			var label = C.hourLabel(h.hour) + ', ' + (h.early ? self.t('before_arrival') : !h.bookable ? self.t('legend_closed') : h.discount ? self.t('off', { p: pct }) : self.t('full_fare'));
			return '<button type="button" class="' + cls + '" data-act="hour" data-leg="' + leg.key + '" data-hour="' + h.hour + '"' +
				' aria-pressed="' + (picked === h.hour ? 'true' : 'false') + '" aria-label="' + esc(label) + '"' + (!h.bookable ? ' disabled' : '') + '>' +
				'<span class="wbo__time" dir="ltr">' + C.hourLabel(h.hour) + '</span>' +
				(h.discount ? '<span class="wbo__tag">' + esc(self.t('off', { p: pct })) + '</span>' : '') +
			'</button>';
		}).join('');

		var note = '';
		if (early > 0) {
			var a = C.arrival(this.state.selected.out, this.state.tripHours);
			var when = C.formatDate(C.addDays(this.state.dep, a.days), this.cfg.lang) + ' ' + C.hourLabel(a.hour);
			note += '<p class="wbo__note wbo__note--info">' + esc(this.t('return_after', { time: when })) + '</p>';
		}
		var chosen = picked !== null ? C.hourOf(hours, picked) : null;
		var better = C.nearestOffer(hours, picked);
		if (chosen && chosen.discount) {
			note = '<p class="wbo__note wbo__note--good">' + esc(this.t('offer_explain', { p: pct })) + '</p>';
		} else if (chosen && better !== null && r.enabled) {
			note = '<p class="wbo__note wbo__note--nudge"><span>' + esc(this.t('nudge', { time: C.hourLabel(better), p: pct })) + '</span>' +
				'<button type="button" class="wbo__chip" data-act="hour" data-leg="' + leg.key + '" data-hour="' + better + '">' + esc(this.t('nudge_btn', { time: C.hourLabel(better) })) + '</button></p>';
		}
		if (!offerOn) {
			var up = (r.upcoming || []).map(function (u) {
				return '<button type="button" class="wbo__chip wbo__chip--offer" data-act="jump" data-leg="' + leg.key + '" data-date="' + esc(u.date) + '" data-hour="' + u.hour + '">' +
					esc(C.formatDate(u.date, self.cfg.lang)) + ' · <span dir="ltr">' + C.hourLabel(u.hour) + '</span></button>';
			}).join('');
			note += '<div class="wbo__note wbo__note--none"><p>' + esc(this.t('no_offer_day')) + '</p>' +
				(up ? '<p class="wbo__next">' + esc(this.t('next_offers')) + '</p><div class="wbo__chips">' + up + '</div>' : '') + '</div>';
		}

		return '<section class="wbo__leg" data-leg="' + leg.key + '">' +
			'<header class="wbo__leghead">' +
				'<div><h3>' + esc(this.t(leg.key === 'out' ? 'outbound' : 'return_leg')) + ' · ' + esc(C.formatDate(leg.date, this.cfg.lang)) + '</h3>' +
				'<p>' + esc(C.countryName(this.cfg.countries, leg.from)) + C.arrow(this.cfg.lang) + esc(C.countryName(this.cfg.countries, leg.to)) + '</p></div>' +
				(offerOn ? '<span class="wbo__badge">' + esc(this.t('off', { p: pct })) + '</span>' : '') +
			'</header>' +
			'<p class="wbo__hint">' + esc(this.t('pick_hour')) + ' · ' + esc(C.tripHoursLabel(this.s, this.state.tripHours)) + '</p>' +
			'<div class="wbo__hours" role="group" aria-label="' + esc(this.t('pick_hour')) + '">' + chips + '</div>' +
			note +
		'</section>';
	};

	/** A leg's hours as they may be booked: a return before the outward trip arrives cannot. */
	App.prototype.hoursFor = function (key) {
		var r = this.results[key];
		var s = this.state;
		return (r ? r.hours : []).map(function (h) {
			var early = key === 'back' && C.returnTooEarly(s, h.hour);
			return early ? { hour: h.hour, bookable: false, discount: h.discount, cars: h.cars, early: true } : h;
		});
	};

	App.prototype.renderResults = function () {
		var box = this.q('.wbo__results');
		if (this.state.selected.back !== null && C.returnTooEarly(this.state, this.state.selected.back)) {
			this.state.selected.back = null;
		}
		var legs = C.legsFor(this.state);
		var self = this;
		var pct = C.percent((this.results.out || {}).discount_percent || 0);

		box.hidden = false;
		box.innerHTML = legs.map(function (leg) {
			return self.legSection(leg);
		}).join('') +
			'<ul class="wbo__legend">' +
				'<li><i class="is-offer"></i>' + esc(this.t('legend_offer', { p: pct })) + '</li>' +
				'<li><i></i>' + esc(this.t('legend_full')) + '</li>' +
				'<li><i class="is-closed"></i>' + esc(this.t('legend_closed')) + '</li>' +
			'</ul>' +
			'<div class="wbo__summary"></div>';
		this.renderSummary();
	};

	App.prototype.renderSummary = function () {
		var box = this.q('.wbo__summary');
		if (!box || !this.results.out) {
			return;
		}
		var s = this.state;
		var ready = C.canBook(s, this.results);
		var number = this.results.out.whatsapp || this.cfg.whatsapp || '';
		var self = this;

		var lines = C.legsFor(s).map(function (leg) {
			var picked = s.selected[leg.key];
			var r = self.results[leg.key];
			var h = r && picked !== null ? C.hourOf(r.hours, picked) : null;
			return '<li><b>' + esc(C.countryName(self.cfg.countries, leg.from)) + C.arrow(self.cfg.lang) + esc(C.countryName(self.cfg.countries, leg.to)) + '</b> ' +
				esc(C.formatDate(leg.date, self.cfg.lang)) +
				(h ? ' · <span dir="ltr">' + C.hourLabel(picked) + '</span> · ' + esc(C.arriveLabel(self.s, picked, s.tripHours)) + (h.discount ? ' <span class="wbo__tag">' + esc(self.t('off', { p: C.percent(r.discount_percent) })) + '</span>' : '') : ' · —') +
			'</li>';
		}).join('');

		var href = ready && number ? C.waLink(number, C.buildMessage(s, this.results, this.cfg)) : '#';
		box.innerHTML =
			'<div class="wbo__sumtext"><p class="wbo__sumtitle">' + esc(this.t('summary')) + ' · ' + esc(C.passengersLabel(this.s, s.passengers)) + '</p><ul>' + lines + '</ul>' +
				(ready ? '' : '<p class="wbo__hint">' + esc(this.t(s.trip === 'round' ? 'choose_hours' : 'choose_hour')) + '</p>') + '</div>' +
			'<a class="wbo__book" data-act="book" href="' + esc(href) + '" target="_blank" rel="noopener"' +
				' aria-disabled="' + (ready && number ? 'false' : 'true') + '">' + ICON.whatsapp + '<span>' + esc(this.t('book')) + '</span></a>';
	};

	/** The ERP could not be asked: say so and still offer WhatsApp. */
	App.prototype.renderDown = function (key, number) {
		var box = this.q('.wbo__results');
		var to = number || this.cfg.whatsapp || '';
		box.hidden = false;
		box.innerHTML = '<div class="wbo__down"><p>' + esc(this.t(key)) + '</p>' +
			(to ? '<a class="wbo__book" data-act="book" href="' + esc(C.waLink(to, this.t('msg_hello'))) + '" target="_blank" rel="noopener" aria-disabled="false">' + ICON.whatsapp + '<span>' + esc(this.t('book')) + '</span></a>' : '') +
		'</div>';
	};

	function boot() {
		var roots = document.querySelectorAll('.wbo[data-wbo]');
		for (var i = 0; i < roots.length; i++) {
			if (roots[i].__wbo) {
				continue;
			}
			var cfg;
			try {
				cfg = JSON.parse(roots[i].getAttribute('data-wbo'));
			} catch (e) {
				continue;
			}
			roots[i].__wbo = new App(roots[i], cfg);
		}
	}

	window.WBOApp = { App: App, boot: boot };

	if (document.readyState === 'loading') {
		document.addEventListener('DOMContentLoaded', boot);
	} else {
		boot();
	}
})();
