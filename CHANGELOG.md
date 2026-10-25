# Changelog

## Unreleased

### New features

- `php artisan ventasync:reset-link <username>` prints a one-time link, valid for 30 minutes, where that user sets a new username and password. It needs no email, for a forgotten administrator password on a server without mail.

### Bug fixes

- TikTok Shop orders and returns showed a time 8 hours earlier than they were placed.

### Updates

- Some minor improvements.

## 2.0.0-beta.1 (2026-10-23)

2.0.0 is the first public release; 1.x ran privately as the alpha.

### New features

- Listing templates per store: category, brand, couriers, attributes, price rule and watermark, applied to selected products with a preview.
- A variation can have its own price on each store.
- The listing template and the Edit listing page refresh categories, Lazada brands and Shopee couriers from the store. Couriers switched off in Seller Centre are labeled.
- TikTok listing templates have a brand.
- Order PDF and invoice numbers, with VAT or Non-VAT, TIN and tax rates, and a Customer TIN on orders.
- The buyer's checkout note shows in Fulfillment and in the packing check.
- Five wrong passwords lock an account for 15 minutes; an admin can unlock it from Settings, Users.
- Phone app: order lines show the photo of the variation that was ordered.
- Hosted servers follow their plan for extensions, limits and how long logs are kept. Self-hosted installs are not limited.

### Updates

- Product groups are a name, their products and a listing template. A send reads only the listing.
- Listing Templates, Description Templates and Watermarks are lists, and a template in use cannot be deleted.
- VentaCart takes a product's brand from the Master Catalog and no longer fetches the store's brand list.
- Profit counts the shipping the customer paid on own-store orders.
- Changing a password signs that person out everywhere else.
- On a hosted server, store addresses must be public https addresses. Self-hosted installs are unchanged.
- The AI Assistant extension is now the MCP Server.
- Plain table names and one word for each thing (Customer, Sales order, Note, Telephone, Stock, Channel). The REST API and phone app keep the old field names and addresses until 2.0.0.
- Some minor improvements.

### Bug fixes

- Editing an order keeps stock correct when items are added, removed or changed, or the status changes in the same save.
- A listing's markup can no longer go below zero.

## 0.30.0 (2026-10-07)

### New features

- First numbered release, opening the closed beta.
- Master Catalog with variations, pictures, watermarks, product groups and a listing per store.
- Orders with their profit, fulfillment with a packing check, returns, reviews, payouts and fees.
- Stock and price pushes, scheduled syncs and a stock history for every change.
- Users, user groups and permissions; a REST API and a sign-in API for mobile apps.
- Extensions switch on and off under Settings, Extensions, with a Refresh button for the server cache.
