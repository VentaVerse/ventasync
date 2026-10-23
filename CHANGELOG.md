# Changelog

## 0.31.0 (2026-10-23)

- Settings > Mail offers SMTP and PHP Mail, and a new install starts on SMTP. Run `php artisan migrate`.
- VentaCart no longer fetches the store's brand list: a product's brand comes from the Master Catalog and the storefront creates it if it is missing. Connecting a VentaCart store runs Categories and Order statuses. `ch_ventacart_brands` is dropped; run `php artisan migrate`.
- TikTok listing templates have a Brand, searched from TikTok Shop like the Edit listing page, and applying the template gives it to the listing. Run `php artisan migrate`.
- The listing template and the Edit listing page refresh the same lists from the store: categories on every channel, brands on Lazada, couriers on Shopee. Shopee couriers switched off in Seller Centre stay listed and say so.
- Catalog > Listing Templates is a list with Edit and Delete, like Description Templates and Watermarks. A description template, watermark or listing template that something still uses cannot be deleted; each list shows what uses it.
- A listing's markup can no longer be below zero on any channel (Shopee, TikTok, WooCommerce and VentaCart used to allow it). A discount belongs to a promotion.
- A product group remembers its listing template (`listing_template_id`). Run `php artisan migrate`.
- Fulfillment shows the buyer's checkout note as its own line under each order's header, and the Arrange shipment packing check repeats it.
- Each variation can have its own price per store (`ch_listing_variation_prices`). Run `php artisan migrate`; Lazada's saved variation prices move over.
- Listing templates and one way to send. Each store has Catalog > Listing Templates: named sets of a listing's category, brand, couriers, category attributes, price rule and watermark, applied to ticked products from the Products page with a preview that keeps each product's own values unless Replace is chosen. A send reads only the listing: a product group no longer fills in a blank field, and a product group is now a name and its products, a filter on the Products page, with no settings, page or send of its own. Sending several products names any note per product (pictures left out, variation names not changed). TikTok's stock and price automations now cover linked products outside any group. Existing installs run php artisan migrate, which copies each group's values into its products' blank listing fields and into a listing template of the same name.
- Order PDF and invoice numbers. The order page has View PDF (opens in a new tab, titled ORDER until a number is given, INVOICE after) and Generate invoice number, which gives the next number in one running series and keeps it on the order. Settings, Taxes: VAT or Non-VAT, TIN with branch code, business style and tax rates with one default. Settings, Invoices: the number prefix and whether the logo prints. Orders have a Customer TIN, entered in Edit order under Customer. Existing installs run php artisan migrate.
- Phone app: each order line shows the photo of the variation that was ordered (the variation's own Master Catalog photo, then the store's photo for the line, then the product's main photo), and the order lists use the first line's. Photos come as the resized copies the web pages use.
- One word for each thing: Customer (never buyer), Sales order (never ERP), Created, Total, Tracking, Telephone, Note and Customer note, Enabled. Database: each store order links to its sales order by sales_order_id; orders.comment is customer_note; order history, payments, purchasing and petty cash say note; settings and suppliers say telephone; API clients say enabled. Existing installs run php artisan migrate. REST API and phone app: new fields customer_name, customer_note, note, shipping_telephone and telephone keys; the old buyer_name, comment, notes, internal_notes, shipping_phone and phone keep working until 0.32.0.
- Names in the code and database say what they hold: "channel" wherever every channel is meant (marketplace means only Shopee, Lazada and TikTok), "stock" for stock, "fulfillment" in American spelling, and the OpenCart leftovers on products and orders are gone. Existing installs run php artisan migrate. CONTRIBUTING.md has the naming rules.
- Phone app and REST API: new addresses /channels, /channels/{channel}/stores, /channels/{channel}/orders, /channels/ventacart/stores/{store}/orders and /stock, and new fields channel_order_id, sku, fulfillment, pending_by_channel and pending_by_store. The old addresses and fields (/marketplace-config, /marketplace/..., /ventacart/{store}/..., /inventory, /fulfilment/packing-check, marketplace_order_id, model, fulfilment) keep working until 0.32.0. The REST API order list filters by channel; source still works until then.
- The one-time command orders:normalize-marketplace-fees is removed; order syncs already write each channel's fees.
- Profit counts the shipping the customer paid as income on own-store orders (VentaCart, OpenCart, WooCommerce, Shopify and manual orders); the order page shows it as its own line. Shopee, Lazada and TikTok figures do not change.
- Database cleanup: plain table names and one word for each idea. Existing installs upgrade with php artisan ventasync:upgrade-database.
- Five wrong passwords in a row lock an account for 15 minutes, on the web and in the mobile app; an admin can unlock it sooner from Settings, Users. Locks and unlocks are in the activity log.
- On a hosted (cloud) server, store addresses must be public https addresses; private and local addresses are refused, and requests do not follow redirects. Self-hosted installs are unchanged.
- Changing a password signs that person out everywhere else: other browsers, the mobile app and Claude or ChatGPT sign-ins. The browser where it was changed stays signed in.
- Editing an order keeps stock true: adding a product deducts it, changing a quantity moves only the difference, removing a product gives its stock back, and changing the status in the same save gives back exactly what was deducted. Works for orders from any channel edited in VentaSync.
- The AI Assistant extension is now the MCP Server (id mcp); /mcp, sign-ins, connections and permissions carry over.
- Hosted servers follow their plan: VENTASYNC_EXT_* switches decide which extensions run, VENTASYNC_MAX_* values cap products, users, stores, API apps and orders a month, and retention days clear old logs, activity and waybills. Self-hosted installs set none of these and are not limited.
- Settings, Error log no longer fills with notices that Laravel and Symfony silence on purpose; real warnings and errors still show.

## 0.30.0 (2026-10-07)

- First numbered release, opening the closed beta.
- Master Catalog with variations, pictures, watermarks, product groups and a listing per store.
- Orders with their profit, fulfilment with a packing check, returns, reviews, payouts and fees.
- Stock and price pushes, scheduled syncs and a stock history for every change.
- Users, user groups and permissions; a REST API and a sign-in API for mobile apps.
- Extensions switch on and off under Settings, Extensions, with a Refresh button for the server's cache.
