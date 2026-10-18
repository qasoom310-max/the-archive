# Wanaan Border Offers

A search box for rides between Bahrain and Saudi Arabia, laid out like a flight search:
round trip / one way, passengers, time on the road (about 1 to 12 hours), pick-up country ⇄ drop-off country, departure and return
days (with ‹ › to step a day), then every departure hour of the day from 00:00 to 23:00.

The Wanaan ERP knows where every booked car will be. When a car has to cross the border
empty anyway — it dropped someone in Saudi and must come back, or it is going out to collect
someone there — the hours it can carry a passenger are shown with a discount (30% by default).
Example: a car arrives in Saudi at 03:00; a customer wanting to leave Saudi at 02:00 is told
"Leave at 03:00 instead and save 30%".

The customer picks a time each way and books on WhatsApp; the message says which hours are
the empty-car offer, so the office applies the discount when it books the trip in the ERP.
Once that trip is booked, the car is no longer on offer.

The time on the road is the customer's own estimate (Khobar is about 1 hour, Riyadh about 5).
A customer riding a car that is going out to collect someone must arrive before that pick-up,
and one riding a car home must arrive before its next job, so a longer trip leaves earlier.
The summary and the WhatsApp message show the arrival time, and a same-day return cannot be
picked before the outward trip arrives.

## Install

1. Plugins → Add New → Upload Plugin → `wanaan-border-offers.zip` → Activate.
2. `WANAAN_PORTAL_SECRET` must be defined in wp-config.php (it already is for the other Wanaan
   plugins).
3. Settings → Border offers: check the ERP address and database number (7 for Wanaan), then
   "Ask the ERP now".
4. Put `[wanaan_border_offers]` on a page (Elementor: the Shortcode widget).

Shortcode options: `lang="ar"` / `lang="en"` (otherwise the site language), `theme="dark"`,
`from="BH" to="SA"`, `trip="oneway"`, `hours="5"` (the time on the road shown first, default 2).

## The rules live in the ERP

Fares → Settings → "Empty car discount": on/off, the discount, the drive time between the
countries, how long a car may wait in the other country, and the least notice for the offer.

## Tests

From this folder: `node --test tests/core.test.js tests/app.test.js` (the second runs the box in
headless Chrome or Edge). The contract with the ERP is tested in the ERP's PHPUnit suite
(`BorderOffersPluginTest`). `tests/preview.html?state=results&frame=390` shows the box at phone
width.
