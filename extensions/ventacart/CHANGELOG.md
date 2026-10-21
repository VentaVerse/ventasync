# VentaCart changelog

## Unreleased

- Product groups have their own page again: its products (Add products, Remove), and the listing template it uses, applied to its products with the usual preview. Group membership changes only there; the Products page lost its group menu and Move to group / Remove from group. The listing template page switches templates from a dropdown and names the groups that use it.
- A variation can have its own price on each store, typed on the store's Edit listing page beside the Master Catalog price. A blank uses the Master Catalog price, and the store's price rule is added on top either way.
- Catalog > Listing Templates, and Apply template on the Products page's selection bar. A VentaCart send reads only the listing; product groups are filters with no settings or send of their own.
- The Products page shows the VentaCart ID in its own column again.
- Phone app: VentaCart order lines show the ordered variation's own photo from the Master Catalog when it has one.
- The order list draws the same header row as every channel: Customer, Order, the sales order beside it, and Created on the right; the Date column is gone and the total column is Total.
- On a hosted (cloud) server, store addresses must be public https addresses; private and local addresses are refused, and requests do not follow redirects. Self-hosted installs are unchanged.

## 0.30.0 (2026-10-07)

- First numbered release, opening the closed beta.
