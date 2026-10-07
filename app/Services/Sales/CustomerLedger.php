<?php

namespace App\Services\Sales;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

final class CustomerLedger
{
    public const MAX_KEYS = 10;

    private const PHONE_DIGITS = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(o.telephone,''),' ',''),'-',''),'(',''),')',''),'+',''),'.','')";

    public function search(string $search, int $limit = 25): array
    {
        $search = trim($search);
        if ($search === '') {
            return [];
        }

        $pfx = (string) config('catalog.prefix');
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $search) . '%';
        $digits = preg_replace('/\D+/', '', $search);

        $orders = DB::table($pfx . 'order as o')
            ->where(function ($q) use ($like, $digits) {
                $q->where('o.firstname', 'like', $like)
                    ->orWhere('o.lastname', 'like', $like)
                    ->orWhereRaw("CONCAT(COALESCE(o.firstname,''), ' ', COALESCE(o.lastname,'')) LIKE ?", [$like])
                    ->orWhere('o.email', 'like', $like);
                if (strlen((string) $digits) >= 7) {
                    $q->orWhereRaw(self::PHONE_DIGITS . ' LIKE ?', ['%' . substr((string) $digits, -7) . '%']);
                }
            })
            ->orderByDesc('o.date_added')
            ->limit(2000)
            ->get(['o.order_id', 'o.firstname', 'o.lastname', 'o.email', 'o.telephone', 'o.customer_group',
                'o.marketplace_source', 'o.store_id', 'o.store_name', 'o.order_status_id', 'o.date_added']);

        return $this->summarise($orders)->take($limit)->values()->all();
    }

    public function history(array $keys, ?string $from = null, ?string $to = null): array
    {
        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');
        $keys = array_values(array_unique(array_filter(array_map('trim', $keys))));

        $query = DB::table($pfx . 'order as o')->where(function ($q) use ($keys) {
            foreach ($keys as $key) {
                [$kind, $value] = array_pad(explode(':', $key, 2), 2, '');
                match ($kind) {
                    'email' => $q->orWhereRaw('LOWER(TRIM(o.email)) = ?', [$value]),
                    'phone' => $q->orWhereRaw(self::PHONE_DIGITS . ' LIKE ?', ['%' . substr($value, -7)]),
                    'name' => $q->orWhereRaw("LOWER(TRIM(CONCAT(COALESCE(o.firstname,''), ' ', COALESCE(o.lastname,'')))) = ?", [$value])
                        ->orWhereRaw('LOWER(TRIM(o.firstname)) = ?', [$value]),
                    default => null,
                };
            }
        });
        if ($from) {
            $query->where('o.date_added', '>=', $from . ' 00:00:00');
        }
        if ($to) {
            $query->where('o.date_added', '<=', $to . ' 23:59:59');
        }

        $orders = $query->leftJoin($pfx . 'order_status as os', fn ($j) => $j->on('os.order_status_id', '=', 'o.order_status_id')->where('os.language_id', '=', $lang))
            ->orderByDesc('o.date_added')
            ->get(['o.order_id', 'o.firstname', 'o.lastname', 'o.email', 'o.telephone', 'o.customer_group', 'o.marketplace_source',
                'o.store_id', 'o.store_name', 'o.order_status_id', 'o.date_added', 'o.marketplace_order_id', 'os.name as status']);

        $orders = $orders->filter(fn ($o) => in_array($this->identity($o), $keys, true))->values();

        $counted = $this->countedStatuses();
        $ids = $orders->pluck('order_id')->all();
        $lines = $ids === [] ? collect() : DB::table($pfx . 'order_product as op')
            ->leftJoin(DB::raw("(SELECT order_product_id, MIN(product_option_value_id) AS pov, GROUP_CONCAT(value ORDER BY order_option_id SEPARATOR ' / ') AS variation
                FROM `{$pfx}order_option` GROUP BY order_product_id) as oo"), 'oo.order_product_id', '=', 'op.order_product_id')
            ->leftJoin($pfx . 'product as p', 'p.product_id', '=', 'op.product_id')
            ->leftJoin($pfx . 'product_option_value as pov', 'pov.product_option_value_id', '=', 'oo.pov')
            ->whereIn('op.order_id', $ids)
            ->get(['op.order_id', 'op.product_id', 'op.name', 'op.model', 'op.quantity', 'op.price', 'op.total', 'op.cost',
                'oo.variation', 'p.sku', 'p.price as price_now', 'pov.sku as variation_sku', 'pov.absolute_price as variation_price_now']);

        $orderById = $orders->keyBy('order_id');
        $countedLines = $lines->filter(fn ($l) => in_array((int) ($orderById[$l->order_id]->order_status_id ?? 0), $counted, true));

        $products = $countedLines->groupBy(fn ($l) => ($l->variation_sku ?: $l->sku ?: $l->model) . '|' . $l->product_id)
            ->map(function (Collection $group) use ($orderById) {
                $first = $group->first();
                $priceNow = (float) ($first->variation_price_now ?: $first->price_now);
                $units = (int) $group->sum('quantity');
                $revenue = (float) $group->sum('total');
                $cost = (float) $group->sum(fn ($l) => (float) $l->cost * (int) $l->quantity);

                return [
                    'product_id' => $first->product_id ? (int) $first->product_id : null,
                    'sku' => $first->variation_sku ?: ($first->sku ?: $first->model),
                    'name' => html_entity_decode((string) $first->name, ENT_QUOTES | ENT_HTML5, 'UTF-8'),
                    'variation' => $first->variation ?: null,
                    'price_now' => $priceNow > 0 ? round($priceNow, 2) : null,
                    'units' => $units,
                    'revenue' => round($revenue, 2),
                    'average_price' => $units > 0 ? round($revenue / $units, 2) : null,
                    'margin' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : null,
                    'purchases' => $group->sortByDesc(fn ($l) => $orderById[$l->order_id]->date_added)->map(function ($l) use ($orderById, $priceNow) {
                        $price = (float) $l->price;
                        $cost = (float) $l->cost;

                        return [
                            'date' => substr((string) $orderById[$l->order_id]->date_added, 0, 10),
                            'order_id' => (int) $l->order_id,
                            'quantity' => (int) $l->quantity,
                            'price' => round($price, 2),
                            'cost' => round($cost, 2),
                            'margin' => $price > 0 ? round(($price - $cost) / $price * 100, 1) : null,
                            'below_price_now' => $priceNow > 0 && $price > 0 ? round((1 - $price / $priceNow) * 100, 1) : null,
                        ];
                    })->values()->all(),
                ];
            })->sortByDesc('revenue')->values();

        $revenue = (float) $countedLines->sum('total');
        $cost = (float) $countedLines->sum(fn ($l) => (float) $l->cost * (int) $l->quantity);

        return [
            'customers' => $this->summarise($orders)->values()->all(),
            'summary' => [
                'orders' => $orders->count(),
                'counted_orders' => $orders->filter(fn ($o) => in_array((int) $o->order_status_id, $counted, true))->count(),
                'revenue' => round($revenue, 2),
                'cost' => round($cost, 2),
                'margin' => $revenue > 0 ? round(($revenue - $cost) / $revenue * 100, 1) : null,
            ],
            'products' => $products->all(),
            'orders' => $orders->map(fn ($o) => [
                'order_id' => (int) $o->order_id,
                'date' => substr((string) $o->date_added, 0, 10),
                'store' => $o->store_name ?: ($o->marketplace_source ?: 'Manual'),
                'store_ref' => $this->storeRef($o),
                'status' => $o->status,
                'counted' => in_array((int) $o->order_status_id, $counted, true),
                'marketplace_order_id' => $o->marketplace_order_id ?: null,
                'total' => round((float) $lines->where('order_id', $o->order_id)->sum('total'), 2),
            ])->values()->all(),
        ];
    }

    private function storeRef(object $order): array
    {
        $source = (string) ($order->marketplace_source ?? '');
        if ($source === '') {
            return ['key' => \App\Support\StoreKey::MANUAL, 'channel' => 'Manual', 'name' => ''];
        }

        $channel = \Illuminate\Support\Str::before($source, ':');
        $this->channelNames[$channel] ??= (string) (app(\App\Extensions\ExtensionManager::class)->getManifest($channel)['name'] ?? \Illuminate\Support\Str::headline($channel));

        return [
            'key' => \App\Support\StoreKey::of($source, (int) ($order->store_id ?? 0)),
            'channel' => $this->channelNames[$channel],
            'name' => (string) ($order->store_name ?? ''),
        ];
    }

    private array $channelNames = [];

    public function identity(object $order): ?string
    {
        $email = mb_strtolower(trim((string) ($order->email ?? '')));
        if ($email !== '' && ! str_contains($email, '*') && filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return 'email:' . $email;
        }

        $phone = (string) ($order->telephone ?? '');
        $digits = preg_replace('/\D+/', '', $phone);
        if (! str_contains($phone, '*') && strlen((string) $digits) >= 7) {
            return 'phone:' . substr((string) $digits, -10);
        }

        $name = mb_strtolower(trim(preg_replace('/\s+/', ' ', trim(($order->firstname ?? '') . ' ' . ($order->lastname ?? '')))));
        if ($name !== '' && ! str_contains($name, '*')) {
            return 'name:' . $name;
        }

        return null;
    }

    private function summarise(Collection $orders): Collection
    {
        $counted = $this->countedStatuses();
        $countedIds = $orders->filter(fn ($o) => in_array((int) $o->order_status_id, $counted, true))->pluck('order_id')->all();
        $spent = $countedIds === [] ? collect() : DB::table((string) config('catalog.prefix') . 'order_product')
            ->whereIn('order_id', $countedIds)->groupBy('order_id')
            ->selectRaw('order_id, SUM(total) as spent')->pluck('spent', 'order_id');

        return $orders->groupBy(fn ($o) => $this->identity($o) ?? '')
            ->reject(fn ($g, $key) => $key === '')
            ->map(function (Collection $g, string $key) use ($spent) {
                $latest = $g->sortByDesc('date_added')->first();

                return [
                    'key' => $key,
                    'name' => trim($latest->firstname . ' ' . $latest->lastname),
                    'email' => ($latest->email && ! str_contains($latest->email, '*')) ? $latest->email : null,
                    'phone' => ($latest->telephone && ! str_contains($latest->telephone, '*')) ? $latest->telephone : null,
                    'customer_types' => $g->pluck('customer_group')->filter()->unique()->values()->all(),
                    'stores' => $g->map(fn ($o) => $o->store_name ?: ($o->marketplace_source ?: 'Manual'))->unique()->values()->all(),
                    'store_refs' => $g->map(fn ($o) => $this->storeRef($o))->unique('key')->values()->all(),
                    'orders' => $g->count(),
                    'spent' => round((float) $g->sum(fn ($o) => (float) ($spent[$o->order_id] ?? 0)), 2),
                    'first_order' => substr((string) $g->min('date_added'), 0, 10),
                    'last_order' => substr((string) $g->max('date_added'), 0, 10),
                ];
            })
            ->sortByDesc('last_order');
    }

    private function countedStatuses(): array
    {
        return DB::table((string) config('catalog.prefix') . 'order_status')->where('add_revenue', 1)
            ->distinct()->pluck('order_status_id')->map(fn ($id) => (int) $id)->all();
    }
}
