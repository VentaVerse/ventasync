<?php

namespace Extensions\Shopee\Services\Shopee;

use App\Channels\Listings\ListingState;
use App\Channels\Listings\ListingVariations;
use App\Channels\Push\PushLedger;
use Extensions\Shopee\Models\ShopeeListing;
use Extensions\Shopee\Models\ShopeeProductGroup;
use Extensions\Shopee\Models\ShopeeListingLink;
use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ShopeeListingStates
{
    private ?int $storeId = null;

    public function __construct(private readonly ShopeeListingReadiness $readiness)
    {
    }

    public function forStore(ShopeeStore|int|null $store): static
    {
        $id = $store instanceof ShopeeStore ? (int) $store->id : (int) $store;
        $copy = clone $this;
        $copy->storeId = $id > 0 ? $id : null;

        return $copy;
    }

    private function storeId(): int
    {
        return $this->storeId ?? (int) (ShopeeStore::defaultStore()?->id ?? 0);
    }

    private function mine(string $model): \Illuminate\Database\Eloquent\Builder
    {
        $query = $model::query();
        $storeId = $this->storeId();

        return $storeId > 0
            ? $query->withoutGlobalScope('shopeeStore')->where($query->qualifyColumn('store_id'), $storeId)
            : $query;
    }

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
            ['store_id' => $storeId]
        );
        $listing->forceFill([
            'last_push_error' => Str::limit($reason, 477),
            'last_push_failed_at' => now(),
        ])->save();
    }

    public function clearErrors(array $productIds): void
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($v) => $v > 0)));
        $storeId = $this->storeId();
        if ($productIds === [] || $storeId <= 0) {
            return;
        }

        $groupIds = ShopeeProductGroup::query()->withoutGlobalScope('shopeeStore')
            ->where('store_id', $storeId)->pluck('id')->map(fn ($v) => (int) $v)->all();

        foreach (array_chunk($productIds, 500) as $chunk) {
            $this->mine(ShopeeListing::class)->whereIn('product_id', $chunk)
                ->where(fn ($q) => $q->whereNotNull('last_push_error')->orWhereNotNull('last_push_failed_at'))
                ->update(['last_push_error' => null, 'last_push_failed_at' => null]);

            $this->mine(ShopeeListingLink::class)->whereIn('product_id', $chunk)
                ->where('last_sync_ok', false)
                ->update(['last_sync_ok' => true, 'last_sync_error_code' => null, 'last_sync_error_message' => null]);

            if ($groupIds === []) {
                continue;
            }

            $linked = $this->mine(ShopeeListingLink::class)->whereIn('product_id', $chunk)
                ->whereNotNull('shopee_item_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $pivot = fn () => DB::table('ch_shopee_product_group_products')
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

    public function erroredProductIds(): array
    {
        $storeId = $this->storeId();
        if ($storeId <= 0) {
            return [];
        }

        $candidates = $this->mine(ShopeeListing::class)
            ->whereNotNull('last_push_error')->where('last_push_error', '!=', '')
            ->pluck('product_id')
            ->merge($this->mine(ShopeeListingLink::class)->where('last_sync_ok', false)->pluck('product_id'))
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

    public function summary(): array
    {
        return [
            'attention' => $this->bucket('attention')->distinct()->count('ch_shopee_listing_links.product_id'),
            'drift' => count($this->catalogChanges()),
        ];
    }

    private function catalogChanges(): array
    {
        return \App\Channels\Listings\CatalogCopy::pending(
            $this->bucket('drift')->get(), 'ch_shopee_listings', ShopeeListing::copyColumns()
        );
    }

    public function productIdsIn(string $bucket): array
    {
        if ($bucket === 'drift') {
            return array_map('intval', array_keys($this->catalogChanges()));
        }

        return $this->bucket($bucket)->distinct()
            ->pluck('ch_shopee_listing_links.product_id')
            ->map(fn ($v) => (int) $v)->unique()->values()->all();
    }

    private function bucket(string $bucket): \Illuminate\Database\Eloquent\Builder
    {

        if ($bucket === 'drift') {
            return ShopeeListing::query()
                ->select('ch_shopee_listings.*')
                ->join('products as p', 'p.product_id', '=', 'ch_shopee_listings.product_id')
                ->where(fn ($q) => $q->whereNull('ch_shopee_listings.catalog_seen_at')
                    ->orWhereColumn('p.date_modified', '>', 'ch_shopee_listings.catalog_seen_at'));
        }

        return ShopeeListingLink::query()
            ->whereIn('live_status', ['BANNED', 'SELLER_DELETE', 'SHOPEE_DELETE', 'MISSING']);
    }

    private const SEVERITY = ['BANNED', 'SHOPEE_DELETE', 'SELLER_DELETE', 'MISSING', 'REVIEWING', 'UNLIST', 'NORMAL'];

    public function errors(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $listings = $this->mine(ShopeeListing::class)->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $links = $this->mine(ShopeeListingLink::class)->whereIn('product_id', $productIds)->get()->groupBy('product_id');
        $missing = ListingVariations::missing('shopee', $this->storeId(), $productIds);

        $out = [];
        foreach ($productIds as $pid) {
            $listing = $listings->get($pid);
            $rows = $links->get($pid) ?? collect();

            $found = [];
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $found[] = [$listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : Carbon::createFromTimestamp(0), trim((string) $listing->last_push_error)];
            }
            $failedSync = $rows->filter(fn ($r) => $r->last_sync_ok !== null && ! $r->last_sync_ok)
                ->sortByDesc('last_synced_at')->first();
            if ($failedSync) {
                $found[] = [$failedSync->last_synced_at ? Carbon::parse($failedSync->last_synced_at) : Carbon::createFromTimestamp(0),
                    trim((string) $failedSync->last_sync_error_message) ?: \App\Channels\Push\PushLedger::NO_REASON];
            }
            usort($found, fn ($a, $b) => $b[0] <=> $a[0]);
            $out[$pid] = ListingVariations::withMissing(
                $found === [] ? null : $found[0][1],
                isset($missing[$pid]) && $rows->isNotEmpty() ? ListingVariations::missingMessage('Shopee', $missing[$pid]) : null
            );
        }

        return $out;
    }

    public static function rememberHeld(int $storeId, int $productId, iterable $skus): void
    {
        if ($storeId <= 0 || $productId <= 0) {
            return;
        }
        $variations = [];
        foreach (PushLedger::catalogVariationSkus([$productId])[$productId] ?? [] as $sku) {
            $variations[strtolower(trim((string) $sku))] = true;
        }
        if ($variations === []) {
            return;
        }

        $parent = DB::table('products')->where('product_id', $productId)->first(['sku']);
        $own = [];
        foreach ([$parent->sku ?? ''] as $s) {
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

    public function forProducts(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }

        $links = ShopeeListingLink::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');

        $listings = ShopeeListing::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $catalogChanges = \App\Channels\Listings\CatalogCopy::pending($listings->values(), 'ch_shopee_listings', ShopeeListing::copyColumns());

        $readiness = $this->readiness->forProducts($productIds);

        $out = [];
        foreach ($productIds as $productId) {
            $listing = $listings->get($productId);
            $pushedAt = $listing?->last_pushed_at ? Carbon::parse($listing->last_pushed_at) : null;

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
