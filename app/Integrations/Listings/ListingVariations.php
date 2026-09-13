<?php

namespace App\Integrations\Listings;

use App\Integrations\Push\PushLedger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Which of a product's variations a store sells.
 *
 * Two switches decide it. The catalog's own switch on a variation turns it
 * off everywhere: no listing shows it and no store is sent or synced it. A
 * listing's switch turns an enabled variation off on that one store. Every
 * push, every stock and price sync and every listing page asks here, so a
 * variation that is off is never sent, and one a store does not carry is
 * never an error.
 *
 * Variations are known by SKU, as they are on every store.
 */
final class ListingVariations
{
    public const TABLE = 'listing_variation_offs';

    /** Validation rules for the card's fields, for a listing save to merge. */
    public static function rules(): array
    {
        return [
            'variations_listed' => 'nullable|boolean',
            'variations_on' => 'nullable|array',
            'variations_on.*' => 'string|max:100',
        ];
    }

    /** @return array<int, array<string, true>> lowercase SKUs the catalog switched off, per product */
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

    /** @return array<int, array<string, true>> lowercase SKUs switched off on this store, per product */
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

    /** @return array<int, array<string, true>> every SKU this store must not be sent or synced, per product */
    public static function hidden(string $channel, int $storeId, array $productIds): array
    {
        $out = self::offInCatalog($productIds);
        foreach (self::offOnStore($channel, $storeId, $productIds) as $pid => $set) {
            $out[$pid] = ($out[$pid] ?? []) + $set;
        }

        return $out;
    }

    /** Whether a variation is sold on the store the $hidden map was read for. A blank SKU is left to the push's own SKU rule. */
    public static function allows(array $hidden, int $productId, ?string $sku): bool
    {
        $k = self::key($sku);

        return $k === '' || ! isset($hidden[$productId][$k]);
    }

    /** @return array<int, list<string>> the catalog's variation SKUs this store sells, per product */
    public static function sold(string $channel, int $storeId, array $productIds, ?string $pfx = null): array
    {
        $all = PushLedger::erpVariationSkus($productIds, $pfx ?? (string) config('catalog.prefix'));
        $hidden = self::hidden($channel, $storeId, $productIds);
        foreach ($all as $pid => $skus) {
            $all[$pid] = array_values(array_filter($skus, fn ($sku) => self::allows($hidden, (int) $pid, $sku)));
        }

        return $all;
    }

    /**
     * Whether every variation of a product with variations is off on this
     * store. A push stops there rather than send the product without them.
     */
    public static function noneSold(string $channel, int $storeId, int $productId): bool
    {
        $all = PushLedger::erpVariationSkus([$productId], (string) config('catalog.prefix'))[$productId] ?? [];

        return $all !== [] && (self::sold($channel, $storeId, [$productId])[$productId] ?? []) === [];
    }

    /** The refusal a push gives when noneSold() holds. */
    public static function noneSoldMessage(string $storeName): string
    {
        return 'Every variation of this product is switched off for ' . $storeName . '. Switch at least one on, then push.';
    }

    /**
     * What the listing page's Variations card draws.
     *
     * @param  list<string>|null  $storeSkus  the SKUs the store holds for this product, or null when it was not asked
     * @return array{variationRows: list<array{sku: string, name: string, on: bool, onStore: ?bool}>, variationExtras: list<string>}
     */
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

        // The product's own SKU and model are the parent, never a variation,
        // however a store's rows happen to carry them.
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

    /** @return list<string>|null the SKUs ticked on, or null when the form did not carry the card */
    public static function submitted(Request $request): ?array
    {
        if (! $request->boolean('variations_listed')) {
            return null;
        }

        return array_values(array_filter(array_map(fn ($s) => trim((string) $s), (array) $request->input('variations_on', []))));
    }

    /**
     * Keep the store's switches for the variations the card showed: the
     * catalog's enabled ones. A variation the catalog has switched off was
     * not on the card, so its store switch is left as it was.
     *
     * @param  list<string>  $onSkus
     */
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

    /** A variation the catalog deleted leaves no switch behind on any store. */
    public static function forget(int $productId, array $skus): void
    {
        $keys = array_values(array_filter(array_map(fn ($s) => self::key($s), $skus)));
        if ($keys !== []) {
            DB::table(self::TABLE)->where('product_id', $productId)->whereIn('sku', $keys)->delete();
        }
    }

    /** A product the catalog deleted leaves no switch behind. */
    public static function forgetProducts(array $productIds): void
    {
        DB::table(self::TABLE)->whereIn('product_id', $productIds ?: [0])->delete();
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
