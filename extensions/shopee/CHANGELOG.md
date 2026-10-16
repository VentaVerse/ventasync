# Shopee changelog

## Unreleased

- Phone app: Shopee order lines show the ordered variation's own photo from the Master Catalog when it has one.
- The order list shows Shopee's own status, written plainly; the sales order status is set by the status map. A status the map does not know no longer turns a sales order into Pending: an existing sales order keeps its status and a new order waits until the status is mapped. The status map lists every status Shopee has sent and marks the unmapped ones Not mapped.
- The order list's header row says Customer, links the sales order beside the order, and the total column is Total. The customer's invoice request is stored as customer_invoice.

## 0.30.0 (2026-10-07)

- First numbered release, opening the closed beta.
