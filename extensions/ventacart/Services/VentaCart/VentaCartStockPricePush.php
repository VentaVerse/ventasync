<?php

namespace Extensions\VentaCart\Services\VentaCart;

use Extensions\VentaCart\Models\VentaCartListingLink;
use Extensions\VentaCart\Models\VentaCartStore;
use Illuminate\Support\Facades\DB;

final class VentaCartStockPricePush
{
    public function __construct(private readonly VentaCartStore $store, private readonly VentaCartClient $client) {}

    public static function for(VentaCartStore $store): self
    {
        return new self($store, new VentaCartClient($store));
    }

    public function push(array $productIds, bool $stock, bool $price): array
    {
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $out = ['ok' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => [], 'outcomes' => []];
        if ($ids === []) {
            return $out;
        }
        $links = VentaCartListingLink::query()->where('store_id', $this->store->id)->whereIn('product_id', $ids)->get()->keyBy('product_id');
        $products = DB::table('products')->whereIn('product_id', $ids)->get(['product_id', 'sku', 'quantity', 'price', 'status'])->keyBy('product_id');
        $combos = DB::table('product_variations')->whereIn('product_id', $ids)->get(['product_id', 'sku', 'quantity', 'absolute_price'])->groupBy(fn ($r) => (int) $r->product_id);
        $variationValues = DB::table('product_variation_values')->whereIn('product_id', $ids)->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'quantity', 'price', 'price_prefix', 'absolute_price'])->groupBy(fn ($r) => (int) $r->product_id);
        $hidden = \App\Channels\Listings\ListingVariations::hidden('ventacart', (int) $this->store->id, $ids);
        $own = \App\Channels\Listings\VariationPrices::forProducts('ventacart', (int) $this->store->id, $ids);
        $listings = \Extensions\VentaCart\Models\VentaCartListing::query()->where('store_id', $this->store->id)->whereIn('product_id', $ids)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $withVariations = array_flip(\Extensions\VentaCart\Models\VentaCartListing::withVariations($ids));

        foreach ($ids as $pid) {
            $link = $links->get($pid);
            $product = $products->get($pid);
            if (! $link || ! $product || (int) $product->status === 0 || ! $link->ventacart_product_id) {
                $out['skipped']++;
                continue;
            }
            $sku = $link->sku ?: $product->sku;
            $fine = true;
            $why = '';
            $allVariants = ($combos->get($pid) ?? collect())->isNotEmpty() ? $combos->get($pid) : ($variationValues->get($pid) ?? collect());
            $variants = $allVariants->filter(fn ($v) => \App\Channels\Listings\ListingVariations::allows($hidden, $pid, (string) ($v->sku ?? '')));
            if ($allVariants->isNotEmpty() && $variants->isEmpty()) {
                $out['skipped']++;
                continue;
            }
            $basePrice = (float) $product->price;
            $rule = \Extensions\VentaCart\Models\VentaCartListing::ruleFor($listings->get($pid));

            $variantSkus = $variants->filter(fn ($v) => trim((string) ($v->sku ?? '')) !== '');
            $hasVariants = $variantSkus->isNotEmpty();

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
                        $variantCall($this->client->updateVariant($vsku, ['price' => $rule(\App\Channels\Listings\VariationPrices::base($own, $pid, $vsku, self::variantPrice($v, $basePrice)))]), $vsku);
                    }
                } else {
                    $start = \Extensions\VentaCart\Models\VentaCartListing::startFor($listings->get($pid), $basePrice, isset($withVariations[$pid]));
                    $r = $this->client->updateProduct($sku, ['price' => $rule($start)]);
                    if (! ($r['ok'] ?? false)) {
                        $fine = false;
                        $why = (string) ($r['body']['error'] ?? $r['body']['message'] ?? 'no answer');
                    }
                }
            }
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

    private static function holds(array $r): ?bool
    {
        if ($r['ok'] ?? false) {
            return true;
        }
        $error = is_array($r['body'] ?? null) ? (string) ($r['body']['error'] ?? '') : '';

        return (int) ($r['status'] ?? 0) === 404 && stripos($error, 'variant not found') !== false ? false : null;
    }

    private function rememberHeld(int $productId, array $answers): void
    {
        $held = [];
        $before = DB::table(\App\Channels\Listings\ListingVariations::STORE_SKUS)
            ->where('channel', 'ventacart')->where('store_id', $this->store->id)->where('product_id', $productId)
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
        \App\Channels\Listings\ListingVariations::remember('ventacart', (int) $this->store->id, $productId, $held);
    }

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

    public static function recordOutcomes(int $storeId, array $outcomes): void
    {
        if ($outcomes === []) {
            return;
        }
        $groupIds = DB::table('ch_ventacart_product_groups')->where('store_id', $storeId)->pluck('id');
        $states = VentaCartListingStates::for($storeId);
        foreach ($outcomes as $productId => $failure) {
            if ($groupIds->isNotEmpty()) {
                DB::table('ch_ventacart_product_group_products')->whereIn('ventacart_product_group_id', $groupIds)->where('product_id', (int) $productId)
                    ->update([
                        'sync_status' => $failure === null ? 'pushed' : 'error',
                        'push_error' => $failure === null ? null : \Illuminate\Support\Str::limit($failure, 480),
                        'last_pushed_at' => now(),
                    ]);
            }
            $states->recordOutcome((int) $productId, $failure);
        }
    }

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
