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

class VentaListingStates
{
    public function __construct(private readonly int $storeId) {}

    public static function for(int $storeId): self
    {
        return new self($storeId);
    }

    public function summary(): array
    {
        return [
            'attention' => $this->bucket('attention')->distinct()->count('venta_listings.product_id'),
            'drift' => count($this->catalogChanges()),
        ];
    }

    private function catalogChanges(): array
    {
        return \App\Integrations\Listings\CatalogCopy::pending(
            $this->bucket('drift')->get(), 'venta_listings', VentaListing::copyColumns()
        );
    }

    public function productIdsIn(string $bucket): array
    {
        if ($bucket === 'drift') {
            return array_map('intval', array_keys($this->catalogChanges()));
        }

        return $this->bucket($bucket)->distinct()
            ->pluck('venta_listings.product_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function bucket(string $bucket): \Illuminate\Database\Eloquent\Builder
    {
        $pfx = (string) config('catalog.prefix');

        $mine = VentaListing::query()->where('venta_listings.venta_setting_id', $this->storeId);

        if ($bucket === 'drift') {
            return $mine
                ->select('venta_listings.*')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'venta_listings.product_id')
                ->where(fn ($q) => $q->whereNull('venta_listings.catalog_seen_at')
                    ->orWhereColumn('p.date_modified', '>', 'venta_listings.catalog_seen_at'));
        }

        return $mine->where('live_status', VentaListing::STATUS_MISSING);
    }

    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $links =VentaProductLink::query()->where('venta_setting_id', $this->storeId)->whereIn('product_id', $productIds)->whereNotNull('venta_product_id')->get()->keyBy(fn ($l) => (int) $l->product_id);
        $listings = VentaListing::query()->where('venta_setting_id', $this->storeId)->whereIn('product_id', $productIds)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $catalogChanges = \App\Integrations\Listings\CatalogCopy::pending($listings->values(), 'venta_listings', VentaListing::copyColumns());
        $attempts = $this->latestAttempts($productIds);
        $readiness = VentaListingReadiness::forProducts($productIds);

        $out = [];
        foreach ($productIds as $pid) {
            $link = $links->get($pid);
            $listing = $listings->get($pid);
            $inSync = $listing ? ! isset($catalogChanges[$pid]) : null;
            $attempt = $attempts->get($pid);
            $pushedAt = $listing?->last_pushed_at ? Carbon::parse($listing->last_pushed_at) : ($attempt?->last_pushed_at ? Carbon::parse($attempt->last_pushed_at) : null);
            $failure = [];
            if ($attempt && in_array((string) $attempt->sync_status, ['error', 'failed'], true) && trim((string) ($attempt->push_error ?? '')) !== '') {
                $failedAt = $attempt->last_pushed_at ? Carbon::parse($attempt->last_pushed_at) : null;
                $failure[] = 'Last push' . ($failedAt ? ' ' . $failedAt->diffForHumans() : '') . ' failed: ' . trim((string) $attempt->push_error);
            }
            $r = $readiness[$pid] ?? ['ready' => false, 'gaps' => []];
            if (! $link) {
                $out[$pid] = new ListingState(state: ListingState::NOT_LISTED, inSync: $inSync, reasons: $failure, ready: (bool) $r['ready'], missing: $r['gaps'], pushedAt: $pushedAt);
                continue;
            }
            $raw = $listing?->live_status !== null ? strtolower((string) $listing->live_status) : null;
            $checkedAt = $listing?->live_checked_at ? Carbon::parse($listing->live_checked_at) : null;
            $reasons = $failure;
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

    public function errors(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $listings = VentaListing::query()->where('venta_setting_id', $this->storeId)->whereIn('product_id', $productIds)->get()->keyBy(fn ($l) => (int) $l->product_id);
        $attempts = $this->latestAttempts($productIds);
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
            if ($a && in_array((string) $a->sync_status, ['error', 'failed'], true)) {
                $found[] = [$a->last_pushed_at ? Carbon::parse($a->last_pushed_at) : Carbon::createFromTimestamp(0),
                    trim((string) ($a->push_error ?? '')) ?: \App\Integrations\Push\PushLedger::NO_REASON];
            }
            usort($found, fn ($x, $y) => $y[0] <=> $x[0]);
            $out[$pid] = ListingVariations::withMissing($found === [] ? null : $found[0][1], isset($missing[$pid]) ? ListingVariations::missingMessage($storeName, $missing[$pid]) : null);
        }

        return $out;
    }

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
