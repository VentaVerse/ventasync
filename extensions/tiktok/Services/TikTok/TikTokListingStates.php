<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Integrations\Listings\ListingState;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The TikTok engine behind ListingState (Phase D, #184): one computation,
 * batch-shaped, that every TikTok surface renders from. It reads only the
 * ERP's own records - the listing row (settings, mirror and provenance in
 * one, product_id unique so no severity fold), the group pivots, the
 * catalogue row, the readiness service - and never calls TikTok:
 * freshness is the mirror's job (the 30-minute automation, the Refresh
 * button, and write-through on toggles and the edit page).
 *
 * The one wrinkle Lazada does not have: a product pushed before the
 * listing row carried the truth may be on TikTok only through a group
 * PIVOT row. Those count as linked here - with no mirror of their own
 * they read "Not checked yet" honestly until the next sweep back-fills
 * a listing row for them, which refreshMirror() already does.
 *
 * Raw vocabulary is TikTok's own words plus the ERP's honest MISSING
 * (uploaded once, no longer returned by the shop walk):
 *
 *   ACTIVATE → live · SELLER_DEACTIVATED → inactive
 *   IN_REVIEW / PENDING → reviewing
 *   FAILED / PLATFORM_DEACTIVATED / FREEZE / DRAFT / DELETED / MISSING → attention
 *   null (never checked) → unknown
 *
 * DRAFT is attention, deliberately: the product exists on TikTok but can
 * never go live without a human, and offering Activate against a draft
 * would send a call TikTok refuses. The label says exactly what it is.
 */
class TikTokListingStates
{
    public function __construct(private readonly TikTokListingReadiness $readiness)
    {
    }

    private const ATTENTION_RAW = ['FAILED', 'PLATFORM_DEACTIVATED', 'FREEZE', 'DRAFT', 'DELETED', 'MISSING'];

    /**
     * The shop's listing troubles in two numbers, for the dashboard chip.
     * Aggregate SQL, not the per-product path.
     *
     * @return array{attention: int, drift: int}
     */
    public function summary(): array
    {
        $pfx = (string) config('catalog.prefix');

        $linked = TikTokListing::query()->whereNotNull('tiktok_product_id');

        $attention = (clone $linked)
            ->whereIn('live_status', self::ATTENTION_RAW)
            ->distinct()->count('product_id');

        $drift = (clone $linked)
            ->whereIn('live_status', ['ACTIVATE', 'SELLER_DEACTIVATED'])
            ->join($pfx . 'product as p', 'p.product_id', '=', 'tiktok_listings.product_id')
            ->whereNotNull('tiktok_listings.last_pushed_at')
            ->whereColumn('p.date_modified', '>', 'tiktok_listings.last_pushed_at')
            ->distinct()->count('tiktok_listings.product_id');

        return ['attention' => $attention, 'drift' => $drift];
    }

    /**
     * @param list<int> $productIds
     * @return array<int, ListingState> keyed by product id
     */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $pfx = (string) config('catalog.prefix');

        $listings = TikTokListing::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy(fn ($l) => (int) $l->product_id);

        // Products on TikTok only through a group pivot (pushed before the
        // listing row carried the truth): linked, mirror unknown.
        $pivotTruth = TikTokProductGroupProduct::query()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('tiktok_product_id')
            ->orderByDesc('last_pushed_at')
            ->get()
            ->groupBy(fn ($p) => (int) $p->product_id)
            ->map(fn ($rows) => $rows->first());

        $modified = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->pluck('date_modified', 'product_id');

        $unlinked = array_values(array_filter($productIds, function ($id) use ($listings, $pivotTruth) {
            return !($listings->get($id)?->tiktok_product_id) && !$pivotTruth->has($id);
        }));
        $readiness = $this->readiness->forProducts($unlinked);

        $out = [];
        foreach ($productIds as $productId) {
            $listing = $listings->get($productId);
            $linked = (bool) ($listing?->tiktok_product_id) || $pivotTruth->has($productId);
            $pushedAtRaw = $listing?->last_pushed_at ?? $pivotTruth->get($productId)?->last_pushed_at;
            $pushedAt = $pushedAtRaw ? Carbon::parse($pushedAtRaw) : null;

            // One latest error per product (the owner, 2026-09-05): the last
            // refusal, from whichever surface pushed, told on every surface.
            $failure = [];
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $failedAt = $listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : null;
                $failure[] = 'Last push' . ($failedAt ? ' ' . $failedAt->diffForHumans() : '') . ' failed: ' . trim((string) $listing->last_push_error);
            }

            if (!$linked) {
                $r = $readiness[$productId] ?? ['ready' => false, 'missing' => [], 'gaps' => []];
                $out[$productId] = new ListingState(
                    state: ListingState::NOT_LISTED,
                    reasons: $failure,
                    ready: (bool) $r['ready'],
                    missing: $r['gaps'] ?? [],
                    pushedAt: $pushedAt,
                );
                continue;
            }

            $raw = $listing?->live_status !== null ? strtoupper((string) $listing->live_status) : null;
            $checkedAt = $listing?->live_checked_at ? Carbon::parse($listing->live_checked_at) : null;

