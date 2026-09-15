<?php

namespace Extensions\shopee\Services\Shopee;

use App\Integrations\Listings\ListingState;
use App\Integrations\Listings\ListingVariations;
use App\Integrations\Push\PushLedger;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * The Shopee engine behind ListingState (Phase B, #184): one computation,
 * batch-shaped, that every Shopee surface renders from. It reads only the
 * ERP's own records - the link mirror, the listing record, the catalogue
 * row, the readiness service - and never calls Shopee: freshness is the
 * mirror's job (the 30-minute automation, the Refresh button, and the
 * write-through every push now performs).
 *
 * A product can hold several link rows (one per variation model, rarely
 * several items). One product still gets ONE state: the rows' statuses
 * are folded by severity, worst first, because "one of its variations is
 * banned" IS the product's situation. Severity: attention > reviewing >
 * inactive > live; unknown only when no row has ever been checked.
 *
 * Drift is a Catalog change (App\Integrations\Listings\CatalogCopy): the
 * catalog product moved since the listing was last compared, and the two
 * differ. A product with no listing row has nothing to compare, and inSync
 * stays null rather than pretending.
 */
class ShopeeListingStates
{
    /** The store this instance reads and writes; null follows ShopeeSetting::defaultStore(). */
    private ?int $storeId = null;

    public function __construct(private readonly ShopeeListingReadiness $readiness)
    {
    }

    /**
     * The same engine, pinned to one store. A cron or command looping
     * stores writes through this, so an outcome lands on the store the
     * call was about and never on whichever store happens to be bound.
     */
    public function forStore(ShopeeSetting|int|null $store): static
    {
        $id = $store instanceof ShopeeSetting ? (int) $store->id : (int) $store;
        $copy = clone $this;
        $copy->storeId = $id > 0 ? $id : null;

        return $copy;
    }

    private function storeId(): int
    {
        return $this->storeId ?? (int) (ShopeeSetting::defaultStore()?->id ?? 0);
    }

    /** A store-owned query fenced to this store, request or console alike. */
    private function mine(string $model): \Illuminate\Database\Eloquent\Builder
    {
        $query = $model::query();
        $storeId = $this->storeId();

        return $storeId > 0
            ? $query->withoutGlobalScope('shopeeStore')->where($query->qualifyColumn('shopee_setting_id'), $storeId)
            : $query;
    }

    /**
     * The outcome of the latest thing done with this product on this
     * store. Null is a success and clears every error slot; a reason is a
     * failure and becomes the listing's error line.
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
        $listing = $this->mine(ShopeeListing::class)->firstOrCreate(
            ['product_id' => $productId],
            ['shopee_setting_id' => $storeId]
        );
        $listing->forceFill([
            'last_push_error' => Str::limit($reason, 477),
            'last_push_failed_at' => now(),
        ])->save();
    }

    /**
     * Clear every slot errors() reads for these products on this store,
     * and the group pivot's copy. An unlinked pivot row is left alone.
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

        $groupIds = ShopeeProductGroup::query()->withoutGlobalScope('shopeeStore')
            ->where('shopee_setting_id', $storeId)->pluck('id')->map(fn ($v) => (int) $v)->all();

        foreach (array_chunk($productIds, 500) as $chunk) {
            $this->mine(ShopeeListing::class)->whereIn('product_id', $chunk)
                ->where(fn ($q) => $q->whereNotNull('last_push_error')->orWhereNotNull('last_push_failed_at'))
                ->update(['last_push_error' => null, 'last_push_failed_at' => null]);

            $this->mine(ShopeeProductLink::class)->whereIn('product_id', $chunk)
                ->where('last_sync_ok', false)
                ->update(['last_sync_ok' => true, 'last_sync_error_code' => null, 'last_sync_error_message' => null]);

            if ($groupIds === []) {
                continue;
            }

            $linked = $this->mine(ShopeeProductLink::class)->whereIn('product_id', $chunk)
                ->whereNotNull('shopee_item_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $pivot = fn () => DB::table('shopee_product_group_products')
                ->whereIn('shopee_product_group_id', $groupIds)
                ->whereIn('product_id', $chunk);

            $pivot()->whereIn('sync_status', ['error', 'failed'])->whereIn('product_id', $linked ?: [0])
                ->update(['sync_status' => 'pushed', 'push_error' => null]);
            $pivot()->whereIn('sync_status', ['error', 'failed'])->whereNotIn('product_id', $linked ?: [0])
                ->update(['sync_status' => 'pending', 'push_error' => null]);
            $pivot()->whereNotNull('push_error')
                ->where(fn ($q) => $q->whereNull('sync_status')->orWhere('sync_status', '!=', 'unlinked'))
                ->update(['push_error' => null]);
        }
    }

    /**
     * The products that show an error line on this store, by the same
     * errors() the rows are drawn from, so the filter and the red lines
     * can never disagree.
     *
     * @return list<int>
     */
    public function erroredProductIds(): array
    {
        $storeId = $this->storeId();
        if ($storeId <= 0) {
            return [];
        }

        $candidates = $this->mine(ShopeeListing::class)
            ->whereNotNull('last_push_error')->where('last_push_error', '!=', '')
            ->pluck('product_id')
            ->merge($this->mine(ShopeeProductLink::class)->where('last_sync_ok', false)->pluck('product_id'))
            ->merge(ListingVariations::readProducts('shopee', $storeId))
            ->map(fn ($v) => (int) $v)->filter()->unique()->sort()->values()->all();

        $out = [];
        foreach (array_chunk($candidates, 500) as $chunk) {
            foreach ($this->errors($chunk) as $productId => $line) {
                if ($line !== null) {
                    $out[] = (int) $productId;
                }
            }
        }

        return $out;
    }

