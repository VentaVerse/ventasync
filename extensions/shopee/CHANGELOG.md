# Shopee changelog

## Unreleased

- Product groups have their own page again: its products (Add products, Remove), and the listing template it uses, applied to its products with the usual preview. Group membership changes only there; the Products page lost its group menu and Move to group / Remove from group. The listing template page switches templates from a dropdown and names the groups that use it.
- The buyer's note (message to seller) is saved on the sales order as its Customer note. Before, it was always left empty.
- A variation can have its own price on each store, typed on the store's Edit listing page beside the Master Catalog price. A blank uses the Master Catalog price, and the store's price rule is added on top either way.
- Catalog > Listing Templates, and Apply template on the Products page's selection bar. A Shopee send reads only the listing; product groups are filters with no settings or send of their own.
- The Products page shows the Shopee ID in its own column again.
- Phone app: Shopee order lines show the ordered variation's own photo from the Master Catalog when it has one.
- The order list shows Shopee's own status, written plainly; the sales order status is set by the status map. A status the map does not know no longer turns a sales order into Pending: an existing sales order keeps its status and a new order waits until the status is mapped. The status map lists every status Shopee has sent and marks the unmapped ones Not mapped.
- The order list's header row says Customer, links the sales order beside the order, and the total column is Total. The customer's invoice request is stored as customer_invoice.

## 0.30.0 (2026-10-07)

- First numbered release, opening the closed beta.
