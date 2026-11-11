=== Wanaan Fare Finder ===
Contributors: wanaan
Requires at least: 5.8
Tested up to: 6.6
Requires PHP: 7.2
Stable tag: 2.9.0
License: GPL-2.0-or-later

Shows an instant fare for every Wanaan transport service. Prices come from the
Wanaan ERP, with a local copy so the website never waits on it.

== What it does ==

Drops a fare calculator onto any page. The customer picks where they are going
and which car, and sees a real price in two taps — then either books the
product or sends the whole quote to WhatsApp.

Services covered:

* Airport Transfer — direction, zone, date, flight number and landing time
* Chauffeur in Bahrain — 4, 8 or 12 hour blocks
* Bahrain to Saudi Arabia — destination, direction, one way or return
* City to City in Bahrain — destination zone, pick-up and drop-off
* Luxury Bus with Driver — 6, 8 or 12 hour blocks across a 4-bus fleet
* Bus to Saudi Arabia — five destinations, four buses. Fares are ESTIMATES
  derived from the in-Bahrain rates; replace them before launch

== Shortcodes ==

  [wanaan_fare_finder service="airport"]
  [wanaan_fare_finder service="chauffeur"]
  [wanaan_fare_finder service="ksa"]
  [wanaan_fare_finder service="city"]
  [wanaan_fare_finder service="bus"]
  [wanaan_fare_finder service="ksa_bus"]
  [wanaan_fare_finder service="all"]

Attributes:

* service   airport | chauffeur | ksa | city | bus | ksa_bus | all
            (default: airport)
* lang      en | ar                           (default: follows the site locale)
* theme     dark | light                      (default: dark)
* sticky    1 to make the widget follow the page on desktop (1025px and up).
            On phones it returns to normal flow rather than disappearing
* headline  replaces the default headline
* sub       replaces the default sub-line

The stylesheet and script load only on pages where the shortcode actually ran.

== Where prices come from ==

Settings -> Fare Finder has a Pricing source switch.

*Fare table on this page* — the numbers are edited in WordPress. Works with no
ERP at all.

*Wanaan ERP* — prices are set once in the ERP and this site follows them.
This is the mode that makes it impossible for two pages to quote the same trip
differently.

Add the shared key to wp-config.php:

  define( 'WANAAN_ERP_KEY', 'the-key-from-the-erp' );

The key is never written to the database and never appears on the website.

== The site never waits on the ERP ==

A customer's page load makes no call to erp.wanaan-bh.com. Ever. The plugin
reads from its own copy and refreshes that copy out of band, in this order:

1. Fresh copy, less than 10 minutes old
2. Older copy, served instantly, with a background refresh queued
3. The last good copy received, which never expires
4. The fare table built into the plugin

If the ERP has been offline for a week, customers still see the last prices it
sent. There is no path through the code that shows a blank fare or 0 BHD.

Data that fails validation is rejected and the previous prices are kept, so a
broken ERP response cannot wipe the prices off the site.

== Instant updates ==

The ERP can ping this endpoint whenever a price or offer is saved:

  POST /wp-json/wanaan/v1/pricing/refresh
  Header: X-Wanaan-Key: <the key>
  Body:   {"version": 48}

The ping carries no prices — it only says a new version exists, and the plugin
fetches it itself. A forged ping cannot change a fare.

There is also a read-only check, same key:

  GET /wp-json/wanaan/v1/pricing/status

Without the ping, prices refresh hourly by WP-Cron instead of instantly.

== Tracking ==

Three events are pushed to the dataLayer for GTM:

* wanaan_fare_view        — a fare was shown or changed
* wanaan_fare_book_click  — the booking button was clicked
* wanaan_fare_whatsapp    — the WhatsApp link was clicked

Each carries service, option, car, direction, trip, price_full, price_paid,
offer_applied and currency.

== Changelog ==

