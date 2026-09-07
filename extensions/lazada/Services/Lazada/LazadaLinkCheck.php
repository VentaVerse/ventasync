<?php

namespace Extensions\lazada\Services\Lazada;

use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductGroup;
use Extensions\lazada\Models\LazadaProductGroupProduct;
use Illuminate\Support\Facades\DB;

/**
 * Ask Lazada about these products and make the ERP agree (Phase F,
 * #184). Extracted from the group page so the workbench can run the
 * same check: the SKU walk was never group-shaped - only its entry
 * point was. A group, when given, contributes adoption defaults
 * (category, markups) and gets its pivot kept honest; without one the
 * check still adopts, with the listing left for the workbench to dress.
 *
 * Reads only. It confirms, repoints or drops LINKS and refreshes the
 * status mirror; it writes nothing to Lazada.
 */
class LazadaLinkCheck
{
    public function __construct(private readonly LazadaLiveListing $live)
    {
    }

    /**
     * The next page of this store's links, checked (the owner, 2026-09-07).
     * Both the Refresh button and the scheduled refresh call this, which is
     * what stops them from drifting apart: the cron used to re-read statuses
     * and never re-run the check, so a failed check could not clear itself
     * and a row kept a six-month-old refusal until somebody pressed the
     * button. The cursor caps a run at two hundred and continues where the
     * last one stopped.
     *
     * @return ?array the check's own result, or null when nothing is linked
     */
    public function runNext(object $setting, array $creds): ?array
    {
        $linked = \Extensions\lazada\Models\LazadaProduct::query()->whereNotNull('lazada_item_id')->whereNull('lazada_deleted_at')
            ->orderBy('product_id')->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
        $linked = \App\Integrations\Support\LinkCheckCursor::rotate('lazada:' . (int) $setting->id, $linked);

        return $linked !== [] ? $this->run($setting, $creds, $linked) : null;
    }

    /** @return array{tone: 'status'|'error', summary: string} */
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

        /* The pivots the verdicts write to: the containing group's when the
         * check runs from a group page, every pivot the product has when it
         * runs from the workbench - a stale copy anywhere is a lie somewhere. */
        $pivotQuery = LazadaProductGroupProduct::query()->whereIn('product_id', $productIds);
        if ($group !== null) {
            $pivotQuery->where('lazada_product_group_id', $group->id);
        }
        $pivotsByProduct = $pivotQuery->get()->groupBy('product_id');

        $verdicts = ['confirmed' => 0, 'linked' => 0, 'lost' => 0, 'not_on_lazada' => 0];
        $problems = [];

        foreach ($productIds as $productId) {
            $pivots = $pivotsByProduct->get($productId) ?? collect();
            $listing = null;
            foreach ($pivots as $p) {
                if ($p->lazada_product_id && ($listing = LazadaProduct::find($p->lazada_product_id))) {
                    break;
                }
            }
            $listing ??= LazadaProduct::query()->where('product_id', $productId)->first();
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
            /* The walk answered with the item's status; writing it to the
             * mirror here means a check leaves every row it touched fresh
             * instead of throwing the answer away (#184 C). */
            $hitMirror = $hitItemId !== null && ($hitStatuses[$hitItemId] ?? '') !== ''
                ? ['live_status' => $hitStatuses[$hitItemId], 'live_checked_at' => now()]
                : [];

            if ($listing && $listing->lazada_item_id && !$listing->lazada_deleted_at) {
                if ($hitItemId !== null && in_array((string) $listing->lazada_item_id, $hits, true)) {
                    $listing->forceFill($hitMirror + ['last_synced_at' => now(), 'last_sync_action' => 'check', 'last_sync_ok' => true, 'last_sync_error_message' => null])->save();
                    foreach ($pivots as $p) $p->update(['sync_status' => 'pushed']);
                    $verdicts['confirmed']++;
                } elseif ($hitItemId !== null) {
                    // The SKU sits on a different item now; the record follows the shop.
                    $listing->forceFill($hitMirror + ['lazada_item_id' => $hitItemId, 'lazada_deleted_at' => null, 'last_synced_at' => now(), 'last_sync_action' => 'check', 'last_sync_ok' => true])->save();
                    foreach ($pivots as $p) $p->update(['sync_status' => 'pushed']);
                    $verdicts['linked']++;
                } else {
                    $listing->forceFill(['lazada_deleted_at' => now(), 'live_status' => 'missing', 'live_checked_at' => now(), 'last_synced_at' => now(), 'last_sync_action' => 'check', 'last_sync_ok' => false, 'last_sync_error_message' => 'Not found on Lazada'])->save();
                    foreach ($pivots as $p) $p->update(['sync_status' => 'error']);
                    $problems[] = "#{$productId}: not found on Lazada; the link was dropped";
                    $verdicts['lost']++;
                }
                continue;
            }

            if ($hitItemId !== null) {
                // Unlisted here, already on the shop: adopt it. Group
                // defaults dress the new listing when a group is present.
                if (!$listing) {
                    $listing = LazadaProduct::create([
                        'product_id' => $productId,
                        'primary_category_id' => $group?->lazada_category_id,
                        'brand_name_override' => 'No Brand',
                        'markup_fixed' => $group?->markup_fixed,
                        'markup_percent' => $group?->markup_percent,
                    ]);
                }
                $listing->forceFill($hitMirror + ['lazada_item_id' => $hitItemId, 'lazada_deleted_at' => null, 'unlinked_at' => null, 'last_synced_at' => now(), 'last_sync_action' => 'check', 'last_sync_ok' => true])->save();
                if ($pivots->isNotEmpty()) {
                    foreach ($pivots as $p) $p->update(['lazada_product_id' => $listing->id, 'sync_status' => 'pushed']);
                } elseif ($group !== null) {
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
        if ($verdicts['not_on_lazada']) $parts[] = "{$verdicts['not_on_lazada']} not on Lazada";
        $summary = 'Checked ' . count($productIds) . ' against Lazada: ' . (implode(', ', $parts) ?: 'nothing to report') . '.';
        if ($skippedForSize > 0) $summary .= " {$skippedForSize} more were not checked (200 at a time).";
        if ($problems) $summary .= ' ' . implode('; ', array_slice($problems, 0, 5)) . '.';

        return ['tone' => $verdicts['lost'] > 0 ? 'error' : 'status', 'summary' => $summary];
    }

    /** Every SKU the catalogue knows for these products: parent, model, variations. */
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
