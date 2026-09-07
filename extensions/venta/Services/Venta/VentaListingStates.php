<?php

namespace Extensions\venta\Services\Venta;

use App\Integrations\Listings\ListingState;
use Extensions\venta\Models\VentaListing;
use Extensions\venta\Models\VentaProductGroup;
use Extensions\venta\Models\VentaProductGroupProduct;
use Extensions\venta\Models\VentaProductLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

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
            $out[$pid] = $found === [] ? null : $found[0][1];
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
