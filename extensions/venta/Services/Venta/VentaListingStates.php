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

    /** The shared Last sync cell: the latest failure, else the newest success, else never. */
    public function lastSync(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $modified = DB::table((string) config('catalog.prefix') . 'product')->whereIn('product_id', $productIds)->pluck('date_modified', 'product_id');
        $listings = VentaListing::query()->where('venta_setting_id', $this->storeId)->whereIn('product_id', $productIds)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $attempts = $this->latestAttempts($productIds);
        $out = [];
        foreach ($productIds as $pid) {
            $l = $listings->get($pid);
            $a = $attempts->get($pid);
            if ($a && in_array((string) $a->sync_status, ['error', 'failed'], true) && trim((string) ($a->push_error ?? '')) !== '') {
                $at = $a->last_pushed_at ? Carbon::parse($a->last_pushed_at) : null;
                $out[$pid] = ['tone' => 'failed', 'headline' => 'Last push' . ($at ? ' ' . $at->diffForHumans() : '') . ' failed:', 'detail' => trim((string) $a->push_error), 'at' => $at];
                continue;
            }
            $candidates = [];
            if ($l?->last_pushed_at) {
                $candidates[] = [Carbon::parse($l->last_pushed_at), 'listing'];
            }
            if ($a?->last_pushed_at) {
                $candidates[] = [Carbon::parse($a->last_pushed_at), 'group push'];
            }
            if ($l?->live_checked_at) {
                $candidates[] = [Carbon::parse($l->live_checked_at), 'status check'];
            }
            usort($candidates, fn ($x, $y) => $y[0] <=> $x[0]);
            if ($candidates === []) {
                $out[$pid] = ['tone' => 'none', 'headline' => 'Never synced', 'detail' => null, 'at' => null];
                continue;
            }
            [$at, $what] = $candidates[0];
            $movedAt = ($modified[$pid] ?? null) ? Carbon::parse($modified[$pid]) : null;
            $stale = $movedAt && $movedAt->gt($at);
            $out[$pid] = ['tone' => 'ok', 'headline' => 'Synced ' . $at->diffForHumans(), 'what' => $what, 'stale' => $stale, 'detail' => null, 'at' => $at];
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
