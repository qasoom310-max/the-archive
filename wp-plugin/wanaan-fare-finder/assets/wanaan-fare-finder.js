/* Wanaan Fare Finder — front end. No libraries, no build step.
 *
 * Design rule: the fare is on screen the moment the page paints. Everything
 * the customer might type — flight number, bags, date — sits BELOW the price,
 * never between the customer and the number.
 */
(function () {
  "use strict";

  var T = {
    en: {
      car: "Choose your car",
      fare: "Your fare",
      cur: "BHD",
      book: "Book this car",
      wa: "Or send the details on WhatsApp →",
      waOnly: "Send the details on WhatsApp to book →",
      see: "See the",
      close: "Close",
      was: "Normally ",
      title: "What will my trip cost?",
      sub: "Fixed fare · Driver included · 24/7",
      details: "Trip details",
      required: "all required",
      missing: "Fill in {f} to book.",
      andWord: "and",
      dirFrom: "From the airport",
      dirTo: "To the airport",
      flight: "Flight number",
      flightPh: "GF 152",
      ftime: "Landing time",
      ftimeTo: "Pick-up time",
      pax: "passengers",
      bags: "bags",
      upto: "Seats up to",
      date: "Date",
      time: "Pick-up time",
      pickup: "Pick-up location",
      pickupPh: "Hotel, office or address",
      dropoff: "Drop-off address",
      dropoffPh: "Where are we taking you?",
      cities: "Cities within Bahrain",
      perHour: "{p} {c} per hour",
      extraHour: "Extra hours are {p} {c} each.",
      saving: "Booking the return saves you {p} {c} against two one-way trips.",
      oneway: "One way",
      returnT: "Return",
      hi: "Hello Wanaan, I would like to book:",
      lead: "We need {h} hours' notice to book online. For a sooner trip, message us on WhatsApp and we'll confirm availability.",
      leadShort: "Earliest online booking: {d}",
      lbl: { service: "Service", trip: "Trip", car: "Car", price: "Price" }
    },
    ar: {
      car: "اختر سيارتك",
      fare: "السعر",
      cur: "د.ب",
      book: "احجز هذه السيارة",
      wa: "أو أرسل التفاصيل على واتساب ←",
      waOnly: "أرسل التفاصيل على واتساب لإتمام الحجز ←",
      see: "شاهد",
      close: "إغلاق",
      was: "السعر العادي ",
      title: "كم تكلفة رحلتي؟",
      sub: "سعر ثابت · شامل السائق · ٢٤/٧",
      details: "تفاصيل الرحلة",
      required: "جميعها مطلوبة",
      missing: "أدخل {f} لإتمام الحجز.",
      andWord: "و",
      dirFrom: "من المطار",
      dirTo: "إلى المطار",
      flight: "رقم الرحلة",
      flightPh: "GF 152",
      ftime: "وقت الوصول",
      ftimeTo: "وقت الاستلام",
      pax: "ركاب",
      bags: "حقائب",
      upto: "تتسع حتى",
      date: "التاريخ",
      time: "وقت الاستلام",
      pickup: "مكان الاستلام",
      pickupPh: "الفندق أو المكتب أو العنوان",
      dropoff: "عنوان الوصول",
      dropoffPh: "إلى أين نأخذك؟",
      cities: "مدن داخل البحرين",
      perHour: "{p} {c} للساعة",
      extraHour: "الساعة الإضافية {p} {c}.",
      saving: "حجز الذهاب والعودة يوفر عليك {p} {c} مقارنة برحلتين منفصلتين.",
      oneway: "ذهاب فقط",
      returnT: "ذهاب وعودة",
      hi: "مرحباً ونان، أرغب في الحجز:",
      lead: "الحجز عبر الموقع يحتاج {h} ساعة مسبقاً. لرحلة أقرب، راسلنا على واتساب وسنؤكد التوفر.",
      leadShort: "أقرب موعد للحجز عبر الموقع: {d}",
      lbl: {
        service: "الخدمة",
        trip: "الرحلة",
        car: "السيارة",
        price: "السعر"
      }
    }
  };

  /* Local-time helpers. Times are compared against the visitor's own clock,
   * which is the clock they are reading the page with. */
  function pad(n) { return (n < 10 ? "0" : "") + n; }
  function dayString(d) {
    return d.getFullYear() + "-" + pad(d.getMonth() + 1) + "-" + pad(d.getDate());
  }
  function timeString(d) {
    return pad(d.getHours()) + ":" + pad(d.getMinutes());
  }

  function el(tag, cls, text) {
    var n = document.createElement(tag);
    if (cls) { n.className = cls; }
    if (text != null) { n.textContent = text; }
    return n;
  }

  function money(n, dp) {
    var places = typeof dp === "number" ? dp : 3;
    var factor = Math.pow(10, places);
    var v = Math.round(Number(n) * factor) / factor;
    if (!isFinite(v)) { return "0"; }
    var s = v.toFixed(places).replace(/0+$/, "").replace(/\.$/, "");
    return s === "" ? "0" : s;
  }

  function push(name, detail) {
    try {
      window.dataLayer = window.dataLayer || [];
      detail.event = name;
      window.dataLayer.push(detail);
    } catch (e) { /* tracking must never break the widget */ }
  }

  function Widget(root) {
    var cfg;
    try { cfg = JSON.parse(root.getAttribute("data-wnf")); } catch (e) { return; }
    if (!cfg || !cfg.data || !cfg.data.services || !cfg.data.services.length) { return; }

    var D = cfg.data;
    var lang = cfg.lang === "ar" ? "ar" : "en";
    var t = T[lang];
    var services = D.services;
    var allCars = D.cars || [];
    if (!allCars.length) { return; }

    var state = {
      svc: services[0].id,
      opt: null,
      car: allCars[0].id,
      dir: "from",
      trip: "oneway",
      flight: "",
      ftime: "",
      date: "",
      time: "",
      pickup: "",
      dropoff: "",
      attempted: false
    };

    /* The server also rendered a real fare table inside this root for crawlers
     * and for anyone without JavaScript. Build into .wnf-app so that table
     * survives underneath instead of being wiped. */
    var app = root.querySelector(".wnf-app") || root;

    var ui = {};

    function svc() {
      for (var i = 0; i < services.length; i++) {
        if (services[i].id === state.svc) { return services[i]; }
      }
      return services[0];
    }
    /* Which vehicles this service offers. Buses are not cars; the airport
     * service must not show a 30-seat coaster. */
    function cars() {
      var ids = svc().cars;
      if (!ids || !ids.length) { return allCars; }
      return allCars.filter(function (c) { return ids.indexOf(c.id) !== -1; });
    }
    function opts() { return svc().options || []; }
    function optFull(o) { return o ? (o[lang] || o.en || "") : ""; }
    function optShort(o) {
      if (!o) { return ""; }
      return o["short_" + lang] || o.short_en || optFull(o);
    }
    function option() {
      var o = opts();
      for (var i = 0; i < o.length; i++) {
        if (o[i].code === state.opt) { return o[i]; }
      }
      return o[0];
    }
    /* Each service names its own directions — an airport run is from/to the
     * airport, a Saudi run is Bahrain-to-Saudi or the reverse. */
    function dirs() {
      var d = svc().directions;
      if (d && d.length) { return d; }
      return [
        { v: "from", en: t.dirFrom, ar: t.dirFrom },
        { v: "to", en: t.dirTo, ar: t.dirTo }
      ];
    }
    function dirLabel(v) {
      var list = dirs();
      for (var i = 0; i < list.length; i++) {
        if (list[i].v === v) { return list[i][lang] || list[i].en; }
      }
      return "";
    }
    function returnFactor() {
      var f = Number(svc().return_factor);
      return isFinite(f) && f > 0 ? f : 2;
    }

    function has(field) {
      var f = svc().fields || [];
      return f.indexOf(field) !== -1;
    }
    /* Per-service wording with NO fallback to the object's own name. L() below
     * falls back to obj.en, which is right for ask/note but wrong here: a
     * service with no car_label_* would print its own name as the label. */
    function svcText(key) {
      var v = svc();
      return v[key + "_" + lang] || v[key + "_en"] || "";
    }
    function L(obj, key) {
      if (!obj) { return ""; }
      return obj[key + "_" + lang] || obj[key + "_en"] || obj[lang] || obj.en || "";
    }
    function carName(id) {
      var c = carInfo(id);
      return c[lang] || c.en || id;
    }
    function carInfo(id) {
      for (var i = 0; i < allCars.length; i++) {
        if (allCars[i].id === id) { return allCars[i]; }
      }
      return {};
    }
    function carModel(id) { return carInfo(id).model || ""; }
    function capacityLine(id) {
      var c = carInfo(id);
      var bits = [];
      if (c.pax) { bits.push(c.pax + " " + t.pax); }
      if (c.bags) { bits.push(c.bags + " " + t.bags); }
      return bits.length ? t.upto + " " + bits.join(" · ") : "";
    }
    function product(id) {
      var p = svc().products || {};
      var info = p[id] || { url: "", img: "", name: "" };

      /*
       * The vehicle's own photo, name and model come from the ERP when it sets
       * them. Only fall back to the WooCommerce product's featured image, so
       * swapping a car is one edit in one place rather than two systems.
       */
      var car = carInfo(id);
      if (car.image) { info = { url: info.url, img: car.image, name: info.name }; }
      return info;
    }
    function base(carId) {
      var o = option();
      if (!o || !o.prices) { return 0; }
      var v = o.prices[carId];
      return typeof v === "undefined" ? 0 : Number(v);
    }
    function full(carId) {
      var v = base(carId);
      if (has("trip") && state.trip === "return") { v = v * returnFactor(); }
      return v;
    }
    function offer() {
      return svc().offer || { active: false, percent: 0 };
    }

    /* Does the offer discount THIS trip?
     *
     * Mirrors WNF_Pricing::offer_applies() in PHP. That copy is the authority
     * — it decides what is charged — and this one exists so the customer is
     * shown the same number before they click.
     *
     * The window is measured against the DATE OF THE TRIP, not today. An offer
     * running 16-24 Sep discounts a trip on the 18th booked today; it does not
     * discount a trip on the 30th just because the offer is on now. Both of
     * those used to be wrong in opposite directions.
     *
     * With no date picked yet the answer is 0. We cannot know whether the offer
     * covers a trip whose date has not been chosen, and quoting the discount
     * first would mean putting the price UP the moment they choose. */
    function offerPercent(carId) {
      var o = offer();
      if (!o.active || !(o.percent > 0)) { return 0; }

      var d = String(state.date || "").trim();
      if (!/^\d{4}-\d{2}-\d{2}$/.test(d)) { return 0; }

      // ISO dates compare correctly as strings — no parsing, no timezone slip.
      if (o.starts && d < String(o.starts)) { return 0; }
      if (o.ends && d > String(o.ends)) { return 0; }

      // An offer naming no cars covers all of them.
      var cars = (o.cars && o.cars.length) ? o.cars : null;
      if (cars && cars.indexOf(String(carId)) === -1) { return 0; }

      return o.percent;
    }

    function paid(carId) {
      var f = full(carId);
      var p = offerPercent(carId);
      return p > 0 ? f * (1 - p / 100) : f;
    }

    /* Wording for the badge. Once a date is chosen it says what came off; until
     * then it advertises the window, so the offer is visible from the moment
     * the page loads rather than only after a lucky date. */
    function offerBadge() {
      var o = offer();
      if (!o.active || !(o.percent > 0)) { return null; }

      var label = (lang === "ar" ? o.label_ar : o.label_en) || (o.percent + "%");

      if (offerPercent(state.car) > 0) { return { text: label, applied: true }; }

      var when = niceRange(o.starts, o.ends);
      if (!when) { return null; }
      return { text: label + " \u00b7 " + when, applied: false };
    }

    /* "16 - 24 Sep" from two ISO dates, in the page's language. */
    function niceRange(a, b) {
      function one(iso) {
        if (!/^\d{4}-\d{2}-\d{2}$/.test(String(iso || ""))) { return ""; }
        var p = String(iso).split("-");
        var dt = new Date(Number(p[0]), Number(p[1]) - 1, Number(p[2]));
        if (isNaN(dt.getTime())) { return ""; }
        try {
          return dt.toLocaleDateString(lang === "ar" ? "ar" : "en-GB",
            { day: "numeric", month: "short" });
        } catch (e) {
          return p[2] + "/" + p[1];
        }
      }
      var x = one(a), y = one(b);
      if (x && y) { return x + " \u2013 " + y; }
      return x || y || "";
    }

    /* ----------------------------------------------------------------
     * Build the DOM once. Only the numbers and links change afterwards,
     * so typing a flight number never rebuilds the card or steals focus.
     * -------------------------------------------------------------- */
    function build() {
      app.textContent = "";

      var card = el("div", "wnf-card");

      // No wordmark here: the site's own logo sits directly above this widget,
      // and printing WANAAN twice on one screen reads as a mistake.
      var head = el("div", "wnf-head");
      head.appendChild(el("p", "wnf-title", cfg.headline || t.title));
      head.appendChild(el("p", "wnf-sub", cfg.sub || t.sub));
      card.appendChild(head);

      if (services.length > 1) {
        var tabs = el("div", "wnf-tabs");
        tabs.setAttribute("role", "tablist");
        services.forEach(function (s) {
          var b = el("button", "wnf-tab", s[lang] || s.en);
          b.type = "button";
          b.setAttribute("role", "tab");
          b.setAttribute("aria-selected", s.id === state.svc ? "true" : "false");
          b.addEventListener("click", function () {
            if (state.svc === s.id) { return; }
            state.svc = s.id;
            state.opt = null;
            build();
            emit("wanaan_fare_view");
          });
          tabs.appendChild(b);
        });
        card.appendChild(tabs);
      }

      var body = el("div", "wnf-body");

      /* --- trip definers: these change the price, so they come first --- */

      if (has("direction")) {
        var dlist = dirs();
        var known = dlist.some(function (d) { return d.v === state.dir; });
        if (!known) { state.dir = dlist[0].v; }
        body.appendChild(pills(
          "",
          dlist.map(function (d) { return { v: d.v, l: d[lang] || d.en }; }),
          function () { return state.dir; },
          function (v) { state.dir = v; update(); emit("wanaan_fare_view"); }
        ));
      }

      if (has("trip")) {
        body.appendChild(pills(
          "",
          [{ v: "oneway", l: t.oneway }, { v: "return", l: t.returnT }],
          function () { return state.trip; },
          function (v) { state.trip = v; update(); emit("wanaan_fare_view"); }
        ));
      }

      if (!state.opt && opts().length) { state.opt = opts()[0].code; }

      var fleet = cars();
      var haveCar = fleet.some(function (c) { return c.id === state.car; });
      if (!haveCar && fleet.length) { state.car = fleet[0].id; }

      var askField;

      if (svc().option_style === "pills") {
        // Three hour-blocks read better as buttons than as a dropdown: all the
        // choices are visible at once, and it is one tap instead of two.
        askField = pills(
          L(svc(), "ask"),
          opts().map(function (o) { return { v: o.code, l: optShort(o) }; }),
          function () { return state.opt; },
          function (v) { state.opt = v; update(); emit("wanaan_fare_view"); }
        );
      } else {
        askField = el("div", "wnf-field");
        var askLabel = el("label", null, L(svc(), "ask"));
        askLabel.setAttribute("for", root.id + "-opt");
        var sel = el("select");
        sel.id = root.id + "-opt";
        opts().forEach(function (o) {
          var op = el("option", null, optShort(o));
          op.value = o.code;
          sel.appendChild(op);
        });
        sel.value = state.opt;
        sel.addEventListener("change", function () {
          state.opt = sel.value;
          update();
          emit("wanaan_fare_view");
        });
        askField.appendChild(askLabel);
        askField.appendChild(sel);
      }

      // A short name is all that fits in the control. Anything longer — the
      // full list of areas in a zone — goes underneath, so nobody has to guess
      // whether theirs counts.
      ui.hint = el("p", "wnf-hint", "");
      askField.appendChild(ui.hint);

      body.appendChild(askField);

      /* --- cars --- */
      var carField = el("div", "wnf-field");
      carField.appendChild(el("label", null, svcText("car_label") || t.car));
      var grid = el("div", "wnf-cars");
      ui.carBtns = {};
      cars().forEach(function (c) {
        var b = el("button", "wnf-car");
        b.type = "button";
        b.setAttribute("aria-pressed", c.id === state.car ? "true" : "false");
        var nm = el("span", "wnf-nm", c[lang] || c.en);
        var pr = el("span", "wnf-pr", "");
        b.appendChild(nm);
        b.appendChild(pr);
        b.addEventListener("click", function () {
          state.car = c.id;
          update();
          emit("wanaan_fare_view");
        });
        ui.carBtns[c.id] = { btn: b, price: pr };
        grid.appendChild(b);
      });
      carField.appendChild(grid);

      // Capacity, not a question. It answers "will my family and our bags fit"
      // without the customer having to phone and ask.
      ui.cap = el("p", "wnf-cap", "");
      carField.appendChild(ui.cap);

      ui.see = el("button", "wnf-see", "");
      ui.see.type = "button";
      ui.see.addEventListener("click", function () { openPhoto(); });
      carField.appendChild(ui.see);
      body.appendChild(carField);

      /* --- the number --- */
      var fare = el("div", "wnf-fare");
      var left = el("div");
      left.appendChild(el("p", "wnf-lab", t.fare));
      ui.amount = el("div", "wnf-amount");
      ui.amountNum = el("span", null, "");
      ui.amount.appendChild(ui.amountNum);
      ui.amount.appendChild(el("span", "wnf-cur", t.cur));
      left.appendChild(ui.amount);
      ui.was = el("div", "wnf-was", "");
      left.appendChild(ui.was);
      ui.perHour = el("div", "wnf-perhour", "");
      left.appendChild(ui.perHour);
      fare.appendChild(left);
      ui.badgeWrap = el("div");
      ui.badge = el("span", "wnf-badge", "");
      ui.badgeWrap.appendChild(ui.badge);
      fare.appendChild(ui.badgeWrap);
      body.appendChild(fare);

      ui.extra = el("p", "wnf-extra", "");
      body.appendChild(ui.extra);

      /* --- trip details: after the price, before the button --- */
      var detailFields = ["date", "flight", "time", "pickup", "dropoff", "cities"].filter(has);
      if (detailFields.length) {
        var det = el("div", "wnf-details");
        var dh = el("div", "wnf-dhead");
        dh.appendChild(el("span", "wnf-dtitle", t.details));
        dh.appendChild(el("span", "wnf-dnote", t.required));
        det.appendChild(dh);

        var row = el("div", "wnf-drow");

        if (has("date")) { row.appendChild(input("date", t.date, "date", "")); }
        if (has("flight")) {
          row.appendChild(input("text", t.flight, "flight", t.flightPh));
          row.appendChild(input("time", t.ftime, "ftime", ""));
        }
        if (has("time")) { row.appendChild(input("time", t.time, "time", "")); }
        if (has("pickup")) {
          var pf = input("text", t.pickup, "pickup", t.pickupPh);
          pf.className = "wnf-field wnf-wide";
          row.appendChild(pf);
        }
        if (has("dropoff")) {
          var df = input("text", t.dropoff, "dropoff", t.dropoffPh);
          df.className = "wnf-field wnf-wide";
          row.appendChild(df);
        }
        // City to City: a line where the drop-off box would be, nothing to type.
        if (has("cities")) {
          row.appendChild(el("p", "wnf-cities wnf-wide", t.cities));
        }

        det.appendChild(row);
        body.appendChild(det);
      }

      /* --- actions --- */
      ui.warn = el("p", "wnf-warn", "");
      ui.warn.setAttribute("role", "status");
      body.appendChild(ui.warn);

      ui.cta = el("a", "wnf-cta", svcText("book") || t.book);
      ui.cta.setAttribute("href", "#");
      ui.cta.addEventListener("click", function (e) {
        if (ui.cta.getAttribute("aria-disabled") === "true") {
          e.preventDefault();
          focusMissing();
          return;
        }
        emit("wanaan_fare_book_click");
      });
      body.appendChild(ui.cta);

      ui.wa = el("a", "wnf-alt", t.wa);
      ui.waText = t.wa;
      ui.wa.setAttribute("href", "#");
      ui.wa.setAttribute("rel", "noopener");
      ui.wa.setAttribute("target", "_blank");
      ui.wa.addEventListener("click", function () { emit("wanaan_fare_whatsapp"); });
      body.appendChild(ui.wa);

      var note = L(svc(), "note");
      if (note) { body.appendChild(el("p", "wnf-note", note)); }

      card.appendChild(body);

      /* --- photo overlay --- */
      ui.lb = el("div", "wnf-lb");
      ui.lb.hidden = true;
      var inner = el("div", "wnf-lbin");
      ui.lbImg = el("img");
      ui.lbImg.setAttribute("alt", "");
      ui.lbImg.setAttribute("loading", "lazy");
      ui.lbName = el("p", "wnf-lbnm", "");
      var x = el("button", "wnf-lbx", t.close);
      x.type = "button";
      x.addEventListener("click", closePhoto);
      inner.appendChild(ui.lbImg);
      inner.appendChild(ui.lbName);
      inner.appendChild(x);
      ui.lb.appendChild(inner);
      ui.lb.addEventListener("click", function (e) {
        if (e.target === ui.lb) { closePhoto(); }
      });
      card.appendChild(ui.lb);

      app.appendChild(card);
      update();
    }

    function pills(labelText, items, get, set) {
      var f = el("div", "wnf-field");
      if (labelText) { f.appendChild(el("label", null, labelText)); }
      var wrap = el("div", "wnf-pills");
      items.forEach(function (it) {
        var b = el("button", "wnf-pill", it.l);
        b.type = "button";
        b.setAttribute("aria-pressed", get() === it.v ? "true" : "false");
        b.addEventListener("click", function () {
          set(it.v);
          Array.prototype.forEach.call(wrap.children, function (c) {
            c.setAttribute("aria-pressed", "false");
          });
          b.setAttribute("aria-pressed", "true");
        });
        wrap.appendChild(b);
      });
      f.appendChild(wrap);
      return f;
    }

    function input(type, labelText, key, placeholder) {
      var f = el("div", "wnf-field wnf-half");
      var id = root.id + "-" + key;
      var l = el("label", null, labelText);
      l.setAttribute("for", id);
      var i = el("input");
      i.type = type;
      i.id = id;
      if (placeholder) { i.placeholder = placeholder; }
      i.value = state[key] || "";
      i.addEventListener("input", function () {
        state[key] = i.value;
        applyLead();
        // The date is what offerPercent()/paid() key off — without this the
        // booking link (updateLinks -> bookLink) already carries the
        // discounted total the moment a qualifying date is typed, but the
        // big "Your fare" number on screen stays at the full price until
        // something else happens to trigger update(). The customer sees 40
        // right up to checkout and then 30 appears there, which reads as a
        // bait-and-switch even though the charged amount was always correct.
        update();
        updateLinks();
      });
      f.appendChild(l);
      f.appendChild(i);
      ui[key + "Label"] = l;
      ui[key + "Input"] = i;
      return f;
    }

    /* ----------------------------------------------------------------
     * Booking rules: every trip detail is required, and the trip has to be
     * at least `lead_hours` away. Falling foul of either blocks the booking
     * button but never the WhatsApp link — someone who needs a car in three
     * hours should still be able to ask.
     * -------------------------------------------------------------- */

    function leadHours() {
      var h = Number(D.lead_hours);
      return isFinite(h) && h >= 0 ? h : 12;
    }

    function earliest() {
      return new Date(Date.now() + leadHours() * 3600000);
    }

    function requiredKeys() {
      var keys = [];
      if (has("date")) { keys.push("date"); }
      if (has("flight")) { keys.push("flight", "ftime"); }
      if (has("time")) { keys.push("time"); }
      if (has("pickup")) { keys.push("pickup"); }
      if (has("dropoff")) { keys.push("dropoff"); }
      return keys;
    }

    function labelFor(key) {
      if (key === "date") { return t.date; }
      if (key === "flight") { return t.flight; }
      if (key === "ftime") { return state.dir === "to" ? t.ftimeTo : t.ftime; }
      if (key === "time") { return t.time; }
      if (key === "pickup") { return t.pickup; }
      if (key === "dropoff") { return t.dropoff; }
      return key;
    }

    function joinList(items) {
      if (items.length <= 1) { return items[0] || ""; }
      return items.slice(0, -1).join(", ") + " " + t.andWord + " " + items[items.length - 1];
    }

    function chosenMoment() {
      if (!state.date) { return null; }
      var clock = state.ftime || state.time || "23:59";
      var d = new Date(state.date + "T" + clock);
      return isNaN(d.getTime()) ? null : d;
    }

    function applyLead() {
      if (!ui.cta) { return; }

      var min = earliest();
      var minDay = dayString(min);

      if (ui.dateInput) { ui.dateInput.min = minDay; }
      ["ftimeInput", "timeInput"].forEach(function (k) {
        if (!ui[k]) { return; }
        if (state.date && state.date === minDay) {
          ui[k].min = timeString(min);
        } else {
          ui[k].removeAttribute("min");
        }
      });

      var missing = requiredKeys().filter(function (k) { return !state[k]; });

      // Only turn things red after someone has actually tried to book. Marking
      // empty boxes red the moment the page loads is telling people off for
      // nothing.
      requiredKeys().forEach(function (k) {
        // Must be a real boolean: classList.toggle(name, undefined) flips the
        // class instead of removing it, which turned every box red on load.
        var bad = !!(state.attempted && !state[k]);
        if (ui[k + "Input"]) { ui[k + "Input"].classList.toggle("wnf-bad", bad); }
        if (ui[k + "Label"]) { ui[k + "Label"].classList.toggle("wnf-bad", bad); }
      });

      var when = chosenMoment();
      var tooSoon = !missing.length && when && when.getTime() < min.getTime();

      var message = "";
      if (missing.length) {
        message = t.missing.replace("{f}", joinList(missing.map(labelFor)).toLowerCase());
      } else if (tooSoon) {
        message = t.lead.replace("{h}", leadHours());
      }

      if (ui.wa && ui.cta) {
        ui.wa.textContent = ui.cta.hidden ? t.waOnly : ui.waText;
      }

      ui.warn.textContent = message;
      ui.warn.hidden = !message;
      if (tooSoon) {
        ui.warn.className = "wnf-warn wnf-warn-lead";
      } else if (missing.length && state.attempted) {
        ui.warn.className = "wnf-warn wnf-warn-bad";
      } else {
        ui.warn.className = "wnf-warn";
      }
      ui.cta.setAttribute("aria-disabled", message ? "true" : "false");
    }

    /**
     * Take the customer to the box that is stopping them, wherever it is on
     * the page. Marks every empty box red, then scrolls to the first one and
     * puts the cursor in it.
     */
    function focusMissing() {
      state.attempted = true;
      applyLead();

      var keys = requiredKeys();
      var target = null;

      for (var i = 0; i < keys.length; i++) {
        if (!state[keys[i]] && ui[keys[i] + "Input"]) {
          target = ui[keys[i] + "Input"];
          break;
        }
      }
      if (!target) {
        // Everything is filled in, so the trip itself is too soon.
        target = ui.dateInput || ui.warn;
      }
      if (!target) { return; }

      var still = window.matchMedia && window.matchMedia("(prefers-reduced-motion: reduce)").matches;
      try {
        target.scrollIntoView({ behavior: still ? "auto" : "smooth", block: "center" });
      } catch (e) {
        target.scrollIntoView();
      }
      if (target.focus) {
        try { target.focus({ preventScroll: true }); } catch (e2) { target.focus(); }
      }
    }

    /* ---------------- updates ---------------- */

    function update() {
      var o = offer();

      cars().forEach(function (c) {
        var ref = ui.carBtns[c.id];
        if (!ref) { return; }
        ref.btn.setAttribute("aria-pressed", c.id === state.car ? "true" : "false");
        ref.price.textContent = money(paid(c.id)) + " " + t.cur;
      });

      ui.amountNum.textContent = money(paid(state.car));

      var badge = offerBadge();
      var applied = offerPercent(state.car) > 0;

      if (badge) {
        // The struck-through "was" price only makes sense once something has
        // actually come off. While the badge is still advertising the window,
        // the price on screen IS the normal price.
        if (applied) {
          ui.was.textContent = t.was + money(full(state.car)) + " " + t.cur;
          ui.was.hidden = false;
        } else {
          ui.was.textContent = "";
          ui.was.hidden = true;
        }
        ui.badge.textContent = badge.text;
        ui.badge.hidden = false;
      } else {
        ui.was.textContent = "";
        ui.was.hidden = true;
        ui.badge.textContent = "";
        ui.badge.hidden = true;
      }

      // Per-hour breakdown, so a 12-hour block reads as the deal it is.
      var block = option();
      var hrs = block && block.hours ? Number(block.hours) : 0;
      if (hrs > 0) {
        ui.perHour.textContent = t.perHour
          // Two places: this is a guide figure, not a charge.
          .replace("{p}", money(paid(state.car) / hrs, 2))
          .replace("{c}", t.cur);
        ui.perHour.hidden = false;
      } else {
        ui.perHour.textContent = "";
        ui.perHour.hidden = true;
      }

      // What it costs to run over. Answering this up front is cheaper than
      // arguing about it at the end of the night.
      var xh = svc().extra_hour || {};
      var extraText = "";

      if (xh[state.car]) {
        extraText = t.extraHour
          .replace("{p}", money(xh[state.car]))
          .replace("{c}", t.cur);
      } else if (has("trip") && state.trip === "return" && returnFactor() < 2) {
        // Name the saving. A return that is cheaper than two one-ways is a
        // reason to book the return, and silence wastes it.
        var twoWays = base(state.car) * 2;
        var saving = twoWays - full(state.car);
        if (saving > 0) {
          extraText = t.saving
            .replace("{p}", money(saving))
            .replace("{c}", t.cur);
        }
      }

      ui.extra.textContent = extraText;
      ui.extra.hidden = !extraText;

      var o2 = option();
      var fullLabel = optFull(o2);
      var showHint = fullLabel && fullLabel !== optShort(o2);
      ui.hint.textContent = showHint ? fullLabel : "";
      ui.hint.hidden = !showHint;

      var cap = capacityLine(state.car);
      ui.cap.textContent = cap;
      ui.cap.hidden = !cap;

      var model = carModel(state.car);
      var p = product(state.car);
      if (p.img && model) {
        ui.see.textContent = t.see + " " + model;
        ui.see.hidden = false;
      } else {
        ui.see.hidden = true;
      }

      // "Landing time" only makes sense coming from the airport.
      if (ui.ftimeLabel) {
        ui.ftimeLabel.textContent = state.dir === "to" ? t.ftimeTo : t.ftime;
      }

      applyLead();
      updateLinks();
    }

    /*
     * "Book this car" no longer goes to a product page. It hands the trip to
     * the plugin, which prices it again on the server, puts it in the cart and
     * drops the customer on checkout with everything filled in. No price is
     * ever sent — only what was asked for.
     */
    function bookLink() {
      var q = [
        ["wnf_book", "1"],
        ["svc", state.svc],
        ["opt", state.opt],
        ["car", state.car],
        ["lang", lang]
      ];
      if (has("direction")) { q.push(["dir", state.dir]); }
      if (has("trip")) { q.push(["trip", state.trip]); }
      if (state.date) { q.push(["date", state.date]); }
      if (state.time) { q.push(["time", state.time]); }
      if (state.flight) { q.push(["flight", state.flight]); }
      if (state.ftime) { q.push(["ftime", state.ftime]); }
      if (state.pickup) { q.push(["pickup", state.pickup]); }
      if (state.dropoff) { q.push(["dropoff", state.dropoff]); }

      var parts = q.map(function (kv) {
        return encodeURIComponent(kv[0]) + "=" + encodeURIComponent(kv[1] == null ? "" : kv[1]);
      });
      return cfg.book_url + "?" + parts.join("&");
    }

    function updateLinks() {
      /*
       * With WooCommerce present every car is bookable, because the booking
       * attaches to one hidden product rather than a product per vehicle.
       * Without it, fall back to the old product page, and if a car has no
       * product either, hide the button rather than dead-end the customer.
       */
      if (cfg.book_url) {
        ui.cta.setAttribute("href", bookLink());
        ui.cta.hidden = false;
      } else {
        var p = product(state.car);
        if (p.url) {
          ui.cta.setAttribute("href", p.url);
          ui.cta.hidden = false;
        } else {
          ui.cta.removeAttribute("href");
          ui.cta.hidden = true;
        }
      }

      ui.wa.setAttribute("href", waLink());
    }

    function tripLine() {
      var parts = [];
      if (has("direction")) {
        parts.push(dirLabel(state.dir));
      }
      var o = option();
      if (o) { parts.push(optShort(o)); }
      if (has("trip")) {
        parts.push(state.trip === "return" ? t.returnT : t.oneway);
      }
      return parts.join(" — ");
    }

    function waLink() {
      var lines = [];
      lines.push(t.hi);
      lines.push(t.lbl.service + ": " + (svc()[lang] || svc().en));
      lines.push(t.lbl.trip + ": " + tripLine());
      lines.push(t.lbl.car + ": " + carName(state.car) + (carModel(state.car) ? " (" + carModel(state.car) + ")" : ""));
      lines.push(t.lbl.price + ": " + money(paid(state.car)) + " " + t.cur);

      if (state.date) { lines.push(t.date + ": " + state.date); }
      if (state.flight) { lines.push(t.flight + ": " + state.flight); }
      if (state.ftime) {
        lines.push((state.dir === "to" ? t.ftimeTo : t.ftime) + ": " + state.ftime);
      }
      if (state.time) { lines.push(t.time + ": " + state.time); }
      if (state.pickup) { lines.push(t.pickup + ": " + state.pickup); }
      if (state.dropoff) { lines.push(t.dropoff + ": " + state.dropoff); }

      var num = String(D.whatsapp || "").replace(/\D/g, "");
      return "https://wa.me/" + num + "?text=" + encodeURIComponent(lines.join("\n"));
    }

    function openPhoto() {
      var p = product(state.car);
      if (!p.img) { return; }
      ui.lbImg.setAttribute("src", p.img);
      ui.lbImg.setAttribute("alt", carModel(state.car) || carName(state.car));
      ui.lbName.textContent = carModel(state.car) || p.name || "";
      ui.lb.hidden = false;
    }

    function closePhoto() {
      ui.lb.hidden = true;
      if (ui.see && !ui.see.hidden) { ui.see.focus(); }
    }

    function emit(name) {
      push(name, {
        service: state.svc,
        option: state.opt,
        car: state.car,
        direction: has("direction") ? state.dir : null,
        trip: has("trip") ? state.trip : null,
        price_full: full(state.car),
        price_paid: paid(state.car),
        offer_applied: offerPercent(state.car) > 0,
        currency: D.currency || "BHD"
      });
    }

    document.addEventListener("keydown", function (e) {
      if (e.key === "Escape" && ui.lb && !ui.lb.hidden) { closePhoto(); }
    });

    build();
    emit("wanaan_fare_view");
  }

  function init() {
    var nodes = document.querySelectorAll(".wnf-root[data-wnf]");
    Array.prototype.forEach.call(nodes, function (n) {
      try { Widget(n); } catch (e) { /* one broken widget must not stop the others */ }
    });
  }

  if (document.readyState === "loading") {
    document.addEventListener("DOMContentLoaded", init);
  } else {
    init();
  }
})();
