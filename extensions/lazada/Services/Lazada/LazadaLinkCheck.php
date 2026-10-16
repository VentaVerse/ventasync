<?php

namespace Extensions\Lazada\Services\Lazada;

use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaProductGroup;
use Extensions\Lazada\Models\LazadaProductGroupProduct;
use Illuminate\Support\Facades\DB;

class LazadaLinkCheck
{
    public function __construct(private readonly LazadaLiveListing $live)
    {
    }

    public function runNext(object $store, array $creds): ?array
    {
        $linked = \Extensions\Lazada\Models\LazadaListing::query()->whereNotNull('lazada_item_id')->whereNull('lazada_deleted_at')
            ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $linked = \App\Channels\Support\LinkCheckCursor::rotate('lazada:' . (int) $store->id, $linked);

        return $linked !== [] ? $this->run($store, $creds, $linked) : null;
    }

    public function run(object $store, array $creds, array $productIds, ?LazadaProductGroup $group = null): array
    {
        $limit = 200;
        $skippedForSize = max(0, count($productIds) - $limit);
        $productIds = array_slice($productIds, 0, $limit);
        $skusByProduct = $this->catalogSkusByProduct($productIds);
        $allSkus = array_values(array_unique(array_merge(...array_values($skusByProduct ?: [[]]))));
        try {
            $found = $this->live->itemsBySkus($store, $creds, $allSkus);
        } catch (\RuntimeException $e) {
            return ['tone' => 'error', 'summary' => 'Check against Lazada: ' . $e->getMessage()];
        }

        $pivotQuery = LazadaProductGroupProduct::query()->whereIn('product_id', $productIds);
        if ($group !== null) {
            $pivotQuery->where('lazada_product_group_id', $group->id);
        } else {
            $pivotQuery->whereIn('lazada_product_group_id', LazadaProductGroup::query()
                ->withoutGlobalScope('lazadaStore')->where('store_id', (int) $store->id)->select('id'));
        }
        $pivotsByProduct = $pivotQuery->get()->groupBy('product_id');

        $verdicts = ['confirmed' => 0, 'linked' => 0, 'lost' => 0, 'taken' => 0, 'not_on_lazada' => 0, 'unsure' => 0];
        $problems = [];
        $states = LazadaListingStates::on($store);

        foreach ($productIds as $productId) {
            $pivots = $pivotsByProduct->get($productId) ?? collect();
            $listing = null;
            foreach ($pivots as $p) {
                if ($p->lazada_listing_id && ($listing = LazadaListing::find($p->lazada_listing_id))) {
                    break;
                }
            }
            $listing ??= LazadaListing::query()
                ->withoutGlobalScope('lazadaStore')->where('store_id', (int) $store->id)
                ->where('product_id', $productId)->first();
            $hits = [];
            $hitStatuses = [];
            foreach ($skusByProduct[$productId] ?? [] as $sku) {
                if (isset($found[strtolower($sku)])) {
                    $hits[] = $found[strtolower($sku)]['item_id'];
                    $hitStatuses[(string) $found[strtolower($sku)]['item_id']] =
                        strtolower(trim((string) ($found[strtolower($sku)]['status'] ?? '')));
                }
            }
            $hitItemId = $hits ? (string) $hits[0] : null;
            $hitMirror = $hitItemId !== null && ($hitStatuses[$hitItemId] ?? '') !== ''
                ? ['live_status' => $hitStatuses[$hitItemId], 'live_checked_at' => now()]
                : [];

            if ($listing && $listing->lazada_item_id && !$listing->lazada_deleted_at) {
                if ($hitItemId !== null && in_array((string) $listing->lazada_item_id, $hits, true)) {
                    $listing->forceFill($hitMirror + $this->checkedStamp($listing))->save();
                    $this->markPushed($pivots);
                    $verdicts['confirmed']++;
                } elseif ($hitItemId !== null && ($holder = $this->holderOf($store, $hitItemId, (int) $productId)) !== null) {
                    $line = "Lazada item {$hitItemId} holds this product's SKU but is linked to Master Catalog product #{$holder}.";
                    $states->recordOutcome((int) $productId, $line);
                    $problems[] = "#{$productId}: " . $line;
                    $verdicts['taken']++;
                } elseif ($hitItemId !== null) {
                    $listing->forceFill($hitMirror + ['lazada_item_id' => $hitItemId, 'lazada_deleted_at' => null] + $this->checkedStamp($listing))->save();
                    $this->markPushed($pivots);
                    $verdicts['linked']++;
                } else {
                    // The SKU search once answered "none" for live listings, so a miss alone never drops a link:
                    // Lazada must also say the listing number itself does not exist.
                    $byNumber = $this->live->itemOnLazada($store, $creds, (string) $listing->lazada_item_id);
                    \Illuminate\Support\Facades\Log::warning('Lazada link check: the SKU search did not find a linked listing', [
                        'product_id' => (int) $productId,
                        'item_id' => (string) $listing->lazada_item_id,
                        'searched_skus' => $skusByProduct[$productId] ?? [],
                        'skus_found_in_this_run' => count($found),
                        'asked_by_number' => $byNumber,
                        'searched_again' => $this->searchAgain($store, $creds, $skusByProduct[$productId] ?? []),
                    ]);
                    if ($byNumber['answer'] !== 'no') {
                        if ($byNumber['answer'] === 'yes') {
                            $listing->forceFill(($byNumber['status'] ? ['live_status' => $byNumber['status'], 'live_checked_at' => now()] : []) + $this->checkedStamp($listing))->save();
                            $verdicts['confirmed']++;
                        } else {
                            $verdicts['unsure']++;
                        }
                        continue;
                    }
                    \App\Channels\Listings\DroppedLink::write(
                        'Lazada', 'Lazada Product', (int) $productId, (string) $listing->lazada_item_id,
                        (int) $store->id, $skusByProduct[$productId] ?? [], count($found)
                    );
                    $listing->forceFill(['lazada_deleted_at' => now(), 'live_status' => 'missing', 'live_checked_at' => now(), 'last_synced_at' => now(), 'last_sync_action' => 'check', 'last_sync_ok' => false, 'last_sync_error_message' => 'Not found on Lazada'])->save();
                    foreach ($pivots as $p) $p->update(['sync_status' => 'error']);
                    $problems[] = "#{$productId}: not found on Lazada; the link was dropped";
                    $verdicts['lost']++;
                }
                continue;
            }

            if ($hitItemId !== null && ($holder = $this->holderOf($store, $hitItemId, (int) $productId)) !== null) {
                $line = "Lazada item {$hitItemId} holds this product's SKU but is linked to Master Catalog product #{$holder}.";
                if ($listing) {
                    $states->recordOutcome((int) $productId, $line);
                }
                $problems[] = "#{$productId}: " . $line;
                $verdicts['taken']++;
            } elseif ($hitItemId !== null) {
                if (!$listing) {
                    $listing = LazadaListing::create(['product_id' => $productId]);
                }
                $listing->forceFill($hitMirror + ['lazada_item_id' => $hitItemId, 'lazada_deleted_at' => null, 'unlinked_at' => null] + $this->checkedStamp($listing))->save();
                if ($pivots->isNotEmpty()) {
                    foreach ($pivots as $p) {
                        $p->update(['lazada_listing_id' => $listing->id] + (in_array($p->sync_status, ['error', 'failed'], true) ? [] : ['sync_status' => 'pushed']));
                    }
                } elseif ($group !== null && \App\Channels\OneGroupRule::claim('ch_lazada_product_group_products', 'lazada_product_group_id', 'ch_lazada_product_groups', 'store_id', $group->id, [(int) $productId])['free'] !== []) {
                    LazadaProductGroupProduct::create(['lazada_product_group_id' => $group->id, 'product_id' => $productId, 'lazada_listing_id' => $listing->id, 'sync_status' => 'pushed']);
                }
                $verdicts['linked']++;
            } else {
                $verdicts['not_on_lazada']++;
            }
        }

        $parts = [];
        if ($verdicts['confirmed']) $parts[] = "{$verdicts['confirmed']} confirmed on Lazada";
        if ($verdicts['linked']) $parts[] = "{$verdicts['linked']} linked to the item Lazada holds";
        if ($verdicts['lost']) $parts[] = "{$verdicts['lost']} no longer on Lazada";
        if ($verdicts['taken']) $parts[] = "{$verdicts['taken']} on an item another product holds";
        if ($verdicts['not_on_lazada']) $parts[] = "{$verdicts['not_on_lazada']} not on Lazada";
        if ($verdicts['unsure']) $parts[] = "{$verdicts['unsure']} left alone because Lazada did not answer for the listing";
        $summary = 'Checked ' . count($productIds) . ' against Lazada: ' . (implode(', ', $parts) ?: 'nothing to report') . '.';
        if ($skippedForSize > 0) $summary .= " {$skippedForSize} more were not checked (200 at a time).";
        if ($problems) $summary .= ' ' . implode('; ', array_slice($problems, 0, 5)) . '.';

        return ['tone' => ($verdicts['lost'] + $verdicts['taken']) > 0 ? 'error' : 'status', 'summary' => $summary];
    }

