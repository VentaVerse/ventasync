<?php

namespace App\Integrations\Listings;

use App\Integrations\Push\PushLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

final class ListingVariations
{
    public const TABLE = 'listing_variation_offs';

    public const STORE_SKUS = 'listing_store_skus';

    public static function rules(): array
    {
        return [
            'variations_listed' => 'nullable|boolean',
            'variations_on' => 'nullable|array',
            'variations_on.*' => 'string|max:100',
        ];
    }

    public static function offInCatalog(array $productIds): array
    {
        $out = [];
        foreach (DB::table('product_option_combinations')
            ->whereIn('product_id', $productIds ?: [0])
            ->where('status', 0)
            ->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku']) as $r) {
            $out[(int) $r->product_id][self::key($r->sku)] = true;
        }

        return $out;
    }

    public static function offOnStore(string $channel, int $storeId, array $productIds): array
    {
        $out = [];
        foreach (DB::table(self::TABLE)
            ->where('channel', $channel)->where('store_id', $storeId)
            ->whereIn('product_id', $productIds ?: [0])
            ->get(['product_id', 'sku']) as $r) {
            $out[(int) $r->product_id][self::key($r->sku)] = true;
        }

        return $out;
    }

    public static function hidden(string $channel, int $storeId, array $productIds): array
    {
        $out = self::offInCatalog($productIds);
        foreach (self::offOnStore($channel, $storeId, $productIds) as $pid => $set) {
            $out[$pid] = ($out[$pid] ?? []) + $set;
        }

        return $out;
    }

    public static function allows(array $hidden, int $productId, ?string $sku): bool
    {
        $k = self::key($sku);

        return $k === '' || ! isset($hidden[$productId][$k]);
    }

    public static function sold(string $channel, int $storeId, array $productIds, ?string $pfx = null): array
    {
        $all = PushLedger::erpVariationSkus($productIds, $pfx ?? (string) config('catalog.prefix'));
        $hidden = self::hidden($channel, $storeId, $productIds);
        foreach ($all as $pid => $skus) {
            $all[$pid] = array_values(array_filter($skus, fn ($sku) => self::allows($hidden, (int) $pid, $sku)));
        }

        return $all;
    }

    public static function noneSold(string $channel, int $storeId, int $productId): bool
    {
        $all = PushLedger::erpVariationSkus([$productId], (string) config('catalog.prefix'))[$productId] ?? [];

        return $all !== [] && (self::sold($channel, $storeId, [$productId])[$productId] ?? []) === [];
    }

    public static function noneSoldMessage(string $storeName): string
    {
        return 'Every variation of this product is switched off for ' . $storeName . '. Switch at least one on, then push.';
    }

    public static function remember(string $channel, int $storeId, int $productId, iterable $skus): void
    {
        $clean = [];
        foreach ($skus as $sku) {
            $sku = trim((string) $sku);
            if ($sku !== '') {
                $clean[self::key($sku)] = $sku;
            }
        }

        DB::table(self::STORE_SKUS)->updateOrInsert(
            ['channel' => $channel, 'store_id' => $storeId, 'product_id' => $productId],
            ['skus' => json_encode(array_values($clean)), 'checked_at' => now()]
        );
    }

    public static function missing(string $channel, int $storeId, array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $held = DB::table(self::STORE_SKUS)
            ->where('channel', $channel)->where('store_id', $storeId)
            ->whereIn('product_id', $productIds)
            ->pluck('skus', 'product_id');
        if ($held->isEmpty()) {
            return [];
        }

        $read = $held->keys()->map(fn ($v) => (int) $v)->all();
        $names = \App\Support\VariationRows::forProducts($read);

        $out = [];
        foreach (self::sold($channel, $storeId, $read) as $pid => $skus) {
            $have = [];
            foreach ((array) json_decode((string) $held->get($pid), true) as $sku) {
                $have[self::key($sku)] = true;
            }
            $named = [];
            foreach ($names->get((int) $pid) ?? [] as $row) {
                $named[self::key($row->sku ?? '')] = trim((string) ($row->option_value_name ?? ''));
            }

            $gap = [];
            foreach ($skus as $sku) {
                $k = self::key($sku);
                if ($k !== '' && ! isset($have[$k])) {
                    $gap[] = ['sku' => trim((string) $sku), 'name' => $named[$k] ?? ''];
                }
            }
            if ($gap !== []) {
                $out[(int) $pid] = $gap;
            }
        }

        return $out;
    }

