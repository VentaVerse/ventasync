<?php

namespace App\Support\Fulfilment;

/**
 * What was just packed, for the confirmation that follows packing.
 *
 * The Packed modal used to show an order number and three buttons. It is the
 * last checkpoint before a waybill prints, and it restated nothing a packer
 * could check against the box in their hand - which makes it a confirmation
 * of a number nobody has memorised. Clicking the row above yours and clicking
 * yours produced the identical screen.
 *
 * This is resolved on the SERVER, not from the row in the page, for a reason
 * that is easy to miss: packing moves the order out of the To Pack tab, so by
 * the time the modal opens the row may not be in the list any more. Reading
 * the DOM would work in testing, where you pack from the All tab, and return
 * nothing in the warehouse, where you pack from the queue.
 */
class PackedParcel
{
    /**
     * @return array{
     *     order_id: string,
     *     items: array<int, array<string, mixed>>,
     *     unit_count: int,
     *     line_count: int,
     *     courier: ?string,
     *     tracking: ?string,
     *     buyer: ?string,
     *     destination: ?string
     * }|null  null when the order cannot be resolved, so the modal falls back
     *         to the order number alone rather than rendering a false parcel
     */
    public static function fromOrder(?object $order, string $orderId): ?array
    {
        if ($order === null || $orderId === '') {
            return null;
        }

        $raw = is_array($order->raw ?? null) ? $order->raw : [];
        $detail = (isset($raw['_detail']) && is_array($raw['_detail'])) ? $raw['_detail'] : [];

        $firstItemRaw = [];
        $items = [];
        if (($order->products ?? null) && $order->products->isNotEmpty()) {
            $items = $order->products->toArray();
            $fir = $order->products->first()->raw ?? null;
            if (is_array($fir)) {
                $firstItemRaw = $fir;
            }
        }
        if ($items === []) {
            $items = $raw['order_items'] ?? $raw['items'] ?? $raw['orderItems'] ?? [];
            if (! is_array($items)) {
                $items = [];
            }
        }

        // Same precedence the row uses: the first item's raw, then the order
        // detail, then the order raw. Lazada puts the courier in a different
        // one of the three depending on which endpoint answered.
        $pick = function (array $keys) use ($firstItemRaw, $detail, $raw) {
            foreach ([$firstItemRaw, $detail, $raw] as $src) {
                foreach ($keys as $k) {
                    $v = $src[$k] ?? null;
                    if (is_array($v)) {
                        $v = implode(', ', $v);
                    }
                    if (is_string($v) && trim($v) !== '') {
                        return trim($v);
                    }
                }
            }

            return null;
        };

        $grouped = OrderItemGrouper::group($items);

        // Enough of the address to tell two orders apart, and no more. A
        // packing station is not a private place and the full address is one
        // click away on the order page.
        $city = $pick(['address_shipping.city', 'city']);
        if ($city === null && isset($raw['address_shipping']) && is_array($raw['address_shipping'])) {
            $addr = $raw['address_shipping'];
            $city = null;
            foreach (['city', 'address3', 'address4'] as $k) {
                if (is_string($addr[$k] ?? null) && trim($addr[$k]) !== '') {
                    $city = trim($addr[$k]);
                    break;
                }
            }
        }

        return [
            'order_id' => $orderId,
            'items' => $grouped,
            'unit_count' => OrderItemGrouper::unitCount($grouped),
            'line_count' => count($grouped),
            'courier' => $pick(['shipment_provider', 'shipping_provider', 'shipping_provider_name']),
            'tracking' => $pick(['tracking_code', 'tracking_number', 'tracking_code_pre']),
            'buyer' => $pick(['customer_first_name', 'buyer_username', 'customer_name']),
            'destination' => $city,
        ];
    }
}
