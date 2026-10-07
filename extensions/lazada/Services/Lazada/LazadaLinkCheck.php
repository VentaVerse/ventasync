<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductGroup;
use Extensions\lazada\Models\LazadaProductGroupProduct;
use Illuminate\Support\Facades\DB;

class LazadaLinkCheck
{
    public function __construct(private readonly LazadaLiveListing $live)
    {
    }

    public function runNext(object $setting, array $creds): ?array
    {
        $linked = \Extensions\lazada\Models\LazadaProduct::query()->whereNotNull('lazada_item_id')->whereNull('lazada_deleted_at')
            ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $linked = \App\Integrations\Support\LinkCheckCursor::rotate('lazada:' . (int) $setting->id, $linked);

        return $linked !== [] ? $this->run($setting, $creds, $linked) : null;
    }

    public function run(object $setting, array $creds, array $productIds, ?LazadaProductGroup $group = null): array
    {
        $limit = 200;
        $skippedForSize = max(0, count($productIds) - $limit);
        $productIds = array_slice($productIds, 0, $limit);

        $pfx = (string) config('catalog.prefix');
        $skusByProduct = $this->erpSkusByProduct($pfx, $productIds);
        $allSkus = array_values(array_unique(array_merge(...array_values($skusByProduct ?: [[]]))));
        try {
            $found = $this->live->itemsBySkus($setting, $creds, $allSkus);
        } catch (\RuntimeException $e) {
            return ['tone' => 'error', 'summary' => 'Check against Lazada: ' . $e->getMessage()];
        }

        $pivotQuery = LazadaProductGroupProduct::query()->whereIn('product_id', $productIds);
        if ($group !== null) {
            $pivotQuery->where('lazada_product_group_id', $group->id);
        } else {
            $pivotQuery->whereIn('lazada_product_group_id', LazadaProductGroup::query()
                ->withoutGlobalScope('lazadaStore')->where('lazada_setting_id', (int) $setting->id)->select('id'));
        }
        $pivotsByProduct = $pivotQuery->get()->groupBy('product_id');

        $verdicts = ['confirmed' => 0, 'linked' => 0, 'lost' => 0, 'taken' => 0, 'not_on_lazada' => 0];
        $problems = [];
        $states = LazadaListingStates::on($setting);

        foreach ($productIds as $productId) {
            $pivots = $pivotsByProduct->get($productId) ?? collect();
            $listing = null;
            foreach ($pivots as $p) {
                if ($p->lazada_product_id && ($listing = LazadaProduct::find($p->lazada_product_id))) {
                    break;
                }
            }
            $listing ??= LazadaProduct::query()
                ->withoutGlobalScope('lazadaStore')->where('lazada_setting_id', (int) $setting->id)
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
                } elseif ($hitItemId !== null && ($holder = $this->holderOf($setting, $hitItemId, (int) $productId)) !== null) {
                    $line = "Lazada item {$hitItemId} holds this product's SKU but is linked to catalog product #{$holder}.";
                    $states->recordOutcome((int) $productId, $line);
                    $problems[] = "#{$productId}: " . $line;
                    $verdicts['taken']++;
                } elseif ($hitItemId !== null) {
                    $listing->forceFill($hitMirror + ['lazada_item_id' => $hitItemId, 'lazada_deleted_at' => null] + $this->checkedStamp($listing))->save();
                    $this->markPushed($pivots);
                    $verdicts['linked']++;
                } else {
                    \App\Integrations\Listings\DroppedLink::write(
                        'Lazada', 'Lazada Product', (int) $productId, (string) $listing->lazada_item_id,
                        (int) $setting->id, $skusByProduct[$productId] ?? [], count($found)
                    );
                    $listing->forceFill(['lazada_deleted_at' => now(), 'live_status' => 'missing', 'live_checked_at' => now(), 'last_synced_at' => now(), 'last_sync_action' => 'check', 'last_sync_ok' => false, 'last_sync_error_message' => 'Not found on Lazada'])->save();
                    foreach ($pivots as $p) $p->update(['sync_status' => 'error']);
                    $problems[] = "#{$productId}: not found on Lazada; the link was dropped";
                    $verdicts['lost']++;
                }
                continue;
            }

