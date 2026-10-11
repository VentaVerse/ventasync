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

Every channel table starts with `ch_`, so a channel's tables sit together.

Marketplace ids keep the marketplace's own name, so they match its API documentation: `shopee_model_id`, `tiktok_sku_id`.

An option and a variation are the same thing. "Option" is OpenCart's word, carried into VentaSync from it; VentaSync uses "variation" in its code, tables and screens because more sellers know it. `option` remains only in other systems' own field names: OpenCart's options, Shopee's tier `option_list`, the `option` of a WooCommerce or Shopify attribute, and the VentaCart storefront's `option_name`.

## Spelling and naming

- American English everywhere: catalog, color, recognize, behavior. A test checks this.
- Class names spell each brand its own way: `TikTok`, `WooCommerce`, `OpenCart`, `VentaCart`.
- One word per idea. When you touch a module, use the word in the table above; never add a new synonym.
