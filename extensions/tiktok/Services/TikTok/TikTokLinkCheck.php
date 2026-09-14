<?php

namespace Extensions\tiktok\Services\TikTok;

use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Illuminate\Support\Facades\DB;

/**
 * Ask TikTok Shop about these products and make the ERP agree (Phase F,
 * #184). Extracted from the group page so the workbench can run the
 * same check: the sweep was never group-shaped - it reads the whole
 * shop either way - only its entry point was.
 *
 * Reads only. A check is not a push: it confirms, adopts, repoints or
 * drops LINKS, and writes nothing to TikTok Shop.
 */
class TikTokLinkCheck
{
    public function __construct(private readonly TikTokLiveListing $live)
    {
    }

    /**
     * The next page of this store's links, checked (the owner, 2026-09-07).
     * Both the Refresh button and the scheduled refresh call this, which is
     * what stops them from drifting apart: the cron used to re-read statuses
     * and never re-run the check, so a failed check could not clear itself
     * and a row kept a six-month-old refusal until somebody pressed the
     * button. The cursor caps a run at two hundred and continues where the
     * last one stopped.
     *
     * @return ?array the check's own result, or null when nothing is linked
     */
    public function runNext(array $c, int $storeId): ?array
    {
        $linked = \Extensions\tiktok\Models\TikTokListing::query()->whereNotNull('tiktok_product_id')->pluck('product_id')
            ->merge(\Extensions\tiktok\Models\TikTokProductGroupProduct::query()->onStore($storeId)->whereNotNull('tiktok_product_id')->pluck('product_id'))
            ->map(fn ($v) => (int) $v)->unique()->sort()->values()->all();
        $linked = \App\Integrations\Support\LinkCheckCursor::rotate('tiktok:' . $storeId, $linked);

        return $linked !== [] ? $this->run($c, $linked, $storeId) : null;
    }

    /** @return array{tone: 'status'|'error', summary: string} */
    public function run(array $c, array $productIds, ?int $storeId = null): array
    {
        // The run's store: the caller's, else the credentials' own, else the store in hand.
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
            // Any of this store's pivots will do for a remembered id: the
            // group the check used to run from was just one of them.
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

            // One catalog product to one TikTok Shop product: a match another product holds is not taken over.
            $holder = $hit !== null ? $this->holder($hit, (int) $productId, $storeId) : null;

            if ($known) {
                if (isset($shop['products'][(string) $known])) {
                    TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_product_id' => (string) $known, 'last_checked_at' => now()]);
                    // A check is a read: it can find a failure, never clear one.
                    $this->writeProductStatus($groupIds, (int) $productId, ['tiktok_product_id' => (string) $known, 'sync_status' => 'pushed']);
                    $verdicts['confirmed']++;
                } elseif ($hit !== null && $holder === null) {
                    // A fresh link voids the mirror: whatever status the row
                    // wore belonged to the previous link (#184 D).
                    TikTokListing::query()->updateOrCreate(['product_id' => $productId], ['tiktok_product_id' => $hit, 'tiktok_sku_id' => null, 'live_status' => null, 'live_checked_at' => null, 'last_checked_at' => now()]);
                    $this->writeProductStatus($groupIds, (int) $productId, ['tiktok_product_id' => $hit, 'tiktok_sku_id' => null, 'sync_status' => 'pushed']);
                    $verdicts['linked']++;
                } else {
                    /* AND IT IS WRITTEN DOWN (parity, 2026-09-09): dropping
                     * a link is destructive and left no trace, so an
                     * operator watching links vanish had nothing to look at
                     * and no way to tell a correct drop from a wrong one. */
                    \App\Integrations\Listings\DroppedLink::write(
                        'TikTok Shop', 'TikTok Listing', (int) $productId, (string) $known,
                        // The store's own id, never the shop cipher: the
                        // cipher is a credential and this line is read by
                        // people.
                        $storeId,
                        $skusByProduct[$productId] ?? [], count($shop['products'] ?? [])
                    );
                    /* THIS store's row, named rather than assumed: the read
                       fence in BelongsToTikTokStore is only up inside a store
                       request, and dropping a link from the console used to
                       drop it on every shop that held the product. */
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
                // No last_push_source here: a check is not a push, and a
                // source with no timestamp read as provenance from nowhere.
                // Never pushed from the ERP means drift honestly unknowable.
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

    /** Another catalog product on this store that already holds this TikTok Shop product, if any. */
    private function holder(string $tiktokProductId, int $productId, int $storeId): ?int
    {
        $held = TikTokListing::query()->withoutGlobalScope('tiktokStore')->where('tiktok_setting_id', $storeId)
            ->where('tiktok_product_id', $tiktokProductId)->where('product_id', '!=', $productId)->value('product_id')
            ?: TikTokProductGroupProduct::query()->onStore($storeId)
                ->where('tiktok_product_id', $tiktokProductId)->where('product_id', '!=', $productId)->value('product_id');

        return $held ? (int) $held : null;
    }

    /** @param int[] $groupIds the run's store's groups; an empty list writes nothing */
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
