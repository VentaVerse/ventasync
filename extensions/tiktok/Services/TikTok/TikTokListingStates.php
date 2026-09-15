<?php

namespace Extensions\tiktok\Services\TikTok;

use App\Integrations\Listings\ListingState;
use App\Integrations\Listings\ListingVariations;
use App\Integrations\Push\PushLedger;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

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

    /** The store the error slots are read and written for; null means the store in hand. */
    private ?int $store = null;

    /**
     * The same engine, pinned to one store. A loop over stores, or any
     * write about a store other than the bound one, goes through here so
     * it never lands on whichever store happens to be in hand.
     */
    public function forStore(TikTokSetting|int $store): static
    {
        $pinned = clone $this;
        $pinned->store = $store instanceof TikTokSetting ? (int) $store->id : (int) $store;

        return $pinned;
    }

    private function storeId(): int
    {
        return $this->store ?? (int) (TikTokSetting::defaultStore()?->id ?? 0);
    }

    /** This store's listing rows, named rather than left to the request's read fence. */
    private function listingsOnStore(): \Illuminate\Database\Eloquent\Builder
    {
        return TikTokListing::query()->withoutGlobalScope('tiktokStore')
            ->where('tiktok_listings.tiktok_setting_id', $this->storeId());
    }

    /**
     * The outcome of the latest thing done with this product on this
     * store. Null is a success and clears every error slot; a reason is
     * written onto the listing row, which is created when missing.
     */
    public function recordOutcome(int $productId, ?string $failure): void
    {
        if ($failure === null) {
            $this->clearErrors([$productId]);

            return;
        }
        $storeId = $this->storeId();
        if ($productId <= 0 || $storeId <= 0) {
            return;
        }

        $reason = trim($failure) !== '' ? trim($failure) : PushLedger::NO_REASON;
        // The column holds 480 characters, the trailing marker included.
        $values = ['last_push_error' => Str::limit($reason, 477), 'last_push_failed_at' => now()];
        if ($this->listingsOnStore()->where('product_id', $productId)->exists()) {
            $this->listingsOnStore()->where('product_id', $productId)->update($values);
        } else {
            (new TikTokListing())->forceFill(['tiktok_setting_id' => $storeId, 'product_id' => $productId] + $values)->save();
        }
    }

    /**
     * Clear every slot errors() reads for these products on this store: the
     * listing's own error and the group pivots' failures. A pivot marked
     * unlinked keeps that status.
     *
     * @param list<int> $productIds
     */
    public function clearErrors(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($v) => $v > 0)));
        $storeId = $this->storeId();
        if ($productIds === [] || $storeId <= 0) {
            return;
        }

        foreach (array_chunk($productIds, 500) as $chunk) {
            $this->listingsOnStore()->whereIn('product_id', $chunk)
                ->where(fn ($q) => $q->whereNotNull('last_push_error')->orWhereNotNull('last_push_failed_at'))
                ->update(['last_push_error' => null, 'last_push_failed_at' => null]);

            $pivots = fn () => TikTokProductGroupProduct::query()->onStore($storeId)->whereIn('product_id', $chunk);
            $pivots()->whereIn('sync_status', ['error', 'failed'])
                ->whereNotNull('tiktok_product_id')->where('tiktok_product_id', '!=', '')
                ->update(['sync_status' => 'pushed', 'push_error' => null]);
            $pivots()->whereIn('sync_status', ['error', 'failed'])
                ->update(['sync_status' => 'pending', 'push_error' => null]);
            $pivots()->whereNotNull('push_error')->update(['push_error' => null]);
        }
    }

    /**
     * The products on this store whose row shows an error line: the one
     * definition behind the error filter, so the filter and the red lines
     * cannot disagree.
     *
     * @return list<int>
     */
    public function erroredProductIds(): array
    {
        $storeId = $this->storeId();
        if ($storeId <= 0) {
            return [];
        }

        $candidates = $this->listingsOnStore()
            ->whereNotNull('last_push_error')->where('last_push_error', '!=', '')
            ->pluck('product_id')
            ->merge(TikTokProductGroupProduct::query()->onStore($storeId)
                ->where(fn ($q) => $q->whereIn('sync_status', ['error', 'failed'])
                    ->orWhere(fn ($w) => $w->whereNotNull('push_error')->where('push_error', '!=', '')))
                ->pluck('product_id'))
            ->merge(ListingVariations::readProducts('tiktok', $storeId))
            ->map(fn ($v) => (int) $v)->filter(fn ($v) => $v > 0)
            ->unique()->sort()->values()->all();

        $out = [];
        foreach (array_chunk($candidates, 500) as $chunk) {
            foreach ($this->errors($chunk) as $pid => $line) {
                if ($line !== null) {
                    $out[] = (int) $pid;
                }
            }
        }

        return $out;
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
            'drift' => count($this->catalogChanges()),
        ];
    }

    /**
     * The listings showing a Catalog change: their catalog product moved
     * since they were last compared, and the two differ.
     *
     * @return array<int, list<string>>  product id => the fields that differ
     */
    private function catalogChanges(): array
    {
        return \App\Integrations\Listings\CatalogCopy::pending(
            $this->bucket('drift')->get(), 'tiktok_listings', TikTokListing::copyColumns()
        );
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
        if ($bucket === 'drift') {
            return array_map('intval', array_keys($this->catalogChanges()));
        }

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
            return TikTokListing::query()
                ->select('tiktok_listings.*')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'tiktok_listings.product_id')
                ->where(fn ($q) => $q->whereNull('tiktok_listings.catalog_seen_at')
                    ->orWhereColumn('p.date_modified', '>', 'tiktok_listings.catalog_seen_at'));
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

        $catalogChanges = \App\Integrations\Listings\CatalogCopy::pending($listings->values(), 'tiktok_listings', TikTokListing::copyColumns());

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
                    inSync: $listing ? !isset($catalogChanges[$productId]) : null,
                    reasons: $failure,
                    ready: (bool) $r['ready'],
                    missing: $r['gaps'] ?? [],
                    pushedAt: $pushedAt,
                );
                continue;
            }

            $raw = $listing?->live_status !== null ? strtoupper((string) $listing->live_status) : null;
            $checkedAt = $listing?->live_checked_at ? Carbon::parse($listing->live_checked_at) : null;

            // A product on TikTok only through a group pivot has no copy of its own.
            $inSync = $listing ? !isset($catalogChanges[$productId]) : null;
            $reasons = $failure;

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
        $storeId = $this->storeId();
        $listings = $this->listingsOnStore()->whereIn('product_id', $productIds)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $pivots = TikTokProductGroupProduct::query()->onStore($storeId)->whereIn('product_id', $productIds)->get()->groupBy('product_id');
        $missing = ListingVariations::missing('tiktok', $storeId, $productIds);

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
            // A sold variation the linked item does not hold rides beside it.
            $linked = ($listing && !empty($listing->tiktok_product_id))
                || ($pivots->get($pid) ?? collect())->contains(fn ($r) => !empty($r->tiktok_product_id));
            $out[$pid] = ListingVariations::withMissing(
                $found === [] ? null : $found[0][1],
                isset($missing[$pid]) && $linked ? ListingVariations::missingMessage('TikTok Shop', $missing[$pid]) : null
            );
        }

        return $out;
    }

    /**
     * Remember the variation SKUs a store's item holds, as just read from
     * TikTok Shop. The parent's own SKU and model are not variations, nor
     * is the __single__ placeholder, and a product without variations has
     * nothing to remember.
     *
     * @param iterable<string|int|null> $skus
     */
    public static function rememberHeld(int $storeId, int $productId, iterable $skus): void
    {
        if ($storeId <= 0 || $productId <= 0) {
            return;
        }
        $pfx = (string) config('catalog.prefix');
        $variations = [];
        foreach (PushLedger::erpVariationSkus([$productId], $pfx)[$productId] ?? [] as $sku) {
            $variations[strtolower(trim((string) $sku))] = true;
        }
        if ($variations === []) {
            return;
        }

        $parent = DB::table($pfx . 'product')->where('product_id', $productId)->first(['sku', 'model']);
        $own = ['__single__' => true];
        foreach ([$parent->sku ?? '', $parent->model ?? ''] as $s) {
            $k = strtolower(trim((string) $s));
            if ($k !== '' && !isset($variations[$k])) {
                $own[$k] = true;
            }
        }

        $held = [];
        foreach ($skus as $sku) {
            $k = strtolower(trim((string) $sku));
            if ($k !== '' && !isset($own[$k])) {
                $held[] = trim((string) $sku);
            }
        }

        ListingVariations::remember('tiktok', $storeId, $productId, $held);
    }
}
