<?php

namespace App\Support\Fulfilment;

use App\Models\Setting;
use App\Models\User;
use App\Support\CatalogImages;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

final class PackingCheck
{
    public const CHANNELS = ['shopee', 'lazada', 'tiktok', 'ventacart'];

    public const WRONG = 'That count is wrong. Count again.';

    public const MISSING = 'Count the items before booking this order.';

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

    public static function order(string $channel, int $id): ?array
    {
        if (! self::supports($channel)) {
            return null;
        }

        $found = OrderPrintLists::orders($channel, [$id]);
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

    public static function ordersOf(string $channel, Request $request): array
    {
        $store = app()->bound($channel . '.route-store') ? app($channel . '.route-store') : $request->route('store');
        $storeId = is_object($store) ? (int) $store->getKey() : (int) $store;

        if ($request->is('api/*') && $request->route('id') !== null) {
            return [(int) $request->route('id')];
        }

        $ids = match ($channel) {
            'ventacart' => [(int) $request->route('order')],
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

    public static function spend(User $user, string $channel, array $ids): void
    {
        foreach ($ids as $id) {
            Cache::forget(self::passKey($user, $channel, $id));
        }
    }

    public static function booked(string $channel, array $ids, Request $request, Response $response): array
    {
        if ($response instanceof JsonResponse) {
            $data = (array) $response->getData(true);

            return self::succeeded((bool) ($data['ok'] ?? $response->isSuccessful()), $data['error'] ?? null) ? $ids : [];
        }

        if (! $response->isRedirection()) {
            return $response->isSuccessful() ? $ids : [];
        }

        $session = $request->hasSession() ? $request->session() : null;
        $fresh = (array) ($session?->get('_flash.new') ?? []);

        if (in_array($channel . '_bulk_pack', $fresh, true)) {
            $packed = array_map('intval', (array) ($session->get($channel . '_bulk_pack')['packed_ids'] ?? []));

            return array_values(array_intersect($ids, $packed));
        }

        if (in_array($channel . '_orders_last_result', $fresh, true)) {
            $result = (array) $session->get($channel . '_orders_last_result');

            return self::succeeded((bool) ($result['ok'] ?? false), $result['error'] ?? null) ? $ids : [];
        }

        return [];
    }

    private static function succeeded(bool $ok, mixed $error): bool
    {
        return $ok || $error === 'already_done';
    }

    private static function passKey(User $user, string $channel, int $id): string
    {
        return 'packing-check:' . $user->getKey() . ':' . $channel . ':' . $id;
    }
}
