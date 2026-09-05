<?php

namespace App\Support\Fulfilment;

use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;

/**
 * Pick and packing lists over a selection of orders, for any channel.
 *
 * WHY THIS IS ONE CLASS AND NOT FOUR. The four fulfilment lists differ in
 * where an order keeps its reference and its buyer, and in nothing else that
 * matters here: every one of them has a products relation carrying a SKU, a
 * name and a quantity, because that is what an order IS to a warehouse. The
 * per-channel part is therefore a four-line map rather than a class each,
 * and the two documents are built once.
 *
 * The lists differ from each other in a way worth stating, because it is the
 * whole reason there are two:
 *
 *   A PICK LIST is about stock. Three orders wanting the same cable is one
 *   trip to one shelf for three of them, so lines are consolidated by SKU
 *   across the whole selection and the orders each line serves are printed
 *   beside it, so a shortfall can be traced back.
 *
 *   A PACKING LIST is about a parcel. It travels in the box and can only
 *   describe the order it travels with, so it is one sheet per order and
 *   nothing is consolidated.
 *
 * No marketplace call is made. Every channel's sync already writes its order
 * products locally, which is what the single-order lists read first; this
 * only reads them, so printing forty orders costs forty rows of SQL rather
 * than forty API round trips against a rate limit.
 */
class OrderPrintLists
{
    /**
     * Where each channel keeps the two fields this differs on.
     *
     * 'ref' is what the operator would read out loud - the marketplace's own
     * order number - and 'buyer' the person, where the channel records one.
     *
     * @var array<string, array{model: class-string<Model>, ref: string, buyer: ?string, label: string}>
     */
    private const CHANNELS = [
        'shopee' => [
            'model' => \Extensions\shopee\Models\ShopeeOrder::class,
            'ref' => 'order_sn',
            'buyer' => null,          // lives in the raw payload, resolved below
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
        'venta' => [
            'model' => \Extensions\venta\Models\VentaOrder::class,
            'ref' => 'venta_order_number',
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
        return array_key_exists($channel, self::CHANNELS);
    }

    public static function label(string $channel): string
    {
        return self::CHANNELS[$channel]['label'] ?? 'Orders';
    }

    /**
     * The selected orders, with their items, in the order the operator sees
     * them.
     *
     * @param  array<int, int|string>  $ids
     * @return Collection<int, Model>
     */
    public static function orders(string $channel, array $ids): Collection
    {
        $spec = self::CHANNELS[$channel];

        /** @var class-string<Model> $model */
        $model = $spec['model'];

        // Capped, and deliberately: this renders synchronously into a page
        // the operator is waiting on, and a selection of five hundred orders
        // is a report rather than a pick list. The list view pages at fifty,
        // so a whole page of any channel is always printable.
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

    /**
     * One sheet's worth of lines per order, for the packing list.
     *
     * @param  Collection<int, Model>  $orders
     * @return array<int, array{reference: string, buyer: string, items: array<int, array{sku: string, name: string, quantity: int}>}>
     */
    public static function packing(string $channel, Collection $orders): array
    {
        $spec = self::CHANNELS[$channel];

        return $orders->map(fn (Model $o) => [
            'reference' => (string) ($o->{$spec['ref']} ?? $o->getKey()),
            'buyer' => self::buyerOf($o, $spec['buyer']),
            'items' => self::itemsOf($o),
        ])->all();
    }

    /**
     * The whole selection consolidated by SKU, for the pick list.
     *
     * Keyed on SKU where there is one and on the product name where there is
     * not, so an item the channel never gave a code to still consolidates
     * with itself rather than printing once per order.
     *
     * @param  Collection<int, Model>  $orders
     * @return array<int, array{sku: string, name: string, quantity: int, orders: array<int, string>}>
     */
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

        // Walking order: by SKU, because that is how a shelf is arranged and
        // the picker is holding the sheet while walking it.
        uasort($lines, fn (array $a, array $b) => [$a['sku'], $a['name'] ]<=> [$b['sku'], $b['name']]);

        return array_values($lines);
    }

    /**
     * @return array<int, array{sku: string, name: string, quantity: int}>
     */
    private static function itemsOf(Model $order): array
    {
        $products = $order->products ?? [];
        $items = [];

        foreach ($products as $p) {
            $name = trim((string) ($p->name ?? ''));

            // The variant is part of what gets picked off a shelf - "60cm"
            // against "25cm" is the whole difference - so it rides with the
            // name rather than being dropped. Venta spells the column
            // differently from the marketplaces.
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

        // Shopee and Lazada keep the buyer inside the stored payload, under
        // keys each has spelled more than one way over time.
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
