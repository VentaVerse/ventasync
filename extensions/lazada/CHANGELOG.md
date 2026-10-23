# Lazada changelog

## 0.31.0 (2026-10-23)

### New features

- Listing templates, and Apply template on the Products page with a preview.
- A variation can have its own price on this store, beside the Master Catalog price.
- Product groups have their own page, with their products and a listing template.
- Brands refresh from the store on the template and the Edit listing page.
- Phone app: order lines show the photo of the variation that was ordered.

### Updates

- A send reads only the listing; product groups are filters with no settings of their own.
- The order list shows the store's own status. A status the status map does not know no longer turns a sales order into Pending, and the map marks unmapped statuses.
- The Products page shows the Lazada ID in its own column again.
- More improvements.

### Bug fixes

- The buyer's note was always left empty on the sales order.
- The price push now sends variation prices; before, only a full send did.
- Unlink on a product group page now lets go of the Lazada item; before, stock kept going to Lazada.
- Link on a row refuses a Lazada item that another product already holds.

## 0.30.0 (2026-10-07)

- First numbered release, opening the closed beta.
