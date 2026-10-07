<?php

namespace Extensions\lazada\Services\Lazada;

use App\Integrations\Listings\ListingState;
use App\Integrations\Listings\ListingVariations;
use App\Integrations\Push\PushLedger;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductGroupProduct;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class LazadaListingStates
{
    public function __construct(private readonly LazadaListingReadiness $readiness)
    {
    }

    private const ATTENTION_RAW = ['rejected', 'sold-out', 'deleted', 'missing'];

    private ?int $storeId = null;

    public function forStore(object|int $store): self
    {
        $copy = clone $this;
        $id = is_int($store) ? $store : (int) ($store->id ?? 0);
        $copy->storeId = $id > 0 ? $id : null;

        return $copy;
    }

    public static function on(object|int $store): self
    {
        return app(self::class)->forStore($store);
    }

    private function storeId(): int
    {
        return $this->storeId ?? (int) (LazadaSetting::defaultStore()?->id ?? 0);
    }

    private function storeRows(): \Illuminate\Database\Eloquent\Builder
    {
        return LazadaProduct::query()->withoutGlobalScope('lazadaStore')
            ->where('lazada_products.lazada_setting_id', $this->storeId());
    }

    private static function currentRow(Collection $rows): ?LazadaProduct
    {
        return $rows->first(fn ($l) => ! empty($l->lazada_item_id) && ! $l->unlinked_at) ?? $rows->last();
    }

    public function recordOutcome(int $productId, ?string $failure): void
    {
        if ($productId <= 0) {
            return;
        }
        if ($failure === null) {
            $this->clearErrors([$productId]);

            return;
        }
        $storeId = $this->storeId();
        if ($storeId <= 0) {
            return;
        }

        $failure = trim($failure) !== '' ? trim($failure) : PushLedger::NO_REASON;
        $values = ['last_push_error' => Str::limit($failure, 477), 'last_push_failed_at' => now()];

        $row = self::currentRow($this->storeRows()->where('product_id', $productId)->orderBy('id')->get());
        if ($row) {
            $row->forceFill($values)->save();

            return;
        }
        (new LazadaProduct())->forceFill(['product_id' => $productId, 'lazada_setting_id' => $storeId] + $values)->save();
    }

    public function clearErrors(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($v) => $v > 0)));
        $storeId = $this->storeId();
        if ($productIds === [] || $storeId <= 0) {
            return;
        }
        $groupIds = LazadaProductGroupProduct::groupIdsOn($storeId);

        foreach (array_chunk($productIds, 500) as $chunk) {
            $this->storeRows()->whereIn('product_id', $chunk)
                ->where(fn ($q) => $q->whereNotNull('last_push_error')->orWhereNotNull('last_push_failed_at'))
                ->update(['last_push_error' => null, 'last_push_failed_at' => null]);
            $this->storeRows()->whereIn('product_id', $chunk)->where('last_sync_ok', false)
                ->update(['last_sync_ok' => true, 'last_sync_error_code' => null, 'last_sync_error_message' => null]);

            if ($groupIds === []) {
                continue;
            }
            $linked = $this->storeRows()->whereIn('product_id', $chunk)
                ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
                ->whereNull('unlinked_at')->whereNull('lazada_deleted_at')
                ->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $pivots = fn () => DB::table('lazada_product_group_products')
                ->whereIn('lazada_product_group_id', $groupIds)->whereIn('product_id', $chunk);

            $pivots()->whereNotNull('push_error')
                ->where(fn ($q) => $q->whereNull('sync_status')->orWhere('sync_status', '!=', 'unlinked'))
                ->update(['push_error' => null]);
            if ($linked !== []) {
                $pivots()->whereIn('sync_status', ['error', 'failed'])->whereIn('product_id', $linked)
                    ->update(['sync_status' => 'pushed']);
            }
            $pivots()->whereIn('sync_status', ['error', 'failed'])
                ->when($linked !== [], fn ($q) => $q->whereNotIn('product_id', $linked))
                ->update(['sync_status' => 'pending']);
        }
    }

    public function erroredProductIds(): array
    {
        $storeId = $this->storeId();
        if ($storeId <= 0) {
            return [];
        }

        $candidates = $this->storeRows()->whereNotNull('product_id')
            ->where(function ($q) {
                $q->where(fn ($w) => $w->whereNotNull('last_push_error')->where('last_push_error', '!=', ''))
                    ->orWhere('last_sync_ok', false);
            })
            ->distinct()->pluck('product_id')->map(fn ($v) => (int) $v)->all();
        $candidates = array_values(array_unique(array_merge(
            $candidates,
            ListingVariations::readProducts('lazada', $storeId)
        )));

        $out = [];
        foreach (array_chunk($candidates, 500) as $chunk) {
            foreach ($this->errors($chunk) as $pid => $line) {
                if ($line !== null) {
                    $out[] = (int) $pid;
                }
            }
        }
        sort($out);

        return $out;
    }

    public function summary(): array
    {
        return [
            'attention' => $this->bucket('attention')->distinct()->count('lazada_products.product_id'),
            'drift' => count($this->catalogChanges()),
        ];
    }

    private function catalogChanges(): array
    {
        return \App\Integrations\Listings\CatalogCopy::pending(
            $this->bucket('drift')->get(), 'lazada_products', LazadaProduct::copyColumns()
        );
    }

    public function productIdsIn(string $bucket): array
    {
        if ($bucket === 'drift') {
            return array_map('intval', array_keys($this->catalogChanges()));
        }

        return $this->bucket($bucket)->distinct()
            ->pluck('lazada_products.product_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function bucket(string $bucket): \Illuminate\Database\Eloquent\Builder
    {
        $pfx = (string) config('catalog.prefix');

        $linked = LazadaProduct::query()
            ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->whereNull('unlinked_at');

        if ($bucket === 'drift') {
            return LazadaProduct::query()
                ->select('lazada_products.*')
                ->join($pfx . 'product as p', 'p.product_id', '=', 'lazada_products.product_id')
                ->where(fn ($q) => $q->whereNull('lazada_products.catalog_seen_at')
                    ->orWhereColumn('p.date_modified', '>', 'lazada_products.catalog_seen_at'));
        }

        return $linked->whereIn('live_status', self::ATTENTION_RAW);
    }

    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $pfx = (string) config('catalog.prefix');

        $rows = LazadaProduct::query()
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $modified = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->pluck('date_modified', 'product_id');

        $chosen = [];
        $notListedRows = collect();
        foreach ($productIds as $productId) {
            $candidates = $rows->get($productId);
            if (!$candidates) {
                continue;
            }
            $listing = $candidates->first(fn ($l) => !empty($l->lazada_item_id) && !$l->unlinked_at)
                ?? $candidates->last();
            $chosen[$productId] = $listing;
            if (empty($listing->lazada_item_id) || $listing->unlinked_at) {
                $notListedRows->push($listing);
            }
        }

        $rowReadiness = $this->readiness->forListings(collect(array_values($chosen)));
        $catalogChanges = \App\Integrations\Listings\CatalogCopy::pending(array_values($chosen), 'lazada_products', LazadaProduct::copyColumns());
        $rowless = array_values(array_filter($productIds, fn ($id) => !isset($chosen[$id])));
        $rowlessReadiness = $this->readiness->forProducts($rowless);

        $out = [];
        foreach ($productIds as $productId) {
            $listing = $chosen[$productId] ?? null;
            $pushedAt = $listing?->last_pushed_at ? Carbon::parse($listing->last_pushed_at) : null;

            $failure = [];
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $failedAt = $listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : null;
                $failure[] = 'Last push' . ($failedAt ? ' ' . $failedAt->diffForHumans() : '') . ' failed: ' . trim((string) $listing->last_push_error);
            }

            if (!$listing || empty($listing->lazada_item_id) || $listing->unlinked_at) {
                $r = $listing
                    ? ($rowReadiness[(int) $listing->id] ?? ['ready' => false, 'gaps' => []])
                    : ($rowlessReadiness[$productId] ?? ['ready' => false, 'gaps' => []]);
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

            $raw = $listing->live_status !== null ? strtolower((string) $listing->live_status) : null;
            $checkedAt = $listing->live_checked_at ? Carbon::parse($listing->live_checked_at) : null;

            $inSync = !isset($catalogChanges[$productId]);
            $reasons = $failure;

            [$state, $attentionLabel, $extraReason] = match ($raw) {
                'active' => [ListingState::LIVE, null, null],
                'inactive' => [ListingState::INACTIVE, null, null],
                'pending' => [ListingState::REVIEWING, null, 'Lazada is reviewing it; nothing to do until it answers.'],
                'rejected' => [ListingState::ATTENTION, 'Rejected', 'Lazada rejected it. Open the item in Seller Center to see why.'],
                'sold-out' => [ListingState::ATTENTION, 'Sold out', 'Every offer is out of stock, so buyers cannot see it. Push stock when replenished.'],
                'deleted' => [ListingState::ATTENTION, 'Deleted', 'It was deleted on Lazada. Push again to relist it.'],
                'missing' => [ListingState::ATTENTION, 'Not found on Lazada', 'Lazada no longer returns this listing. Refresh the status, or push it again.'],
                default => [ListingState::UNKNOWN, null, null],
            };
            if ($extraReason !== null) {
                $reasons[] = $extraReason;
            }

            $r = $rowReadiness[(int) $listing->id] ?? ['ready' => false, 'gaps' => []];
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
                inactiveLabel: 'Inactive',
            );
        }

        return $out;
    }

    public function errors(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $rowsByProduct = $this->storeRows()->whereIn('product_id', $productIds)->orderBy('id')->get()->groupBy('product_id');
        $missing = ListingVariations::missing('lazada', $this->storeId(), $productIds);

        $out = [];
        foreach ($productIds as $pid) {
            $rows = $rowsByProduct->get($pid) ?? collect();
            $listing = self::currentRow($rows);

            $found = [];
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $found[] = [$listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : Carbon::createFromTimestamp(0), trim((string) $listing->last_push_error)];
            }
            $failedSync = $rows->filter(fn ($r) => $r->last_sync_ok !== null && ! $r->last_sync_ok)
                ->sortByDesc('last_synced_at')->first();
            if ($failedSync) {
                $found[] = [$failedSync->last_synced_at ? Carbon::parse($failedSync->last_synced_at) : Carbon::createFromTimestamp(0),
                    trim((string) $failedSync->last_sync_error_message) ?: \App\Integrations\Push\PushLedger::NO_REASON];
            }
            usort($found, fn ($a, $b) => $b[0] <=> $a[0]);
            $linked = $listing && !empty($listing->lazada_item_id) && !$listing->unlinked_at;
            $out[$pid] = ListingVariations::withMissing(
                $found === [] ? null : $found[0][1],
                isset($missing[$pid]) && $linked ? ListingVariations::missingMessage('Lazada', $missing[$pid]) : null
            );
        }

        return $out;
    }

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

        ListingVariations::remember('lazada', $storeId, $productId, $held);
    }
}