    /**
     * The shop's listing troubles in two numbers, for the dashboard chip:
     * how many products need a human (violation, deleted, vanished) and
     * how many are live but behind the catalogue. Aggregate SQL, not the
     * per-product path: the dashboard must not walk two thousand rows.
     *
     * @return array{attention: int, drift: int}
     */
    public function summary(): array
    {
        return [
            'attention' => $this->bucket('attention')->distinct()->count('shopee_product_links.product_id'),
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
            $this->bucket('drift')->get(), 'shopee_listings', ShopeeListing::copyColumns()
        );
    }

    /**
     * The rows behind a figure, by the SAME query that counted them
     * (#240). A number on the dashboard that leads nowhere is a fact the
     * operator cannot act on - the owner, 2026-09-08: "That doesnt really
     * answer the question needs attention. Im looking at Overview and in
     * listing, but I dont know what Im looking for." The Listings page
     * filters on this, so the count and the rows can never disagree.
     *
     * @return list<int>
     */
    public function productIdsIn(string $bucket): array
    {
        if ($bucket === 'drift') {
            return array_map('intval', array_keys($this->catalogChanges()));
        }

        return $this->bucket($bucket)->distinct()
            ->pluck('shopee_product_links.product_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** One definition of each bucket, counted and listed from here. */
    private function bucket(string $bucket): \Illuminate\Database\Eloquent\Builder
    {
        $pfx = (string) config('catalog.prefix');

        if ($bucket === 'drift') {
            return ShopeeListing::query()
                ->select('shopee_listings.*')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'shopee_listings.product_id')
                ->where(fn ($q) => $q->whereNull('shopee_listings.catalog_seen_at')
                    ->orWhereColumn('p.date_modified', '>', 'shopee_listings.catalog_seen_at'));
        }

        return ShopeeProductLink::query()
            ->whereIn('live_status', ['BANNED', 'SELLER_DELETE', 'SHOPEE_DELETE', 'MISSING']);
    }

    /** Worst first; the fold keeps the first status this list names. */
    private const SEVERITY = ['BANNED', 'SHOPEE_DELETE', 'SELLER_DELETE', 'MISSING', 'REVIEWING', 'UNLIST', 'NORMAL'];

    /**
     * The Last sync column, one answer for the Listings page and every
     * group page (the owner, 2026-09-05): a refusal wins and is told in full,
     * whichever surface pushed or synced; otherwise the newest successful
     * sync, stock and price on the link rows or the listing push, with
     * what it was; otherwise never.
     *
     * @param list<int> $productIds
     * @return array<int, array{tone: 'failed'|'ok'|'none', headline: string, detail: ?string, at: ?Carbon}>
     */
    /**
     * The Error column (the owner, 2026-09-07): the message a Shopee listing is
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
        $listings = $this->mine(ShopeeListing::class)->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $links = $this->mine(ShopeeProductLink::class)->whereIn('product_id', $productIds)->get()->groupBy('product_id');
        $missing = ListingVariations::missing('shopee', $this->storeId(), $productIds);

        $out = [];
        foreach ($productIds as $pid) {
            $listing = $listings->get($pid);
            $rows = $links->get($pid) ?? collect();

            // The newest of the two errors a row can carry: a refused push,
            // and a refused stock, price or link-check sync on a link row.
            $found = [];
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $found[] = [$listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : Carbon::createFromTimestamp(0), trim((string) $listing->last_push_error)];
            }
            // A failure with no saved message still says it failed: blank in
            // a column that only fills on failure reads as nothing wrong.
            $failedSync = $rows->filter(fn ($r) => $r->last_sync_ok !== null && ! $r->last_sync_ok)
                ->sortByDesc('last_synced_at')->first();
            if ($failedSync) {
                $found[] = [$failedSync->last_synced_at ? Carbon::parse($failedSync->last_synced_at) : Carbon::createFromTimestamp(0),
                    trim((string) $failedSync->last_sync_error_message) ?: \App\Integrations\Push\PushLedger::NO_REASON];
            }
            usort($found, fn ($a, $b) => $b[0] <=> $a[0]);
            // A sold variation the linked item does not hold rides beside it.
            $out[$pid] = ListingVariations::withMissing(
                $found === [] ? null : $found[0][1],
                isset($missing[$pid]) && $rows->isNotEmpty() ? ListingVariations::missingMessage('Shopee', $missing[$pid]) : null
            );
        }

        return $out;
    }

    /**
     * Remember the variation SKUs a store's item holds, as just read from
     * Shopee. The parent's own SKU and model are not variations, and a
     * product without variations has nothing to remember.
     *
     * @param iterable<string|null> $skus
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
        $own = [];
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

        ListingVariations::remember('shopee', $storeId, $productId, $held);
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

        $links = ShopeeProductLink::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');

        $listings = ShopeeListing::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $catalogChanges = \App\Integrations\Listings\CatalogCopy::pending($listings->values(), 'shopee_listings', ShopeeListing::copyColumns());

        /* Readiness for every product, linked or not (the owner, 2026-09-06,
         * the Ready column): a listed product answers the update's own
         * checklist, so Ready means the next push, whichever verb, passes
         * every check this side can run. */
        $readiness = $this->readiness->forProducts($productIds);

        $out = [];
        foreach ($productIds as $productId) {
            $listing = $listings->get($productId);
            $pushedAt = $listing?->last_pushed_at ? Carbon::parse($listing->last_pushed_at) : null;

            // One latest error per product (the owner, 2026-09-05): the last
            // refusal, from whichever surface pushed, told as a sentence on
            // every surface until a push succeeds.
            $failure = [];
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $failedAt = $listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : null;
                $failure[] = 'Last push' . ($failedAt ? ' ' . $failedAt->diffForHumans() : '') . ' failed: ' . trim((string) $listing->last_push_error);
            }

            if (!$links->has($productId)) {
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

            $rows = $links->get($productId);
            $statuses = $rows->pluck('live_status')->filter()->unique()->values()->all();
            $checkedAt = $rows->pluck('live_checked_at')->filter()->map(fn ($t) => Carbon::parse($t))->max();

            $worst = null;
            foreach (self::SEVERITY as $candidate) {
                if (in_array($candidate, $statuses, true)) {
                    $worst = $candidate;
                    break;
                }
            }

            $inSync = $listing ? !isset($catalogChanges[$productId]) : null;
            $reasons = $failure;

            // The attention label is Shopee's own word for the trouble;
            // core ListingState knows no marketplace's vocabulary (#184 C).
            [$state, $attentionLabel, $extraReason] = match ($worst) {
                'NORMAL' => [ListingState::LIVE, null, null],
                'UNLIST' => [ListingState::INACTIVE, null, null],
                'REVIEWING' => [ListingState::REVIEWING, null, 'Shopee is reviewing it; nothing to do until it answers.'],
                'BANNED' => [ListingState::ATTENTION, 'Violation', 'Shopee flagged a violation. Open the item in Seller Centre to see its notice.'],
                'MISSING' => [ListingState::ATTENTION, 'Not found on Shopee', 'Shopee no longer returns this listing. Run Check against Shopee, or push it again.'],
                'SELLER_DELETE' => [ListingState::ATTENTION, 'Deleted', 'It was deleted in Seller Centre. Push again to relist it.'],
                'SHOPEE_DELETE' => [ListingState::ATTENTION, 'Deleted by Shopee', 'Shopee removed it. Check the item in Seller Centre before pushing again.'],
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
                channelStatusRaw: $worst,
                attentionLabel: $attentionLabel,
                inactiveLabel: 'Unpublished',
            );
        }

        return $out;
    }
}
