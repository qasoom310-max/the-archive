# Wanaan Service Order (WordPress plugin)

Receives limousine **service orders** from the Wanaan ERP, shows the customer a
private **Service Order** page with a terms-and-conditions consent, takes payment
through **WooCommerce + Tap**, and reports the paid status back to the ERP.

This is the WordPress half of the ERP → WordPress → Tap integration. The Laravel
half lives in `Modules/Limousine/` (`ServiceOrderPortalClient`,
`PaymentCallbackController`, `PortalSignature`).

---

## What it does

1. The ERP agent raises a **payment link** on a trip. The ERP `POST`s the service
   order to `POST /wp-json/wanaan/v1/booking` (HMAC-signed).
2. This plugin stores it (idempotently, keyed on the ERP `erp_payment_id`) and
   answers with a private URL `https://<site>/service-order/{token}`.
3. The customer opens the link, sees the Service Order (the same layout as the ERP
   PDF), ticks **“I read and understand the terms and conditions”**, and presses
   **Pay now**.
4. The plugin records consent (UTC time + IP + user agent), creates a WooCommerce
   order with a single “Limousine Service” line for the exact amount, and sends the
   customer to that order’s **Tap** checkout.
5. When WooCommerce marks the order paid, the plugin `POST`s a signed callback to
   the ERP `POST /limousine/payment-callback`, which credits the booking and issues
   the receipt. Failed callbacks retry with backoff via WP-Cron.

## Requirements

- WordPress 6.0+, PHP 7.4+
- WooCommerce active, with the **WooCommerce – Tap WebConnect** gateway configured
  (keep Tap in **TEST mode** until the end-to-end flow is verified)

## Install

1. Copy the `wanaan-service-order/` folder into `wp-content/plugins/`.
2. **Set the shared secret** in `wp-config.php` (above the “stop editing” line) —
   the *same* string the ERP holds under Settings → Service Portal:

   ```php
   define( 'WANAAN_PORTAL_SECRET', 'paste-the-same-secret-here' );
   ```

   The secret is **never** stored in the database or in plugin files.
3. Activate **Wanaan Service Order** in *Plugins*. Activation creates the
   `{prefix}wanaan_service_orders` table and the `/service-order/{token}` route.
   (If a link 404s right after activating, open *Settings → Permalinks* and click
   *Save* once to flush rewrite rules.)
4. Open **Service Orders** in the admin menu and set the **ERP callback URL** to
   the ERP base, e.g. `https://erp.wanaan-bh.com`.
5. Give the ERP the **REST endpoint** shown on that screen
   (`https://<site>/wp-json/wanaan/v1/booking`) and the same shared secret — enter
   both in the ERP under **Settings → Service Portal**, then turn the portal **on**.

## Security

- Both directions authenticate with `X-Wanaan-Timestamp` + `X-Wanaan-Signature`,
  where the signature is `hash_hmac('sha256', "$timestamp.$rawBody", $secret)`,
  the timestamp must be fresh within **5 minutes**, and comparison is constant-time.
- The public page is addressed by an **unguessable 32-char token** and sent
  `noindex, nofollow`.
- The REST receiver and the ERP callback are both **idempotent** — a replayed or
  retried request never double-charges or double-credits.
- The admin screen is guarded by the `manage_woocommerce` capability + nonces.

## Uninstall / rollback

- **Deactivating** the plugin removes only the rewrite rule; the table and its
  payment history are kept.
- To remove the plugin’s data as well, drop the table manually:
  `DROP TABLE {prefix}wanaan_service_orders;` and delete the
  `wanaan_so_callback_url` and `wanaan_so_schema_version` options.
- No WooCommerce orders are deleted by uninstalling — they are normal WC orders.

## Go-live checklist (do NOT skip)

1. **Back up first** — WordPress files + database, ERP code + a `mysqldump` of the
   `wanaan` database, stored outside the web root, with restore commands written
   down.
2. Install with the ERP portal switch **OFF**. Keep **Tap in TEST mode**.
3. Turn the ERP portal on, raise **one real booking with your own phone and a small
   amount**, pay it end-to-end, confirm the ERP shows it paid with a receipt.
4. Only then flip Tap to live and deploy during quiet hours.
