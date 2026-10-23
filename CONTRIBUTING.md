# Contributing to VentaSync

## Words

VentaSync uses one word for each idea. The screens use the words a seller uses; a few code names differ, and this table says where.

| On screen | In the code | Meaning |
|---|---|---|
| Master Catalog | `products`, `catalog` | The products the business owns. `product` in the code always means a Master Catalog product. |
| Channel | `extensions/<id>/`, `app/Channels`, a `channel` column | A place the business sells: Shopee, Lazada, TikTok Shop, WooCommerce, VentaCart, OpenCart, Shopify. |
| Store | `<Channel>Store` model, `ch_<channel>_stores`, `store_id` | One connected account on a channel. A business can connect several stores to one channel. |
| Products (a store's page) | `listing`: `ch_<channel>_listings`, `ch_<channel>_listing_links` | A store's own copy of a Master Catalog product: its title, price, category and status on that store. |
| Product Group | `product_group` | A set of listings on one store that share settings. |
| Variation type | `variation_types`, `variation_type_id` | A kind of variation, such as Color or Size. |
| Variation value | `variation_values`, `variation_value_id` | One value of a type, such as Black. |
| Variation | `product_variation_values` (one type), `product_variations` (two types) | One sellable version of a product, with its own SKU, stock and price. |
| Brand | `brands`, `brand_id` | Who makes the product. A channel's own brand list keeps the channel's name, such as `ch_lazada_brands`. |
| Supplier | `suppliers`, `supplier_id` | Who the business buys from. |
| Warehouse | `warehouses`, `warehouse_id` | Where stock sits. |
| Extension | `extensions/<id>/`, an `extension` column | A packaged add-on. Every channel is an extension; not every extension is a channel. |
| Stock | `quantity`, `warehouse_stock`, `stock_history` | How many units of a product or variation there are, in total or in one warehouse. The menu groups the stock pages under Inventory; nothing else says inventory. |
| Marketplace | `MarketplaceVerdict`, `ch_marketplace_reviews` | Only Shopee, Lazada and TikTok Shop. Anything that applies to every channel says channel. |
| Customer | `customer_name`, `customer_note`, `CustomerLedger` | The person who ordered. Never buyer, except in a marketplace's own fields such as Shopee's `buyer_username`. |
| Sales order | `orders`, `sales_order_id` | VentaSync's own record of an order. A store's order links to it by `sales_order_id`. Never ERP: VentaSync is the product's name. |
| Created | `order_created_at` | When an order was placed on its store. Never Placed, Date or Order date. |
| Total | `total` | What an order comes to. Amount is only a sum of money moved, such as a payment. |
| Tracking | `tracking_number` | The courier's tracking number. A waybill is the printed label, not the number. |
| Telephone | `telephone` | A telephone number. Never phone. |
| Note | `note`, `internal_note` | Something our staff wrote. What the customer wrote on an order is the Customer note (`customer_note`). Comment is only a review's words. |
| Enabled | `enabled` | The on/off switch on a record. Never active or is_active. |
| Logistics | Shopee's and TikTok's menu | Kept on those two channels' menus because their seller centers say it. Everywhere else the company that carries a parcel is the Courier. |

Every channel table starts with `ch_`, so a channel's tables sit together.

Marketplace ids keep the marketplace's own name, so they match its API documentation: `shopee_model_id`, `tiktok_sku_id`.

An option and a variation are the same thing. "Option" is OpenCart's word, carried into VentaSync from it; VentaSync uses "variation" in its code, tables and screens because more sellers know it. `option` remains only in other systems' own field names: OpenCart's options, Shopee's tier `option_list`, the `option` of a WooCommerce or Shopify attribute, and the VentaCart storefront's `option_name`.

## Naming rules

1. **One word per idea.** The table above is the authority. A new idea gets its word here first, then in the code. Never add a synonym.
2. **A name says what it holds.** If a table, column, key or variable holds something other than its name says, rename it. Do not explain it with a comment.
3. **No dead columns or code.** When the code stops reading a column, the same change drops it. Nothing writes a blank just to fill a column.
4. **Other systems' words stay at the edge.** OpenCart's, Shopee's or the VentaCart storefront's own names appear only in the payloads VentaSync sends and receives. Their ids carry the channel's name (`shopee_item_id`, `opencart_product_id`). Inside VentaSync, only the words in the table above.
5. **A rename is finished in one change:** table, column, model, variables, routes, screens, API, setup migration and upgrade step. The outside doors (the REST API and the mobile app API) keep the old name as an alias, and the changelog names the release that removes it.
6. **American English** in names and screens: catalog, color, canceled, fulfillment. A test checks this.
7. **Class names spell each brand its own way:** `TikTok`, `WooCommerce`, `OpenCart`, `VentaCart`.

A test reads the database layout and fails on retired words (marketplace outside the three marketplaces, `oc_`, inventory, variant, vendor, manufacturer, fulfilment and the dropped OpenCart columns), so a synonym cannot come back unnoticed.

## Changelog

Each release in `CHANGELOG.md`, and in each extension's own `CHANGELOG.md`, has up to three sections. Leave out a section that is empty.

- **New features**: something a person can do that they could not do before. Examples: a new page, action, setting, report, channel or API endpoint.
- **Updates**: something that already existed now works differently, and a person who does not read about it would be surprised or would need to act. Examples: a page moved, a rule changed, a number is calculated differently, something was removed or renamed in the API or the database.
- **Bug fixes**: something gave a wrong result, lost data or failed, and now works as intended.

Everything else is one line, `- Some minor improvements.`, with nothing listed. That covers labels, wording, layout, a default that only a new install sees, a column put back, tests and internal code.

Write each line for the person using VentaSync, in one sentence. Do not add upgrade or install steps.
