<?php

namespace Extensions\venta\Services\Venta;

use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Facades\DB;

/**
 * Stock and price onto the store for linked products, one sender for the
 * Listings page's row and bulk verbs and the group page's (parity,
 * 2026-09-07: Venta's Listings page had neither Push stock nor Push
 * price). Combinations first, then single-axis values, then the parent,
 * the way the group page has always sent them.
 */
final class VentaStockPricePush
{
    public function __construct(private readonly VentaSetting $setting, private readonly VentaClient $client) {}

    public static function for(VentaSetting $setting): self
    {
        return new self($setting, new VentaClient($setting));
    }

    /**
     * @param list<int> $productIds
     * @return array{ok:int, failed:int, skipped:int, errors:array<int,string>, outcomes:array<int,?string>}
     *         outcomes: product id => null when it worked, the failure text when it did not; skipped products are absent
     */
    public function push(array $productIds, bool $stock, bool $price): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $out = ['ok' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => [], 'outcomes' => []];
        if ($ids === []) {
            return $out;
        }
        $links = VentaProductLink::query()->where('venta_setting_id', $this->setting->id)->whereIn('product_id', $ids)->get()->keyBy('product_id');
        $products = DB::table($pfx . 'product')->whereIn('product_id', $ids)->get(['product_id', 'sku', 'quantity', 'price', 'status'])->keyBy('product_id');
        $combos = DB::table('product_option_combinations')->whereIn('product_id', $ids)->get(['product_id', 'sku', 'quantity', 'absolute_price'])->groupBy(fn ($r) => (int) $r->product_id);
        $options = DB::table($pfx . 'product_option_value')->whereIn('product_id', $ids)->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'quantity', 'price', 'price_prefix', 'absolute_price'])->groupBy(fn ($r) => (int) $r->product_id);
        $hidden = \App\Integrations\Listings\ListingVariations::hidden('venta', (int) $this->setting->id, $ids);
        $listings = \Extensions\venta\Models\VentaListing::query()->where('venta_setting_id', $this->setting->id)->whereIn('product_id', $ids)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $groupsByProduct = DB::table('venta_product_group_products as gp')
            ->join('venta_product_groups as g', 'g.id', '=', 'gp.venta_product_group_id')
            ->where('g.venta_setting_id', $this->setting->id)->whereIn('gp.product_id', $ids)
            ->orderBy('gp.id')->get(['gp.product_id', 'g.markup_percent', 'g.markup_fixed'])
            ->unique('product_id')->keyBy(fn ($r) => (int) $r->product_id);

        foreach ($ids as $pid) {
            $link = $links->get($pid);
            $product = $products->get($pid);
            if (! $link || ! $product || (int) $product->status === 0 || ! $link->venta_product_id) {
                $out['skipped']++;
                continue;
            }
            $sku = $link->sku ?: $product->sku;
            $fine = true;
            $why = '';
            $allVariants = ($combos->get($pid) ?? collect())->isNotEmpty() ? $combos->get($pid) : ($options->get($pid) ?? collect());
            // Only the variations this store sells are synced.
            $variants = $allVariants->filter(fn ($v) => \App\Integrations\Listings\ListingVariations::allows($hidden, $pid, (string) ($v->sku ?? '')));
            if ($allVariants->isNotEmpty() && $variants->isEmpty()) {
                $out['skipped']++;
                continue;
            }
            $basePrice = (float) $product->price;
            // The listing's own price rule, else its group's, else the catalog price.
            $rule = \Extensions\venta\Models\VentaListing::ruleFor($listings->get($pid), $groupsByProduct->get($pid));

            // A variation product exists on the store only as its variants
            // (variants/{sku}); there is no simple products/{sku} for it, so a
            // product-level push (pushStock/updateProduct) 404s "Product not
            // found" and flips an otherwise-successful push to failed. Push the
            // product level ONLY for simple products; for variation products
            // the per-variant calls are the whole operation.
            $variantSkus = $variants->filter(fn ($v) => trim((string) ($v->sku ?? '')) !== '');
            $hasVariants = $variantSkus->isNotEmpty();

            // Each variant's answer by lowercase SKU: whether the store's item
            // holds it. One the item lacks is named on the listing, not failed
            // here, and never stops the others.
            $answers = [];
            $sure = true;
            $variantCall = function (array $r, string $vsku) use (&$answers, &$sure, &$fine, &$why): void {
                $holds = self::holds($r);
                if ($holds === null) {
                    $fine = false;
                    $sure = false;
                    $why = (string) ($r['body']['error'] ?? $r['body']['message'] ?? 'no answer');

                    return;
                }
                $answers[strtolower($vsku)] = [$vsku, $holds];
            };

            if ($stock) {
                if ($hasVariants) {
                    foreach ($variantSkus as $v) {
                        $vsku = trim((string) $v->sku);
                        $variantCall($this->client->pushVariantStock($vsku, max(0, (int) $v->quantity)), $vsku);
                    }
                } else {
                    $r = $this->client->pushStock($sku, max(0, (int) $product->quantity));
                    if (! ($r['ok'] ?? false)) {
                        $fine = false;
                        $why = (string) ($r['body']['error'] ?? $r['body']['message'] ?? 'no answer');
                    }
                }
            }
            if ($price) {
                if ($hasVariants) {
                    foreach ($variantSkus as $v) {
                        $vsku = trim((string) $v->sku);
                        $variantCall($this->client->updateVariant($vsku, ['price' => $rule(self::variantPrice($v, $basePrice))]), $vsku);
                    }
                } else {
                    $r = $this->client->updateProduct($sku, ['price' => $rule($basePrice)]);
                    if (! ($r['ok'] ?? false)) {
                        $fine = false;
                        $why = (string) ($r['body']['error'] ?? $r['body']['message'] ?? 'no answer');
                    }
                }
            }
            // Only a run where every variant call was answered says what the item holds.
            if ($hasVariants && $sure && $answers !== []) {
                $this->rememberHeld($pid, $answers);
            }
            if ($fine) {
                $out['ok']++;
                $out['outcomes'][$pid] = null;
            } else {
                $out['failed']++;
                $out['errors'][$pid] = ($stock && ! $price ? 'Stock push failed: ' : ($price && ! $stock ? 'Price push failed: ' : 'Push failed: ')) . $why;
                $out['outcomes'][$pid] = $out['errors'][$pid];
            }
        }

        return $out;
    }

    /**
     * Whether a variant call says the store's item holds the variant: true
     * when it took the change, false when the store answers "Variant not
     * found", null for any other answer (a refusal, or no answer at all).
     */
    private static function holds(array $r): ?bool
    {
        if ($r['ok'] ?? false) {
            return true;
        }
        $error = is_array($r['body'] ?? null) ? (string) ($r['body']['error'] ?? '') : '';

        return (int) ($r['status'] ?? 0) === 404 && stripos($error, 'variant not found') !== false ? false : null;
    }

    /**
     * The variants the store answered for, kept as the SKUs its item holds.
     * A variation this run did not ask about keeps what was last read of it.
     *
     * @param array<string, array{0: string, 1: bool}> $answers lowercase SKU => [SKU, held]
     */
    private function rememberHeld(int $productId, array $answers): void
    {
        $held = [];
        $before = DB::table(\App\Integrations\Listings\ListingVariations::STORE_SKUS)
            ->where('channel', 'venta')->where('store_id', $this->setting->id)->where('product_id', $productId)
            ->value('skus');
        foreach ((array) json_decode((string) $before, true) as $sku) {
            $k = strtolower(trim((string) $sku));
            if ($k !== '' && ! isset($answers[$k])) {
                $held[] = trim((string) $sku);
            }
        }
        foreach ($answers as [$sku, $isHeld]) {
            if ($isHeld) {
                $held[] = $sku;
            }
        }
        \App\Integrations\Listings\ListingVariations::remember('venta', (int) $this->setting->id, $productId, $held);
    }

    /** A combination carries its own price; a single-axis value its own or an adjustment on the base. */
    public static function variantPrice(object $v, float $basePrice): float
    {
        if ((float) ($v->absolute_price ?? 0) > 0) {
            return (float) $v->absolute_price;
        }
        if (isset($v->price) && (float) $v->price != 0.0) {
            return ($v->price_prefix ?? '+') === '-' ? $basePrice - (float) $v->price : $basePrice + (float) $v->price;
        }

        return $basePrice;
    }

    /**
     * Each product's verdict onto its group rows for THIS store, where the
     * group page reads sync_status, and onto the listing's error line, so
     * the two always agree.
     *
     * @param array<int, ?string> $outcomes product id => null when it worked, else the failure text
     */
    public static function recordOutcomes(int $settingId, array $outcomes): void
    {
        if ($outcomes === []) {
            return;
        }
        $groupIds = DB::table('venta_product_groups')->where('venta_setting_id', $settingId)->pluck('id');
        $states = VentaListingStates::for($settingId);
        foreach ($outcomes as $productId => $failure) {
            if ($groupIds->isNotEmpty()) {
                DB::table('venta_product_group_products')->whereIn('venta_product_group_id', $groupIds)->where('product_id', (int) $productId)
                    ->update([
                        'sync_status' => $failure === null ? 'pushed' : 'error',
                        'push_error' => $failure === null ? null : \Illuminate\Support\Str::limit($failure, 480),
                        'last_pushed_at' => now(),
                    ]);
            }
            $states->recordOutcome((int) $productId, $failure);
        }
    }

    /** The one sentence a stock or price push leaves behind. */
    public static function sentence(array $r, string $what, string $storeName): string
    {
        $msg = "{$what} pushed to {$storeName}: {$r['ok']} updated";
        if ($r['failed'] > 0) {
            $msg .= ", {$r['failed']} failed";
        }
        if ($r['skipped'] > 0) {
            $msg .= ", {$r['skipped']} skipped (not linked or disabled)";
        }
        if ($r['errors']) {
            $msg .= '. ' . implode('; ', array_slice(array_map(fn ($pid, $t) => "#{$pid}: {$t}", array_keys($r['errors']), $r['errors']), 0, 3));
        }

        return $msg . '.';
    }
}
