<?php

namespace Extensions\shopee\Services\Shopee;

use App\Integrations\Listings\ListingState;
use App\Integrations\Listings\ListingVariations;
use App\Integrations\Push\PushLedger;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
 * Drift (#100): the catalogue moved after the last push from here. Judged
 * on product.date_modified vs listing.last_pushed_at - a timestamp, not a
 * content hash, so it can cry once for a change the push would not carry;
 * it can never stay silent about one it would. A linked product never
 * pushed from the ERP (matched or reconciled in) has nothing to compare,
 * and inSync stays null rather than pretending.
 */
class ShopeeListingStates
{
    public function __construct(private readonly ShopeeListingReadiness $readiness)
    {
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
            'drift' => $this->bucket('drift')->distinct()->count('shopee_product_links.product_id'),
        ];
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
        return $this->bucket($bucket)->distinct()
            ->pluck('shopee_product_links.product_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** One definition of each bucket, counted and listed from here. */
    private function bucket(string $bucket): \Illuminate\Database\Eloquent\Builder
    {
        $pfx = (string) config('catalog.prefix');

        if ($bucket === 'drift') {
            return ShopeeProductLink::query()
                ->whereIn('live_status', ['NORMAL', 'UNLIST'])
                ->join('shopee_listings as sl', 'sl.product_id', '=', 'shopee_product_links.product_id')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'shopee_product_links.product_id')
                ->whereNotNull('sl.last_pushed_at')
                ->whereColumn('p.date_modified', '>', 'sl.last_pushed_at');
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
        $listings = ShopeeListing::query()->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $links = ShopeeProductLink::query()->whereIn('product_id', $productIds)->get()->groupBy('product_id');
        $missing = ListingVariations::missing('shopee', (int) (ShopeeSetting::defaultStore()?->id ?? 0), $productIds);

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

        $modified = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->pluck('date_modified', 'product_id');

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

            // Drift is a fact about live and inactive listings; a listing
            // Shopee no longer shows has bigger problems than staleness.
            $inSync = null;
            $reasons = $failure;
            if (in_array($worst, ['NORMAL', 'UNLIST'], true) && $pushedAt !== null) {
                $movedAt = ($modified[$productId] ?? null) ? Carbon::parse($modified[$productId]) : null;
                $inSync = !($movedAt && $movedAt->gt($pushedAt));
                if ($inSync === false) {
                    $reasons[] = 'The catalogue changed after the last push. Push an update when ready.';
                }
            }

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