    /** The same SKU search for this product alone, kept with a miss as evidence of what Lazada answered. */
    private function searchAgain(object $store, array $creds, array $skus): array
    {
        try {
            return array_values(array_unique(array_column($this->live->itemsBySkus($store, $creds, $skus), 'item_id')));
        } catch (\RuntimeException $e) {
            return ['error' => $e->getMessage()];
        }
    }

    private function checkedStamp(LazadaListing $listing): array
    {
        return $listing->last_sync_ok !== null && ! $listing->last_sync_ok
            ? []
            : ['last_synced_at' => now(), 'last_sync_action' => 'check', 'last_sync_ok' => true];
    }

    private function markPushed($pivots): void
    {
        foreach ($pivots as $p) {
            if (! in_array($p->sync_status, ['error', 'failed'], true)) {
                $p->update(['sync_status' => 'pushed']);
            }
        }
    }

    private function holderOf(object $store, string $itemId, int $productId): ?int
    {
        $holder = LazadaListing::query()->withoutGlobalScope('lazadaStore')
            ->where('store_id', (int) $store->id)
            ->where('lazada_item_id', $itemId)->where('product_id', '!=', $productId)
            ->whereNull('lazada_deleted_at')->whereNull('unlinked_at')
            ->value('product_id');

        return $holder !== null ? (int) $holder : null;
    }

    public function catalogSkusByProduct(array $productIds): array
    {
        $out = [];
        foreach (DB::table('products')->whereIn('product_id', $productIds)->get(['product_id', 'sku']) as $p) {
            foreach ([$p->sku] as $v) {
                if (trim((string) $v) !== '') {
                    $out[(int) $p->product_id][] = trim((string) $v);
                }
            }
        }
        foreach (DB::table('product_variation_values')->whereIn('product_id', $productIds)->whereNotNull('sku')->where('sku', '!=', '')->get(['product_id', 'sku']) as $r) {
            $out[(int) $r->product_id][] = trim((string) $r->sku);
        }
        foreach (DB::table('product_variations')->whereIn('product_id', $productIds)->whereNotNull('sku')->where('sku', '!=', '')->get(['product_id', 'sku']) as $r) {
            $out[(int) $r->product_id][] = trim((string) $r->sku);
        }
        foreach ($out as $pid => $skus) {
            $out[$pid] = array_values(array_unique($skus));
        }

        return $out;
    }
}