    public static function readProducts(string $channel, int $storeId): array
    {
        return DB::table(self::STORE_SKUS)->where('channel', $channel)->where('store_id', $storeId)
            ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    public static function missingMessage(string $storeName, array $missing): string
    {
        $parts = array_map(fn ($m) => $m['name'] !== '' ? $m['name'] . ' (' . $m['sku'] . ')' : $m['sku'], $missing);

        return 'Not on ' . $storeName . ': ' . implode(', ', $parts) . '.';
    }

    public static function withMissing(?string $error, ?string $missingLine): ?string
    {
        $parts = array_values(array_filter([trim((string) $error), trim((string) $missingLine)], fn ($p) => $p !== ''));

        return $parts === [] ? null : implode(' ', $parts);
    }

    public static function cardData(string $channel, int $storeId, int $productId, ?array $storeSkus): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $catalog = [];

        $combos = DB::table('product_option_combinations')
            ->where('product_id', $productId)
            ->orderBy('sort_order')->orderBy('id')
            ->get(['id', 'sku', 'status']);
        if ($combos->isNotEmpty()) {
            $names = DB::table('product_option_combination_values as cv')
                ->join($pfx . 'product_option_value as pov', 'cv.product_option_value_id', '=', 'pov.product_option_value_id')
                ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                })
                ->whereIn('cv.combination_id', $combos->pluck('id')->all())
                ->orderBy('pov.product_option_id')
                ->get(['cv.combination_id', 'ovd.name'])
                ->groupBy('combination_id');
            foreach ($combos as $c) {
                $catalog[] = [
                    'sku' => trim((string) $c->sku),
                    'name' => ($names->get($c->id) ?? collect())->pluck('name')->map(fn ($n) => self::text($n))->implode(' / '),
                    'enabled' => (int) $c->status !== 0,
                ];
            }
        } else {
            foreach (DB::table($pfx . 'product_option_value as pov')
                ->join($pfx . 'option_value_description as ovd', function ($j) use ($langId) {
                    $j->on('pov.option_value_id', '=', 'ovd.option_value_id')->where('ovd.language_id', '=', $langId);
                })
                ->where('pov.product_id', $productId)
                ->orderBy('pov.product_option_value_id')
                ->get(['pov.sku', 'ovd.name']) as $v) {
                $catalog[] = ['sku' => trim((string) $v->sku), 'name' => self::text($v->name), 'enabled' => true];
            }
        }

        $off = self::offOnStore($channel, $storeId, [$productId])[$productId] ?? [];
        $held = $storeSkus === null ? null : array_flip(array_filter(array_map(fn ($s) => self::key($s), $storeSkus)));

        $rows = [];
        $known = [];
        foreach ($catalog as $v) {
            $k = self::key($v['sku']);
            if ($k !== '') {
                $known[$k] = true;
            }
            if (! $v['enabled']) {
                continue;
            }
            $rows[] = [
                'sku' => $v['sku'],
                'name' => $v['name'] !== '' ? $v['name'] : $v['sku'],
                'on' => $k === '' || ! isset($off[$k]),
                'onStore' => $held === null || $k === '' ? null : isset($held[$k]),
            ];
        }

        $parent = [];
        $product = DB::table($pfx . 'product')->where('product_id', $productId)->first(['sku', 'model']);
        foreach ([$product->sku ?? '', $product->model ?? ''] as $own) {
            if (($k = self::key($own)) !== '') {
                $parent[$k] = true;
            }
        }

        $extras = [];
        foreach ($storeSkus ?? [] as $sku) {
            $k = self::key($sku);
            if ($k !== '' && ! isset($known[$k]) && ! isset($parent[$k])) {
                $extras[$k] = trim((string) $sku);
            }
        }

        return ['variationRows' => $rows, 'variationExtras' => array_values($extras)];
    }

    public static function submitted(Request $request): ?array
    {
        if (! $request->boolean('variations_listed')) {
            return null;
        }

        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) $request->input('variations_on', []))));
    }

    public static function save(string $channel, int $storeId, int $productId, array $onSkus): void
    {
        $on = array_flip(array_map(fn ($s) => self::key($s), $onSkus));
        $coreOff = self::offInCatalog([$productId])[$productId] ?? [];
        $shown = [];
        foreach (PushLedger::erpVariationSkus([$productId], (string) config('catalog.prefix'))[$productId] ?? [] as $sku) {
            $k = self::key($sku);
            if ($k !== '' && ! isset($coreOff[$k])) {
                $shown[$k] = trim((string) $sku);
            }
        }
        if ($shown === []) {
            return;
        }

        DB::transaction(function () use ($channel, $storeId, $productId, $shown, $on) {
            DB::table(self::TABLE)
                ->where('channel', $channel)->where('store_id', $storeId)->where('product_id', $productId)
                ->whereIn('sku', array_keys($shown))
                ->delete();
            $now = now();
            $rows = [];
            foreach ($shown as $k => $sku) {
                if (! isset($on[$k])) {
                    $rows[] = ['channel' => $channel, 'store_id' => $storeId, 'product_id' => $productId, 'sku' => $k, 'created_at' => $now, 'updated_at' => $now];
                }
            }
            if ($rows !== []) {
                DB::table(self::TABLE)->insert($rows);
            }
        });
    }

    public static function forget(int $productId, array $skus): void
    {
        $keys = array_values(array_filter(array_map(fn ($s) => self::key($s), $skus)));
        if ($keys !== []) {
            DB::table(self::TABLE)->where('product_id', $productId)->whereIn('sku', $keys)->delete();
        }
    }

    public static function forgetProducts(array $productIds): void
    {
        DB::table(self::TABLE)->whereIn('product_id', $productIds ?: [0])->delete();
        DB::table(self::STORE_SKUS)->whereIn('product_id', $productIds ?: [0])->delete();
    }

    private static function key(mixed $sku): string
    {
        return strtolower(trim((string) $sku));
    }

    private static function text(mixed $s): string
    {
        return html_entity_decode((string) $s, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    }
}