            if ($hitItemId !== null && ($holder = $this->holderOf($setting, $hitItemId, (int) $productId)) !== null) {
                $line = "Lazada item {$hitItemId} holds this product's SKU but is linked to catalog product #{$holder}.";
                if ($listing) {
                    $states->recordOutcome((int) $productId, $line);
                }
                $problems[] = "#{$productId}: " . $line;
                $verdicts['taken']++;
            } elseif ($hitItemId !== null) {
                if (!$listing) {
                    $listing = LazadaProduct::create(['product_id' => $productId]);
                }
                $listing->forceFill($hitMirror + ['lazada_item_id' => $hitItemId, 'lazada_deleted_at' => null, 'unlinked_at' => null] + $this->checkedStamp($listing))->save();
                if ($pivots->isNotEmpty()) {
                    foreach ($pivots as $p) {
                        $p->update(['lazada_product_id' => $listing->id] + (in_array($p->sync_status, ['error', 'failed'], true) ? [] : ['sync_status' => 'pushed']));
                    }
                } elseif ($group !== null && \App\Integrations\OneGroupRule::claim('lazada_product_group_products', 'lazada_product_group_id', 'lazada_product_groups', 'lazada_setting_id', $group->id, [(int) $productId])['free'] !== []) {
                    LazadaProductGroupProduct::create(['lazada_product_group_id' => $group->id, 'product_id' => $productId, 'lazada_product_id' => $listing->id, 'sync_status' => 'pushed']);
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
        $summary = 'Checked ' . count($productIds) . ' against Lazada: ' . (implode(', ', $parts) ?: 'nothing to report') . '.';
        if ($skippedForSize > 0) $summary .= " {$skippedForSize} more were not checked (200 at a time).";
        if ($problems) $summary .= ' ' . implode('; ', array_slice($problems, 0, 5)) . '.';

        return ['tone' => ($verdicts['lost'] + $verdicts['taken']) > 0 ? 'error' : 'status', 'summary' => $summary];
    }

    private function checkedStamp(LazadaProduct $listing): array
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

    private function holderOf(object $setting, string $itemId, int $productId): ?int
    {
        $holder = LazadaProduct::query()->withoutGlobalScope('lazadaStore')
            ->where('lazada_setting_id', (int) $setting->id)
            ->where('lazada_item_id', $itemId)->where('product_id', '!=', $productId)
            ->whereNull('lazada_deleted_at')->whereNull('unlinked_at')
            ->value('product_id');

        return $holder !== null ? (int) $holder : null;
    }

    private function erpSkusByProduct(string $pfx, array $productIds): array
    {
        $out = [];
        foreach (DB::table($pfx . 'product')->whereIn('product_id', $productIds)->get(['product_id', 'sku', 'model']) as $p) {
            foreach ([$p->sku, $p->model] as $v) {
                if (trim((string) $v) !== '') {
                    $out[(int) $p->product_id][] = trim((string) $v);
                }
            }
        }
        foreach (DB::table($pfx . 'product_option_value')->whereIn('product_id', $productIds)->whereNotNull('sku')->where('sku', '!=', '')->get(['product_id', 'sku']) as $r) {
            $out[(int) $r->product_id][] = trim((string) $r->sku);
        }
        foreach (DB::table('product_option_combinations')->whereIn('product_id', $productIds)->whereNotNull('sku')->where('sku', '!=', '')->get(['product_id', 'sku']) as $r) {
            $out[(int) $r->product_id][] = trim((string) $r->sku);
        }
        foreach ($out as $pid => $skus) {
            $out[$pid] = array_values(array_unique($skus));
        }

        return $out;
    }
}
