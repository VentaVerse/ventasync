<?php

namespace App\Support\Fulfilment;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

class OrderPrintLists
{
    private const CHANNELS = [
        'shopee' => [
            'model' => \Extensions\shopee\Models\ShopeeOrder::class,
            'ref' => 'order_sn',
            'buyer' => null,
            'label' => 'Shopee',
        ],
        'lazada' => [
            'model' => \Extensions\lazada\Models\LazadaOrder::class,
            'ref' => 'order_id',
            'buyer' => null,
            'label' => 'Lazada',
        ],
        'tiktok' => [
            'model' => \Extensions\tiktok\Models\TikTokOrder::class,
            'ref' => 'order_id',
            'buyer' => 'buyer_name',
            'label' => 'TikTok',
        ],
        'ventacart' => [
            'model' => \Extensions\ventacart\Models\VentaCartOrder::class,
            'ref' => 'ventacart_order_number',
            'buyer' => 'customer_name',
            'label' => 'Storefront',
        ],
        'woocommerce' => [
            'model' => \Extensions\woocommerce\Models\WooOrder::class,
            'ref' => 'woo_order_number',
            'buyer' => 'customer_name',
            'label' => 'WooCommerce',
        ],
    ];

    public static function supports(string $channel): bool
    {
        return array_key_exists($channel, self::CHANNELS) && class_exists(self::CHANNELS[$channel]['model']);
    }

    public static function label(string $channel): string
    {
        return self::CHANNELS[$channel]['label'] ?? 'Orders';
    }

    public static function orders(string $channel, array $ids): Collection
    {
        $spec = self::CHANNELS[$channel];

        $model = $spec['model'];

        $ids = array_slice(array_values(array_unique($ids)), 0, 200);

        if ($ids === []) {
            return $model::query()->whereRaw('1 = 0')->get();
        }

        return $model::query()
            ->whereIn('id', $ids)
            ->with('products')
            ->orderBy('id')
            ->get();
    }

    public static function packing(string $channel, Collection $orders): array
    {
        $spec = self::CHANNELS[$channel];

        return $orders->map(fn (Model $o) => [
            'reference' => (string) ($o->{$spec['ref']} ?? $o->getKey()),
            'buyer' => self::buyerOf($o, $spec['buyer']),
            'items' => self::itemsOf($o),
        ])->all();
    }

    public static function picking(string $channel, Collection $orders): array
    {
        $spec = self::CHANNELS[$channel];
        $lines = [];

        foreach ($orders as $o) {
            $reference = (string) ($o->{$spec['ref']} ?? $o->getKey());

            foreach (self::itemsOf($o) as $item) {
                $key = $item['sku'] !== '' ? 'sku:'.$item['sku'] : 'name:'.mb_strtolower($item['name']);

                if (! isset($lines[$key])) {
                    $lines[$key] = [
                        'sku' => $item['sku'],
                        'name' => $item['name'],
                        'quantity' => 0,
                        'orders' => [],
                    ];
                }

                $lines[$key]['quantity'] += $item['quantity'];

                if (! in_array($reference, $lines[$key]['orders'], true)) {
                    $lines[$key]['orders'][] = $reference;
                }
            }
        }

        uasort($lines, fn (array $a, array $b) => [$a['sku'], $a['name'] ]<=> [$b['sku'], $b['name']]);

        return array_values($lines);
    }

    private static function itemsOf(Model $order): array
    {
        $products = $order->products ?? [];
        $items = [];

        foreach ($products as $p) {
            $name = trim((string) ($p->name ?? ''));

            $variant = trim((string) ($p->variation ?? $p->variant_label ?? ''));
            if ($variant !== '' && $variant !== '-') {
                $name = $name !== '' ? $name.' - '.$variant : $variant;
            }

            $items[] = [
                'sku' => trim((string) ($p->sku ?? '')),
                'name' => $name,
                'quantity' => (int) ($p->quantity ?? 0),
            ];
        }

        return $items;
    }

    private static function buyerOf(Model $order, ?string $column): string
    {
        if ($column !== null) {
            return trim((string) ($order->{$column} ?? ''));
        }

        $raw = $order->raw ?? null;
        $raw = is_string($raw) ? json_decode($raw, true) : $raw;

        if (! is_array($raw)) {
            return '';
        }

        foreach (['buyer_username', 'buyer_user_name', 'customer_first_name', 'customer_name', 'buyer_name'] as $key) {
            if (! empty($raw[$key])) {
                return trim((string) $raw[$key]);
            }
        }

        return '';
    }
}