= 2.9.0 =
* Pay part now, or in full. At checkout the customer must choose: pay a part
  of the fare now by card and the rest to the driver in the car, or the full
  fare now. 20% for a trip inside Bahrain, 50% for one that leaves Bahrain
  (Bahrain <-> Saudi, by car or bus); both are set in Settings -> Fare Finder.
  The card is charged only the part chosen; the trip price and what is left
  for the car show under the total, on the order and in the emails.
* A booking ignores the Acowebs deposit plugin, so a deposit is never taken
  twice. The ERP is told the trip price and what was actually paid.

= 2.5.2 =
* The chauffeur extra-hour line is off. The rates behind it were never confirmed
  — they were worked out from the four-hour block, not given by Wanaan — and the
  widget shows that line to paying customers. Set the real rates in
  Settings -> Fare Finder and the line comes back on its own

= 2.5.1 =
* The questions now open and close with an arrow, the same way the site's own
  FAQ does — both are built on <details>, so it works with the keyboard and with
  JavaScript off, and the answers stay in the HTML for search engines
* The calculator and the content are one panel now, with a grey line between
  them instead of a gap. Both halves run to the same depth
* FAQ structured data is off unless the shortcode asks for it with
  faq_schema="1". The airport page already carries a FAQPage block from its own
  Elementor accordion, and two on one page is how you get Google to trust
  neither
* Two questions reworded so they no longer repeat what that accordion already
  answers

= 2.5.0 =
* The side panel now carries written content about the service rather than a
  bare fare table: a heading, an intro, four short sections and five common
  questions, with the fares written into the sentences. Search engines get real
  writing about airport transfers instead of a grid of numbers
* The fares and the notice period inside that copy are placeholders filled from
  the live fare table at render time, so the prose can never contradict what the
  widget quotes — change a price in the ERP and the paragraph follows
* Added FAQPage structured data, built from the same questions shown on the page
* Written in English and Arabic. Services with no copy yet keep the fare table
* Arabic no longer has letter-spacing applied to it, which was pulling the
  letters apart at their joins

= 2.4.3 =
* Arabic is set in Tajawal. The font is loaded only on pages where an Arabic
  widget actually renders, so the English pages carry no extra request

= 2.4.2 =
* The pair now fills the width of the section it sits in instead of being capped
  at 1080px, which left dead space either side on a wide screen
* The fare table adapts to the room it actually gets: in a narrow column it
  stays compact, and once the column is wide enough the car models and the BHD
  after each figure come back on their own

= 2.4.1 =
* The price list now sits on the LEFT and the calculator on the right. In
  Arabic the pair mirrors on its own — list on the right, calculator on the left
* Polylang: the widget now asks Polylang which language the page is in rather
  than relying on the site locale, which could still report the default language
  while the visitor was reading the Arabic page. WPML is handled the same way
* The fare table is fully Arabic on the Arabic page: the heading reads
  "الأسعار", fares are shown in د.ب, and the table drops the monospace face,
  which has no real Arabic, in favour of the page's own font

= 2.4.0 =
* Desktop layout: the calculator and the price list now sit side by side, two
  thirds and one third, instead of the table hanging underneath. Phones and
  tablets are untouched — the table stays full width below the card there
* Removed the WANAAN wordmark from inside the widget. The site's own logo is
  already on the page and printing it twice read as a mistake

= 2.3.3 =
* No VAT on bookings. Every service in this widget comes with a driver, and VAT
  applies to self-drive car rental rather than to a driven service, so the
  booking product is now VAT-exempt. A quoted 15 BHD is charged as 15 BHD
  instead of becoming 16.500 at checkout. An existing booking product is
  corrected automatically on upgrade

= 2.3.2 =
* Removed the stray empty ":" line under the booking on cart and checkout. The
  add-ons plugin adds a blank row for a product with no add-ons; bookings no
  longer carry product options, so empty rows are dropped

