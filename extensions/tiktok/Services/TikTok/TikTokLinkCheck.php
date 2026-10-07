<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Illuminate\Support\Facades\DB;

class TikTokLinkCheck
{
    public function __construct(private readonly TikTokLiveListing $live)
    {
    }

    public function runNext(array $c, int $storeId): ?array
    {
        $linked = \Extensions\tiktok\Models\TikTokListing::query()->whereNotNull('tiktok_product_id')->pluck('product_id')
            ->merge(\Extensions\tiktok\Models\TikTokProductGroupProduct::query()->onStore($storeId)->whereNotNull('tiktok_product_id')->pluck('product_id'))
            ->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
        $linked = \App\Integrations\Support\LinkCheckCursor::rotate('tiktok:' . $storeId, $linked);

        return $linked !== [] ? $this->run($c, $linked, $storeId) : null;
    }

    public function run(array $c, array $productIds, ?int $storeId = null): array
    {
        $storeId ??= isset($c['setting']) ? (int) $c['setting']->id : (int) (\Extensions\tiktok\Models\TikTokSetting::defaultStore()?->id ?? 0);
        $groupIds = TikTokProductGroupProduct::groupIdsOn($storeId);
        $states = app(TikTokListingStates::class)->forStore($storeId);

        $shop = $this->live->shop($c);
        if ($shop['products'] === null) {
            return ['tone' => 'error', 'summary' => 'Check against TikTok Shop: ' . $shop['error']];
        }
        $bySku = [];
        foreach ($shop['products'] as $ttId => $p) {
            foreach ($p['skus'] as $sku) {
                $bySku[strtolower($sku)] ??= (string) $ttId;
            }
        }

        $pfx = (string) config('catalog.prefix');
        $skusByProduct = [];
        foreach (DB::table($pfx . 'product')->whereIn('product_id', $productIds)->get(['product_id', 'sku', 'model']) as $p) {
            foreach ([$p->sku, $p->model] as $v) {
                if (trim((string) $v) !== '') $skusByProduct[(int) $p->product_id][] = strtolower(trim((string) $v));
            }
        }
        foreach (DB::table($pfx . 'product_option_value')->whereIn('product_id', $productIds)->whereNotNull('sku')->where('sku', '!=', '')->get(['product_id', 'sku']) as $r) {
            $skusByProduct[(int) $r->product_id][] = strtolower(trim((string) $r->sku));
        }
        foreach (DB::table('product_option_combinations')->whereIn('product_id', $productIds)->whereNotNull('sku')->where('sku', '!=', '')->get(['product_id', 'sku']) as $r) {
            $skusByProduct[(int) $r->product_id][] = strtolower(trim((string) $r->sku));
        }

        $verdicts = ['confirmed' => 0, 'linked' => 0, 'lost' => 0, 'taken' => 0, 'not_on_tiktok' => 0];
        $problems = [];
        foreach ($productIds as $productId) {
            $listing = TikTokListing::query()->where('product_id', $productId)->first();
            $pivot = TikTokProductGroupProduct::query()->onStore($storeId)->where('product_id', $productId)
                ->whereNotNull('tiktok_product_id')->orderByDesc('last_pushed_at')->first();
            $known = $listing?->tiktok_product_id ?: ($pivot?->tiktok_product_id);
            $hit = null;
            foreach ($skusByProduct[$productId] ?? [] as $sku) {
                if (isset($bySku[$sku])) {
                    $hit = $bySku[$sku];
                    break;
                }
            }

            $holder = $hit !== null ? $this->holder($hit, (int) $productId, $storeId) : null;

            if ($known) {
                if (isset($shop['products'][(string) $known])) {
                    TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_product_id' => (string) $known, 'last_checked_at' => now()]);
                    $this->writeProductStatus($groupIds, (int) $productId, ['tiktok_product_id' => (string) $known, 'sync_status' => 'pushed']);
                    $verdicts['confirmed']++;
                } elseif ($hit !== null && $holder === null) {
                    TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_product_id' => $hit, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null, 'last_checked_at' => now()]);
                    $this->writeProductStatus($groupIds, (int) $productId, ['tiktok_product_id' => $hit, 'tiktok_sku_id' => null, 'sync_status' => 'pushed']);
                    $verdicts['linked']++;
                } else {
                    \App\Integrations\Listings\DroppedLink::write(
                        'TikTok Shop', 'TikTok Listing', (int) $productId, (string) $known,
                        $storeId,
                        $skusByProduct[$productId] ?? [], count($shop['products'] ?? [])
                    );
                    TikTokListing::query()
                        ->withoutGlobalScope('tiktokStore')->where('tiktok_setting_id', $storeId)
                        ->where('product_id', $productId)
                        ->update(['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null, 'last_checked_at' => now()]);
                    $this->writeProductStatus($groupIds, (int) $productId, ['tiktok_product_id' => null, 'tiktok_sku_id' => null, 'sync_status' => 'error', 'push_error' => self::DROPPED]);
                    $states->recordOutcome((int) $productId, self::DROPPED);
                    $problems[] = "#{$productId}: not found on TikTok Shop; the link was dropped";
                    $verdicts['lost']++;
                }
                continue;
            }

            if ($hit !== null && $holder === null) {
                TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_product_id' => $hit, 'live_status' => null, 'live_checked_at' => null, 'last_checked_at' => now()]);
                $this->writeProductStatus($groupIds, (int) $productId, ['tiktok_product_id' => $hit, 'sync_status' => 'pushed']);
                $verdicts['linked']++;
            } elseif ($hit !== null) {
                $states->recordOutcome((int) $productId, 'TikTok Shop product ' . $hit . ' is already linked to catalog product #' . $holder . '. Unlink that product first.');
                $problems[] = "#{$productId}: TikTok Shop product {$hit} is already linked to catalog product #{$holder}";
                $verdicts['taken']++;
            } else {
                $verdicts['not_on_tiktok']++;
            }
        }

        $parts = [];
        if ($verdicts['confirmed']) $parts[] = "{$verdicts['confirmed']} confirmed on TikTok Shop";
        if ($verdicts['linked']) $parts[] = "{$verdicts['linked']} linked to the product TikTok Shop holds";
        if ($verdicts['lost']) $parts[] = "{$verdicts['lost']} no longer on TikTok Shop";
        if ($verdicts['taken']) $parts[] = "{$verdicts['taken']} blocked by another product holding the same TikTok Shop product";
        if ($verdicts['not_on_tiktok']) $parts[] = "{$verdicts['not_on_tiktok']} not on TikTok Shop";
        $summary = 'Checked ' . count($productIds) . ' against TikTok Shop: ' . (implode(', ', $parts) ?: 'nothing to report') . '.';
        if ($problems) $summary .= ' ' . implode('; ', array_slice($problems, 0, 5)) . '.';

        return ['tone' => ($verdicts['lost'] + $verdicts['taken']) > 0 ? 'error' : 'status', 'summary' => $summary];
    }

    private const DROPPED = 'Not found on TikTok Shop; the link was dropped.';

    private function holder(string $tiktokProductId, int $productId, int $storeId): ?int
    {
        $held = TikTokListing::query()->withoutGlobalScope('tiktokStore')->where('tiktok_setting_id', $storeId)
            ->where('tiktok_product_id', $tiktokProductId)->where('product_id', '!=', $productId)->value('product_id')
            ?: TikTokProductGroupProduct::query()->onStore($storeId)
                ->where('tiktok_product_id', $tiktokProductId)->where('product_id', '!=', $productId)->value('product_id');

        return $held ? (int) $held : null;
    }

    private function writeProductStatus(array $groupIds, int $productId, array $attributes): void
    {
        \App\Support\ChannelProductStatus::write(
            'tiktok_product_group_products',
            'tiktok_product_group_id',
            $groupIds,
            $productId,
            $attributes
        );
    }
}
