# Contributing to VentaSync

## Words

VentaSync uses one word for each idea. The screens use the words a seller uses; a few code names differ, and this table says where.

| On screen | In the code | Meaning |
|---|---|---|
| Master Catalog | `catalog`, `product` | The products the business owns. `product` in the code always means a Master Catalog product. |
| Channel | `extensions/<id>/`, `app/Channels` | A place the business sells: Shopee, Lazada, TikTok Shop, WooCommerce, VentaCart, OpenCart, Shopify. |
| Store | `<Channel>Setting` model, `<channel>_setting_id` | One connected account on a channel. A business can connect several stores to one channel. |
| Products (a store's page) | `listing` | A store's own copy of a Master Catalog product: its title, price, category and status on that store. |
| Product Group | `product_group` | A set of listings on one store that share settings. |
| Variation | `option`, `product_option_value`, `combination` | One sellable version of a product, such as a color or a size. |
| Brand | `brand` in the Master Catalog, `brand` on channels | Who makes the product. |
| Supplier | `supplier` | Who the business buys from. |
| Extension | `extensions/<id>/` | A packaged add-on. Every channel is an extension; not every extension is a channel. |

Marketplace ids keep the marketplace's own name, so they match its API documentation: `shopee_model_id`, `tiktok_sku_id`.

## Spelling and naming

- American English everywhere: catalog, color, recognize, behavior. A test checks this.
- Class names spell each brand its own way: `TikTok`, `WooCommerce`, `OpenCart`, `VentaCart`.
- One word per idea. When you touch a module, use the word in the table above; never add a new synonym.