= 2.3.1 =
* Fixed: the booking link did nothing and left the customer on the home page.
  Plugin files load alphabetically, so this plugin was included before
  WooCommerce and the check for it always failed — the booking handler was never
  registered. It now waits for plugins_loaded

= 2.3.0 =
* Booking now goes straight to checkout. "Book this car" no longer opens a
  product page: the plugin puts the trip in the cart itself and sends the
  customer to checkout with the fare, the car, the date, the flight number and
  the time already filled in. All they do is enter their details and pay
* The fare is recalculated on the server from the plugin's own table at the
  moment of booking. No price travels in the link, so editing the URL changes
  which trip you asked for, never what it costs. An invalid car, zone, direction
  or service is refused outright
* The minimum notice rule is now enforced on the server as well as in the
  browser
* Every booking attaches to one hidden product, "Wanaan Booking", created
  automatically. It carries no price of its own. The per-car product pages no
  longer decide any fare — which removes the mismatch where the sedan airport
  product could only ever charge 15 BHD whatever zone the customer picked
* The trip appears under the line on the cart, the checkout, the order email and
  the order screen, and is also stored as JSON on the line for the ERP to read

= 2.2.1 =
* The fare table now carries its own dark surface. It sits outside the card on
  the page's own background, and 2.2.0 styled it with the card's colours, which
  meant near-white text on a white page. Column headings also wrap instead of
  forcing the table wider than the page

= 2.2.0 =
* SEO: the server now renders the complete fare table as real HTML inside the
  widget, plus schema.org Service/OfferCatalog structured data. Previously the
  widget was pure JavaScript and left an empty div for search engines. The
  interactive card is built above the table rather than replacing it, so people
  get the calculator, crawlers get the prices, and anyone with JavaScript off
  still sees every fare
* The table is a real <table> with a heading, so the page gains readable content
  rather than just a script

= 2.1.1 =
* Restored the dropdown arrow, which 2.1.0's theme armour had removed along with
  the theme's own background images

= 2.1.0 =
* New `sticky="1"` attribute: on desktop the widget follows the customer down
  the page so the fare and booking button are never scrolled away. On phones it
  sits in normal flow instead of vanishing
* Theme armour: Wanaan's theme styles every button globally and was repainting
  every control solid yellow. Higher-specificity rules now hold the widget's own
  colours inside any host theme
* Fixed the vehicle label showing the service's own name ("Airport Transfer")
  where "Choose your car" belongs

= 2.0.0 =
* Six services built. Each declares which vehicles it offers, so a 30-seat
  coaster never appears in the airport widget, and the wording says "bus" where
  a bus is being booked
* Per-service behaviour: airport asks direction, date, flight number and landing
  time; chauffeur shows 4/8/12 hour blocks as buttons with a per-hour breakdown
  and an extra-hour rate; Saudi routes have both directions, a discounted return
  that names the saving, and causeway fees included; city to city takes pick-up
  and drop-off; buses run on their own 6/8/12 hour blocks
* Trip details are required. The booking button stays off until they are filled
  in; pressing it marks what is missing in red and scrolls to the first one
* Minimum notice, 12 hours by default, set in Settings -> Fare Finder. A sooner
  trip cannot be booked online and the customer is pointed at WhatsApp, so an
  urgent job still reaches the team. NOTE: this covers the widget only — the
  date picker on the product page belongs to another plugin and does not yet
  enforce the same rule
* Prices can now be driven by the Wanaan ERP, with a four-step local fallback
* Per-service fields: direction and flight details for airport, hours for
  chauffeur, one way or return for Saudi routes
* Seats and bags shown as a capacity note under each car
* Offers per service, with a start and end date, expiring on their own
* Read-only admin view when the ERP is the source, so prices cannot be edited
  in two places at once
* A service is never rendered unless it is switched on AND has at least one real
  price, so an unpriced service cannot quote a customer 0 BHD

= 1.0.0 =
* First version. Fares edited in WordPress.
