<?php

namespace App\Support\Fulfilment;

use App\Models\Setting;
use App\Models\User;
use App\Support\CatalogImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

/**
 * The packing check: before an order is booked, the packer types how many
 * of each item is in their hands, and the booking goes ahead only when
 * every count matches the order. The packer is never shown the ordered
 * quantity.
 *
 * A correct count leaves a pass for that person and order, which the
 * booking routes require while the check is switched on, so a booking
 * cannot skip the count.
 */
final class PackingCheck
{
    /** The channels whose orders are booked from VentaSync. */
    public const CHANNELS = ['shopee', 'lazada', 'tiktok', 'venta'];

    public const WRONG = 'That count is wrong. Count again.';

    public const MISSING = 'Count the items before booking this order.';

    /** How long a correct count lets the person book that order. */
    private const PASS_MINUTES = 30;

    public static function enabled(): bool
    {
        try {
            return (bool) Setting::query()->value('packing_check');
        } catch (\Throwable $e) {
            return false;
        }
    }

    public static function supports(string $channel): bool
    {
        return in_array($channel, self::CHANNELS, true) && OrderPrintLists::supports($channel);
    }

    /**
     * The order's lines, one per product and variation, with the quantity
     * ordered. Null when there is no such order.
     *
     * @return array{reference: string, lines: list<array{key: string, name: string, variation: string, sku: string, image: ?string, quantity: int}>}|null
     */
    public static function order(string $channel, int $id): ?array
    {
        if (! self::supports($channel)) {
            return null;
        }

        $found = OrderPrintLists::orders($channel, [$id]);
        /** @var Model|null $order */
        $order = $found->first();
        if ($order === null) {
            return null;
        }

        $grouped = [];
        foreach ($order->products ?? [] as $p) {
            $name = trim((string) ($p->name ?? ''));
            $variation = trim((string) ($p->variation ?? $p->variant_label ?? ''));
            $variation = $variation === '-' ? '' : $variation;
            $sku = trim((string) ($p->sku ?? ''));
            $group = mb_strtolower($sku . '|' . $name . '|' . $variation);

            $grouped[$group] ??= [
                'name' => $name,
                'variation' => $variation,
                'sku' => $sku,
                'image' => trim((string) ($p->image ?? '')) ?: null,
                'quantity' => 0,
            ];
            $grouped[$group]['quantity'] += (int) ($p->quantity ?? 0);
        }

        $lines = [];
        foreach (array_values($grouped) as $i => $line) {
            $line['image'] ??= CatalogImages::urlFor($line['sku']);
            $lines[] = ['key' => 'l' . $i] + $line;
        }

        $reference = OrderPrintLists::packing($channel, $found)[0]['reference'] ?? (string) $id;

        return ['reference' => $reference, 'lines' => $lines];
    }

    /**
     * Whether the counts match every line. A match leaves the pass.
     *
     * @param  array<string, mixed>  $counts  line key => what the packer typed
     */
    public static function verify(User $user, string $channel, int $id, array $counts): bool
    {
        $order = self::order($channel, $id);
        if ($order === null || $order['lines'] === []) {
            return false;
        }

        foreach ($order['lines'] as $line) {
            $typed = trim((string) ($counts[$line['key']] ?? ''));
            if ($typed === '' || ! ctype_digit($typed) || (int) $typed !== $line['quantity']) {
                return false;
            }
        }

        Cache::put(self::passKey($user, $channel, $id), true, now()->addMinutes(self::PASS_MINUTES));

        return true;
    }

    public static function passed(User $user, string $channel, int $id): bool
    {
        return Cache::has(self::passKey($user, $channel, $id));
    }

    /**
     * The orders a booking request is about, by their row id, read from the
     * route or the bulk selection.
     *
     * @return list<int>
     */
    public static function ordersOf(string $channel, Request $request): array
    {
        // The channel's store middleware binds the store and drops the
        // route parameter; read whichever is there.
        $store = app()->bound($channel . '.route-store') ? app($channel . '.route-store') : $request->route('store');
        $storeId = is_object($store) ? (int) $store->getKey() : (int) $store;

        // The app's routes name every order by its own row id.
        if ($request->is('api/*') && $request->route('id') !== null) {
            return [(int) $request->route('id')];
        }

        $ids = match ($channel) {
            'venta' => [(int) $request->route('order')],
            'tiktok' => [(int) $request->route('id')],
            'shopee' => \Extensions\shopee\Models\ShopeeOrder::query()
                ->where('shopee_setting_id', $storeId)
                ->where('order_sn', (string) $request->route('orderSn'))
                ->pluck('id')->all(),
            'lazada' => $request->route('orderId') !== null
                ? \Extensions\lazada\Models\LazadaOrder::query()
                    ->where('lazada_setting_id', $storeId)
                    ->where('order_id', (string) $request->route('orderId'))
                    ->pluck('id')->all()
                : array_map('intval', (array) $request->input('ids', [])),
            default => [],
        };

        return array_values(array_unique(array_filter(array_map('intval', $ids))));
    }

    private static function passKey(User $user, string $channel, int $id): string
    {
        return 'packing-check:' . $user->getKey() . ':' . $channel . ':' . $id;
    }
}