            // Drift is a fact about live and deactivated listings; one
            // TikTok no longer shows has bigger problems than staleness.
            $inSync = null;
            $reasons = $failure;
            if (in_array($raw, ['ACTIVATE', 'SELLER_DEACTIVATED'], true) && $pushedAt !== null) {
                $movedAt = ($modified[$productId] ?? null) ? Carbon::parse($modified[$productId]) : null;
                $inSync = !($movedAt && $movedAt->gt($pushedAt));
                if ($inSync === false) {
                    $reasons[] = 'The catalogue changed after the last push. Push an update when ready.';
                }
            }

            [$state, $attentionLabel, $extraReason] = match ($raw) {
                'ACTIVATE' => [ListingState::LIVE, null, null],
                'SELLER_DEACTIVATED' => [ListingState::INACTIVE, null, null],
                'IN_REVIEW', 'PENDING' => [ListingState::REVIEWING, null, 'TikTok Shop is reviewing it; nothing to do until it answers.'],
                'FAILED' => [ListingState::ATTENTION, 'Failed review', 'TikTok Shop rejected it. Open the product in Seller Center to see why, fix it, and push again.'],
                'PLATFORM_DEACTIVATED' => [ListingState::ATTENTION, 'Deactivated by TikTok', 'TikTok Shop switched it off. Check the product in Seller Center before pushing again.'],
                'FREEZE' => [ListingState::ATTENTION, 'Frozen', 'TikTok Shop froze this listing. It cannot be edited or sold until TikTok releases it.'],
                'DRAFT' => [ListingState::ATTENTION, 'Draft', 'It sits as a draft on TikTok Shop; buyers cannot see it. Publish it in Seller Center, or push an update from here.'],
                'DELETED' => [ListingState::ATTENTION, 'Deleted', 'It was deleted on TikTok Shop. Push again to relist it.'],
                'MISSING' => [ListingState::ATTENTION, 'Not found on TikTok Shop', 'TikTok Shop no longer returns this listing. Refresh from TikTok Shop, or push it again.'],
                default => [ListingState::UNKNOWN, null, null],
            };
            if ($extraReason !== null) {
                $reasons[] = $extraReason;
            }

            $out[$productId] = new ListingState(
                state: $state,
                inSync: $inSync,
                reasons: $reasons,
                checkedAt: $checkedAt,
                pushedAt: $pushedAt,
                channelStatusRaw: $raw,
                attentionLabel: $attentionLabel,
            );
        }

        return $out;
    }

    /**
     * The Last sync column, one answer for the Listings page and every
     * group page (the owner, 2026-09-05): a refusal wins and is told in full,
     * whichever surface pushed or synced; otherwise the newest successful
     * sync and what it was; otherwise never.
     *
     * @param list<int> $productIds
     * @return array<int, array{tone: 'failed'|'ok'|'none', headline: string, detail: ?string, at: ?Carbon}>
     */
    public function lastSync(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $modified = DB::table((string) config('catalog.prefix') . 'product')
            ->whereIn('product_id', $productIds)->pluck('date_modified', 'product_id');
        $word = fn (?string $action): string => match (true) {
            $action === null || trim($action) === '' => 'sync',
            (bool) preg_match('/qty|stock/i', $action) => 'stock',
            (bool) preg_match('/price/i', $action) => 'price',
            (bool) preg_match('/upload|push|create|update/i', $action) => 'listing',
            (bool) preg_match('/_id$/i', $action) => 'link check',
            (bool) preg_match('/delete/i', $action) => 'delete',
            default => str_replace('_', ' ', strtolower(trim($action))),
        };

        $out = [];
        foreach ($productIds as $pid) {
            $out[$pid] = $this->lastSyncFor($pid, $word, ($modified[$pid] ?? null) ? Carbon::parse($modified[$pid]) : null);
        }

        return $out;
    }

    /** @param \Closure(?string): string $word */
    private function lastSyncFor(int $pid, \Closure $word, ?Carbon $movedAt): array
    {
        $listing = TikTokListing::query()->where('product_id', $pid)->first();
        if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
            $at = $listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : null;
            return ['tone' => 'failed', 'headline' => 'Last push' . ($at ? ' ' . $at->diffForHumans() : '') . ' failed:', 'detail' => trim((string) $listing->last_push_error), 'at' => $at];
        }
        $candidates = [];
        if ($listing?->last_pushed_at) {
            $candidates[] = [Carbon::parse($listing->last_pushed_at), 'listing'];
        }
        $pivotAt = TikTokProductGroupProduct::query()->where('product_id', $pid)->whereNotNull('tiktok_product_id')->max('last_pushed_at');
        if ($pivotAt) {
            $candidates[] = [Carbon::parse($pivotAt), 'listing'];
        }
        usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);
        if ($candidates === []) {
            return ['tone' => 'none', 'headline' => 'Never synced', 'detail' => null, 'at' => null];
        }
        [$at, $what] = $candidates[0];
        $stale = $movedAt && $movedAt->gt($at);
        return ['tone' => 'ok', 'headline' => 'Synced ' . $at->diffForHumans() . ' (' . $what . ')' . ($stale ? ', catalog changed since' : ''), 'detail' => null, 'at' => $at];
    }
}
