<?php

namespace Extensions\venta\Services\Venta;

use App\Integrations\Listings\ListingState;
use App\Integrations\Listings\ListingVariations;
use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * ONE state per product on a Venta store, from the ERP's own records and
 * never the store: the link, the mirror on the listing row, drift against
 * the catalog, readiness for a first push, and the latest push failure.
 * The same contract Shopee's, Lazada's, TikTok's and WooCommerce's engines
 * answer, so the shared listing cell and the core products grid read Venta
 * the way they read every other channel (parity, 2026-09-06).
 */
class VentaListingStates
{
    public function __construct(private readonly int $storeId) {}

    public static function for(int $storeId): self
    {
        return new self($storeId);
    }

    /**
     * The store's listing troubles in two numbers, for the dashboard chip:
     * how many products need a human, and how many are live but behind the
     * catalog. Aggregate SQL, not the per-product path - the dashboard must
     * not walk two thousand rows.
     *
     * @return array{attention: int, drift: int}
     */
    public function summary(): array
    {
        return [
            'attention' => $this->bucket('attention')->distinct()->count('venta_listings.product_id'),
            'drift' => $this->bucket('drift')->distinct()->count('venta_listings.product_id'),
        ];
    }

    /**
     * The rows behind a figure, by the SAME query that counted them: the
     * Listings page filters on this, so a dashboard number and the rows it
     * leads to can never disagree.
     *
     * @return list<int>
     */
    public function productIdsIn(string $bucket): array
    {
        return $this->bucket($bucket)->distinct()
            ->pluck('venta_listings.product_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    /** One definition of each bucket, counted and listed from here. */
    private function bucket(string $bucket): \Illuminate\Database\Eloquent\Builder
    {
        $pfx = (string) config('catalog.prefix');

        $mine = VentaListing::query()->where('venta_listings.venta_setting_id', $this->storeId);

        if ($bucket === 'drift') {
            return $mine
                ->whereIn('live_status', [VentaListing::STATUS_ACTIVE, VentaListing::STATUS_INACTIVE])
                ->join($pfx . 'product as p', 'p.product_id', '=', 'venta_listings.product_id')
                ->whereNotNull('venta_listings.last_pushed_at')
                ->whereColumn('p.date_modified', '>', 'venta_listings.last_pushed_at');
        }

        // The store no longer returns the product: the one condition on
        // this channel that needs a human rather than a push.
        return $mine->where('live_status', VentaListing::STATUS_MISSING);
    }

    /** @return array<int, ListingState> */
    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $pfx = (string) config('catalog.prefix');
        $links = VentaProductLink::query()->where('venta_setting_id', $this->storeId)->whereIn('product_id', $productIds)->whereNotNull('venta_product_id')->get()->keyBy(fn ($l) => (int) $l->product_id);
        $listings = VentaListing::query()->where('venta_setting_id', $this->storeId)->whereIn('product_id', $productIds)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $products = DB::table($pfx . 'product')->whereIn('product_id', $productIds)->get(['product_id', 'sku', 'model', 'image', 'date_modified'])->keyBy('product_id');
        $attempts = $this->latestAttempts($productIds);
        $readiness = VentaListingReadiness::forProducts($productIds);

        $out = [];
        foreach ($productIds as $pid) {
            $link = $links->get($pid);
            $listing = $listings->get($pid);
            $prod = $products->get($pid);
            $attempt = $attempts->get($pid);
            $pushedAt = $listing?->last_pushed_at ? Carbon::parse($listing->last_pushed_at) : ($attempt?->last_pushed_at ? Carbon::parse($attempt->last_pushed_at) : null);
            $failure = [];
            if ($attempt && in_array((string) $attempt->sync_status, ['error', 'failed'], true) && trim((string) ($attempt->push_error ?? '')) !== '') {
                $failedAt = $attempt->last_pushed_at ? Carbon::parse($attempt->last_pushed_at) : null;
                $failure[] = 'Last push' . ($failedAt ? ' ' . $failedAt->diffForHumans() : '') . ' failed: ' . trim((string) $attempt->push_error);
            }
            // The Ready column's answer: the push's own checklist, for a
            // product on the store and one not yet on it alike (2026-09-06).
            $r = $readiness[$pid] ?? ['ready' => false, 'gaps' => []];
            if (! $link) {
                $out[$pid] = new ListingState(state: ListingState::NOT_LISTED, reasons: $failure, ready: (bool) $r['ready'], missing: $r['gaps'], pushedAt: $pushedAt);
                continue;
            }
            $raw = $listing?->live_status !== null ? strtolower((string) $listing->live_status) : null;
            $checkedAt = $listing?->live_checked_at ? Carbon::parse($listing->live_checked_at) : null;
            $inSync = null;
            $reasons = $failure;
            if (in_array($raw, [VentaListing::STATUS_ACTIVE, VentaListing::STATUS_INACTIVE], true) && $pushedAt !== null && $prod) {
                $movedAt = $prod->date_modified ? Carbon::parse($prod->date_modified) : null;
                $inSync = ! ($movedAt && $movedAt->gt($pushedAt));
                if ($inSync === false) {
                    $reasons[] = 'The catalog changed after the last push. Push an update when ready.';
                }
            }
            [$state, $attentionLabel, $extra] = match ($raw) {
                VentaListing::STATUS_ACTIVE => [ListingState::LIVE, null, null],
                VentaListing::STATUS_INACTIVE => [ListingState::INACTIVE, null, null],
                VentaListing::STATUS_MISSING => [ListingState::ATTENTION, 'Not on the store', 'The store no longer returns this product. Refresh the status, or push it again.'],
                default => [ListingState::UNKNOWN, null, null],
            };
            if ($extra !== null) {
                $reasons[] = $extra;
            }
            $out[$pid] = new ListingState(state: $state, inSync: $inSync, reasons: $reasons, ready: (bool) $r['ready'], missing: $r['gaps'], checkedAt: $checkedAt, pushedAt: $pushedAt, channelStatusRaw: $raw, attentionLabel: $attentionLabel);
        }

        return $out;
    }

    /**
     * The Error column (the owner, 2026-09-07): the message a listing on this store is
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
        $listings = VentaListing::query()->where('venta_setting_id', $this->storeId)->whereIn('product_id', $productIds)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $attempts = $this->latestAttempts($productIds);
        // A variation this store sells that its own item does not hold.
        $missing = ListingVariations::missing('venta', $this->storeId, $productIds);
        $storeName = $missing !== [] ? (string) VentaSetting::query()->whereKey($this->storeId)->value('store_name') : '';

        $out = [];
        foreach ($productIds as $pid) {
            $l = $listings->get($pid);
            $a = $attempts->get($pid);
            $found = [];
            if ($l && trim((string) ($l->last_push_error ?? '')) !== '') {
                $found[] = [$l->last_push_failed_at ? Carbon::parse($l->last_push_failed_at) : Carbon::createFromTimestamp(0), trim((string) $l->last_push_error)];
            }
            // A group push writes its refusal on the pivot row.
            // A failure with no saved message still says it failed.
            if ($a && in_array((string) $a->sync_status, ['error', 'failed'], true)) {
                $found[] = [$a->last_pushed_at ? Carbon::parse($a->last_pushed_at) : Carbon::createFromTimestamp(0),
                    trim((string) ($a->push_error ?? '')) ?: \App\Integrations\Push\PushLedger::NO_REASON];
            }
            usort($found, fn ($x, $y) => $y[0] <=> $x[0]);
            $out[$pid] = ListingVariations::withMissing($found === [] ? null : $found[0][1], isset($missing[$pid]) ? ListingVariations::missingMessage($storeName, $missing[$pid]) : null);
        }

        return $out;
    }

    /**
     * The outcome of the latest thing done with this product on this store:
     * null clears every error slot, a failure is written to the listing's
     * own slot, dated now, so it is the newest the error line reads.
     */
    public function recordOutcome(int $productId, ?string $failure): void
    {
        if ($failure === null) {
            $this->clearErrors([$productId]);

            return;
        }
        $text = trim($failure) !== '' ? trim($failure) : \App\Integrations\Push\PushLedger::NO_REASON;
        VentaListing::query()->firstOrCreate(['venta_setting_id' => $this->storeId, 'product_id' => $productId])
            ->forceFill(['last_push_error' => Str::limit($text, 480), 'last_push_failed_at' => now()])
            ->save();
    }

    /**
     * Every error slot errors() reads, cleared for these products on this
     * store: the listing's own, and the store's group rows. A row that was
     * failed reads as pushed when the product is linked here, else pending;
     * an unlinked row is left as it is.
     *
     * @param  list<int>  $productIds
     */
    public function clearErrors(array $productIds): void
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($v) => $v > 0)));
        if ($ids === []) {
            return;
        }
        VentaListing::query()->where('venta_setting_id', $this->storeId)->whereIn('product_id', $ids)
            ->where(fn ($q) => $q->whereNotNull('last_push_error')->orWhereNotNull('last_push_failed_at'))
            ->update(['last_push_error' => null, 'last_push_failed_at' => null]);

        $groupIds = VentaProductGroup::query()->where('venta_setting_id', $this->storeId)->pluck('id')->all();
        if ($groupIds === []) {
            return;
        }
        $linked = VentaProductLink::query()->where('venta_setting_id', $this->storeId)->whereIn('product_id', $ids)
            ->whereNotNull('venta_product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $rows = fn () => VentaProductGroupProduct::query()->whereIn('venta_product_group_id', $groupIds);

        $rows()->whereIn('product_id', $linked ?: [0])->whereIn('sync_status', ['error', 'failed'])
            ->update(['sync_status' => 'pushed', 'push_error' => null]);
        $rows()->whereIn('product_id', array_values(array_diff($ids, $linked)) ?: [0])->whereIn('sync_status', ['error', 'failed'])
            ->update(['sync_status' => 'pending', 'push_error' => null]);
        $rows()->whereIn('product_id', $ids)->whereNotNull('push_error')
            ->where(fn ($q) => $q->whereNull('sync_status')->orWhereNotIn('sync_status', ['unlinked']))
            ->update(['push_error' => null]);
    }

    /**
     * The products on this store whose error line is showing: the one
     * definition the error filter on the Listings page and the group page
     * reads, so the filter and the red lines cannot disagree.
     *
     * @return list<int>
     */
    public function erroredProductIds(): array
    {
        $groupIds = VentaProductGroup::query()->where('venta_setting_id', $this->storeId)->pluck('id')->all();
        $candidates = array_merge(
            VentaListing::query()->where('venta_setting_id', $this->storeId)
                ->whereNotNull('last_push_error')->where('last_push_error', '!=', '')
                ->pluck('product_id')->all(),
            $groupIds === [] ? [] : VentaProductGroupProduct::query()->whereIn('venta_product_group_id', $groupIds)
                ->whereIn('sync_status', ['error', 'failed'])->pluck('product_id')->all(),
            ListingVariations::readProducts('venta', $this->storeId),
        );
        $candidates = array_values(array_unique(array_filter(array_map('intval', $candidates), fn ($v) => $v > 0)));
        sort($candidates);

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

    /** The newest group push attempt per product on this store's groups. */
    private function latestAttempts(array $productIds): \Illuminate\Support\Collection
    {
        $groupIds = VentaProductGroup::query()->where('venta_setting_id', $this->storeId)->pluck('id')->all();

        return VentaProductGroupProduct::query()
            ->whereIn('venta_product_group_id', $groupIds ?: [0])
            ->whereIn('product_id', $productIds)
            ->orderByDesc('last_pushed_at')->get()
            ->groupBy(fn ($p) => (int) $p->product_id)->map(fn ($rows) => $rows->first());
    }
}
