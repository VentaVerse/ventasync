# Lazada changelog

## 0.31.0 (2026-10-23)

- Product groups have their own page again: its products (Add products, Remove), and the listing template it uses, applied to its products with the usual preview. Group membership changes only there; the Products page lost its group menu and Move to group / Remove from group. The listing template page switches templates from a dropdown and names the groups that use it.
- The buyer's note (buyer note) is saved on the sales order as its Customer note. Before, it was always left empty.
- Variation prices typed for Lazada now live with the other stores' variation prices, in the variations table on the Edit listing page. The separate "Only on Lazada" price table is gone; the upgrade moves the saved prices over. The price push now sends them too; before, only a full send did.
- Catalog > Listing Templates, and Apply template on the Products page's selection bar. A Lazada send reads only the listing; product groups are filters with no settings or send of their own.
- The Products page shows the Lazada ID in its own column again. Unlink on a product group page now lets go of the Lazada item, the way Unlink on the Products page does; before, the row kept its item ID and stock kept going to Lazada. Link on a row, on either page, and Link IDs on the Products page run the same check as Link IDs on the group page, which refuses an item another product already holds.
- Phone app: Lazada order lines show the ordered variation's own photo from the Master Catalog when it has one.
- The order list shows Lazada's own status, written plainly; the sales order status is set by the status map. A status the map does not know no longer turns a sales order into Pending: an existing sales order keeps its status and a new order waits until the status is mapped. The status map lists every status Lazada has sent and marks the unmapped ones Not mapped.
- The order list's header row says Customer, links the sales order beside the order, and the total column is Total.

## 0.30.0 (2026-10-07)

- First numbered release, opening the closed beta.
