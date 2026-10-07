<?php

namespace Extensions\ventacart\Services\VentaCart;

use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Support\Facades\DB;

final class VentaCartStockPricePush
{
    public function __construct(private readonly VentaCartSetting $setting, private readonly VentaCartClient $client) {}

    public static function for(VentaCartSetting $setting): self
    {
        return new self($setting, new VentaCartClient($setting));
    }

    public function push(array $productIds, bool $stock, bool $price): array
    {
        $pfx = (string) config('catalog.prefix');
        $ids = array_values(array_unique(array_map('intval', $productIds)));
        $out = ['ok' => 0, 'failed' => 0, 'skipped' => 0, 'errors' => [], 'outcomes' => []];
        if ($ids === []) {
            return $out;
        }
        $links = VentaCartProductLink::query()->where('ventacart_setting_id', $this->setting->id)->whereIn('product_id', $ids)->get()->keyBy('product_id');
        $products = DB::table($pfx . 'product')->whereIn('product_id', $ids)->get(['product_id', 'sku', 'quantity', 'price', 'status'])->keyBy('product_id');
        $combos = DB::table('product_option_combinations')->whereIn('product_id', $ids)->get(['product_id', 'sku', 'quantity', 'absolute_price'])->groupBy(fn ($r) => (int) $r->product_id);
        $options = DB::table($pfx . 'product_option_value')->whereIn('product_id', $ids)->whereNotNull('sku')->where('sku', '!=', '')
            ->get(['product_id', 'sku', 'quantity', 'price', 'price_prefix', 'absolute_price'])->groupBy(fn ($r) => (int) $r->product_id);
        $hidden = \App\Integrations\Listings\ListingVariations::hidden('ventacart', (int) $this->setting->id, $ids);
        $listings = \Extensions\ventacart\Models\VentaCartListing::query()->where('ventacart_setting_id', $this->setting->id)->whereIn('product_id', $ids)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $groupsByProduct = DB::table('ventacart_product_group_products as gp')
            ->join('ventacart_product_groups as g', 'g.id', '=', 'gp.ventacart_product_group_id')
            ->where('g.ventacart_setting_id', $this->setting->id)->whereIn('gp.product_id', $ids)
            ->orderBy('gp.id')->get(['gp.product_id', 'g.markup_percent', 'g.markup_fixed'])
            ->unique('product_id')->keyBy(fn ($r) => (int) $r->product_id);
        $withVariations = array_flip(\Extensions\ventacart\Models\VentaCartListing::withVariations($ids));

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
            $allVariants = ($combos->get($pid) ?? collect())->isNotEmpty() ? $combos->get($pid) : ($options->get($pid) ?? collect());
            $variants = $allVariants->filter(fn ($v) => \App\Integrations\Listings\ListingVariations::allows($hidden, $pid, (string) ($v->sku ?? '')));
            if ($allVariants->isNotEmpty() && $variants->isEmpty()) {
                $out['skipped']++;
                continue;
            }
            $basePrice = (float) $product->price;
            $rule = \Extensions\ventacart\Models\VentaCartListing::ruleFor($listings->get($pid), $groupsByProduct->get($pid));

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
                        $variantCall($this->client->updateVariant($vsku, ['price' => $rule(self::variantPrice($v, $basePrice))]), $vsku);
                    }
                } else {
                    $start = \Extensions\ventacart\Models\VentaCartListing::startFor($listings->get($pid), $basePrice, isset($withVariations[$pid]));
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
        $before = DB::table(\App\Integrations\Listings\ListingVariations::STORE_SKUS)
            ->where('channel', 'ventacart')->where('store_id', $this->setting->id)->where('product_id', $productId)
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
        \App\Integrations\Listings\ListingVariations::remember('ventacart', (int) $this->setting->id, $productId, $held);
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

    public static function recordOutcomes(int $settingId, array $outcomes): void
    {
        if ($outcomes === []) {
            return;
        }
        $groupIds = DB::table('ventacart_product_groups')->where('ventacart_setting_id', $settingId)->pluck('id');
        $states = VentaCartListingStates::for($settingId);
        foreach ($outcomes as $productId => $failure) {
            if ($groupIds->isNotEmpty()) {
                DB::table('ventacart_product_group_products')->whereIn('ventacart_product_group_id', $groupIds)->where('product_id', (int) $productId)
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
