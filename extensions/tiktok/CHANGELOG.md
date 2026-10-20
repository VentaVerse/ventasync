# TikTok Shop changelog

## Unreleased

- The Products page shows the TikTok ID in its own column again. Link on a product group row runs the same check as Link IDs, which refuses a TikTok Shop product another product already holds.
- Phone app: TikTok order lines show the ordered variation's own photo from the Master Catalog when it has one.
- The order list shows TikTok's own status, written plainly; the sales order status is set by the status map. A status the map does not know no longer turns a sales order into Pending: an existing sales order keeps its status and a new order waits until the status is mapped. The status map lists every status TikTok has sent and marks the unmapped ones Not mapped.
- The order list's header row says Customer, links the sales order beside the order, and the total column is Total. The order's customer name is stored as customer_name.

## 0.30.0 (2026-10-07)

- First numbered release, opening the closed beta.
