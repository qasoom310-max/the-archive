# Wanaan WhatsApp Number

One WhatsApp number for the whole of wanaan-bh.com.

- Every WhatsApp link (wa.me, api.whatsapp.com, web.whatsapp.com, whatsapp://) — Elementor
  buttons, icons, social icons, text links, and Elementor's JSON copies — points at the one number.
- Numbers listed under "Numbers on your pages to show as the WhatsApp number" are shown as the
  WhatsApp number wherever they appear (text and tel: links), in the same style.
- The hotline is never touched unless you list it.
- Pages are not edited: the swap happens as each page is sent, so switching back is instant.

The number comes from the Wanaan ERP (Fares → Settings → WhatsApp) by default, read over the
signed pricing endpoint with the `WANAAN_PORTAL_SECRET` constant already in wp-config.php. It is
kept for 10 minutes; "Ask the ERP now" on Settings → WhatsApp number refreshes at once. If the ERP
can't be reached, the last number it gave is used, then the typed one.

Install: Plugins → Add New → Upload Plugin → the zip → Activate.
