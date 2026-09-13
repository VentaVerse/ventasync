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
        return [
            'attention' => $this->bucket('attention')->distinct()->count('tiktok_listings.product_id'),
            'drift' => $this->bucket('drift')->distinct()->count('tiktok_listings.product_id'),
        ];
    }

    /**
     * The rows behind a figure, by the SAME query that counted them
     * (#240): the Listings page filters on this, so a dashboard number and
     * the rows it leads to can never disagree.
     *
     * @return list<int>
     */
    public function productIdsIn(string $bucket): array
    {
        return $this->bucket($bucket)->distinct()
            ->pluck('tiktok_listings.product_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** One definition of each bucket, counted and listed from here. */
    private function bucket(string $bucket): \Illuminate\Database\Eloquent\Builder
    {
        $pfx = (string) config('catalog.prefix');

        $linked = TikTokListing::query()->whereNotNull('tiktok_product_id');

        if ($bucket === 'drift') {
            return $linked
                ->whereIn('live_status', ['ACTIVATE', 'SELLER_DEACTIVATED'])
                ->join($pfx . 'product as p', 'p.product_id', '=', 'tiktok_listings.product_id')
                ->whereNotNull('tiktok_listings.last_pushed_at')
                ->whereColumn('p.date_modified', '>', 'tiktok_listings.last_pushed_at');
        }

        return $linked->whereIn('live_status', self::ATTENTION_RAW);
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
        $pivotTruth = TikTokProductGroupProduct::query()->onStore()
            ->whereIn('product_id', $productIds)
            ->whereNotNull('tiktok_product_id')
            ->orderByDesc('last_pushed_at')
            ->get()
            ->groupBy(fn ($p) => (int) $p->product_id)
            ->map(fn ($rows) => $rows->first());

        $modified = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->pluck('date_modified', 'product_id');

        /* Readiness for every product, linked or not (the owner, 2026-09-06,
         * the Ready column): a listed product answers the update's own
         * checklist. */
        $readiness = $this->readiness->forProducts($productIds);

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

            $r = $readiness[$productId] ?? ['ready' => false, 'gaps' => []];
            $out[$productId] = new ListingState(
                state: $state,
                inSync: $inSync,
                reasons: $reasons,
                ready: (bool) $r['ready'],
                missing: $r['gaps'] ?? [],
                checkedAt: $checkedAt,
                pushedAt: $pushedAt,
                channelStatusRaw: $raw,
                attentionLabel: $attentionLabel,
                inactiveLabel: 'Deactivated',
            );
        }

        return $out;
    }

    /**
     * The Error column (the owner, 2026-09-07): the message a TikTok Shop listing is
     * carrying right now, or null when nothing is wrong. One question, one
     * answer, no rule about how old a failure is: the scheduled refresh
     * re-runs the link check, and a successful push or sync clears the
     * message it wrote.
     *
     * @param list<int> $productIds
     * @return array<int, ?string>
     */
    public function errors(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $listings = TikTokListing::query()->whereIn('product_id', $productIds)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $pivots = TikTokProductGroupProduct::query()->onStore()->whereIn('product_id', $productIds)->get()->groupBy('product_id');

        $out = [];
        foreach ($productIds as $pid) {
            $listing = $listings->get($pid);
            $found = [];
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $found[] = [$listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : Carbon::createFromTimestamp(0), trim((string) $listing->last_push_error)];
            }
            // A group push writes its refusal on the pivot row.
            // A failure with no saved message still says it failed.
            $failed = ($pivots->get($pid) ?? collect())
                ->filter(fn ($r) => in_array((string) $r->sync_status, ['error', 'failed'], true) || trim((string) ($r->push_error ?? '')) !== '')
                ->sortByDesc('last_pushed_at')->first();
            if ($failed) {
                $found[] = [$failed->last_pushed_at ? Carbon::parse($failed->last_pushed_at) : Carbon::createFromTimestamp(0),
                    trim((string) $failed->push_error) ?: \App\Integrations\Push\PushLedger::NO_REASON];
            }
            usort($found, fn ($a, $b) => $b[0] <=> $a[0]);
            $out[$pid] = $found === [] ? null : $found[0][1];
        }

        return $out;
    }
}
