<?php

namespace App\Support\Fulfilment;

class PackedParcel
{
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

        return self::fromItems($grouped, $pick(['shipment_provider', 'shipping_provider', 'shipping_provider_name']), $city) + [
            'order_id' => $orderId,
            'tracking' => $pick(['tracking_code', 'tracking_number', 'tracking_code_pre']),
            'buyer' => $pick(['customer_first_name', 'buyer_username', 'customer_name']),
        ];
    }

    public static function fromItems(array $items, ?string $courier, ?string $destination): array
    {
        $items = array_values(array_filter($items, fn ($i) => is_array($i) && $i !== []));
        $courier = trim((string) $courier);
        $destination = trim((string) $destination);

        return [
            'items' => $items,
            'unit_count' => OrderItemGrouper::unitCount($items),
            'line_count' => count($items),
            'courier' => $courier !== '' ? $courier : null,
            'destination' => $destination !== '' ? $destination : null,
        ];
    }
}
