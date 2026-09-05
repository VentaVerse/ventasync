<?php

namespace Extensions\lazada\Services\Lazada;

use App\Integrations\Listings\ListingState;
use Extensions\lazada\Models\LazadaProduct;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Lazada engine behind ListingState (Phase C, #184): one computation,
 * batch-shaped, that every Lazada surface renders from. It reads only the
 * ERP's own records - the lazada_products row (which carries the mirror
 * AND the settings, one row per listing), the catalogue row, the readiness
 * service - and never calls Lazada: freshness is the mirror's job (the
 * 30-minute automation, the Refresh button, and write-through on pushes).
 *
 * Lazada is structurally simpler than Shopee here: one row per listing,
 * status at item level, so there is no per-variation severity fold. The
 * raw vocabulary is Lazada's own lowercased words plus the ERP's honest
 * "missing" (uploaded once, no longer returned by the shop walk):
 *
 *   active → live · inactive → inactive · pending → reviewing
 *   rejected / sold-out / deleted / missing → attention
 *   null (never checked) → unknown
 *
 * sold-out is attention, not live: every offer at zero stock means buyers
 * cannot see the listing, and the fix (push stock) is the seller's move.
 *
 * Drift (#100): the catalogue moved after the last push from here, judged
 * on product.date_modified vs last_pushed_at for live and inactive rows.
 * A listing never pushed from the ERP has nothing to compare - inSync
 * stays null rather than pretending.
 */
class LazadaListingStates
{
    public function __construct(private readonly LazadaListingReadiness $readiness)
    {
    }

    private const ATTENTION_RAW = ['rejected', 'sold-out', 'deleted', 'missing'];

    /**
     * The shop's listing troubles in two numbers, for the dashboard chip.
     * Aggregate SQL, not the per-product path: the dashboard must not walk
     * two thousand rows.
     *
     * @return array{attention: int, drift: int}
     */
    public function summary(): array
    {
        $pfx = (string) config('catalog.prefix');

        $linked = LazadaProduct::query()
            ->whereNotNull('lazada_item_id')->where('lazada_item_id', '!=', '')
            ->whereNull('unlinked_at');

        $attention = (clone $linked)
            ->whereIn('live_status', self::ATTENTION_RAW)
            ->distinct()->count('product_id');

        $drift = (clone $linked)
            ->whereIn('live_status', ['active', 'inactive'])
            ->join($pfx . 'product as p', 'p.product_id', '=', 'lazada_products.product_id')
            ->whereNotNull('lazada_products.last_pushed_at')
            ->whereColumn('p.date_modified', '>', 'lazada_products.last_pushed_at')
            ->distinct()->count('lazada_products.product_id');

        return ['attention' => $attention, 'drift' => $drift];
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

        $rows = LazadaProduct::query()
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->get()
            ->groupBy('product_id');

        $modified = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->pluck('date_modified', 'product_id');

        // A product's row: prefer the one actually linked to Lazada; among
        // the rest, the newest. (More than one row per product is rare but
        // nothing forbids it.)
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

        // Readiness: rows that exist but are not on Lazada answer from their
        // own settings; products with no row at all answer from the catalogue.
        $rowReadiness = $this->readiness->forListings($notListedRows);
        $rowless = array_values(array_filter($productIds, fn ($id) => !isset($chosen[$id])));
        $rowlessReadiness = $this->readiness->forProducts($rowless);

        $out = [];
        foreach ($productIds as $productId) {
            $listing = $chosen[$productId] ?? null;
            $pushedAt = $listing?->last_pushed_at ? Carbon::parse($listing->last_pushed_at) : null;

            // One latest error per product (the owner, 2026-09-05): the last
            // refusal, from whichever surface pushed, told on every surface.
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
                    reasons: $failure,
                    ready: (bool) $r['ready'],
                    missing: $r['gaps'] ?? [],
                    pushedAt: $pushedAt,
                );
                continue;
            }

            $raw = $listing->live_status !== null ? strtolower((string) $listing->live_status) : null;
            $checkedAt = $listing->live_checked_at ? Carbon::parse($listing->live_checked_at) : null;

            // Drift is a fact about live and inactive listings; a listing
            // Lazada no longer shows has bigger problems than staleness.
            $inSync = null;
            $reasons = $failure;
            if (in_array($raw, ['active', 'inactive'], true) && $pushedAt !== null) {
                $movedAt = ($modified[$productId] ?? null) ? Carbon::parse($modified[$productId]) : null;
                $inSync = !($movedAt && $movedAt->gt($pushedAt));
                if ($inSync === false) {
                    $reasons[] = 'The catalogue changed after the last push. Push an update when ready.';
                }
            }

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

            $out[$productId] = new ListingState(
                state: $state,
                inSync: $inSync,
                reasons: $reasons,
                checkedAt: $checkedAt,
                pushedAt: $pushedAt,
                channelStatusRaw: $raw,
                attentionLabel: $attentionLabel,
            );
        }

        return $out;
    }

    /**
     * The Last sync column, one answer for the Listings page and every
     * group page (the owner, 2026-09-05): a refusal wins and is told in full,
     * whichever surface pushed or synced; otherwise the newest successful
     * sync and what it was; otherwise never.
     *
     * @param list<int> $productIds
     * @return array<int, array{tone: 'failed'|'ok'|'none', headline: string, detail: ?string, at: ?Carbon}>
     */
    public function lastSync(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $modified = DB::table((string) config('catalog.prefix') . 'product')
            ->whereIn('product_id', $productIds)->pluck('date_modified', 'product_id');
        $word = fn (?string $action): string => match (true) {
            $action === null || trim($action) === '' => 'sync',
            (bool) preg_match('/qty|stock/i', $action) => 'stock',
            (bool) preg_match('/price/i', $action) => 'price',
            (bool) preg_match('/upload|push|create|update/i', $action) => 'listing',
            (bool) preg_match('/_id$/i', $action) => 'link check',
            (bool) preg_match('/delete/i', $action) => 'delete',
            default => str_replace('_', ' ', strtolower(trim($action))),
        };

        $out = [];
        foreach ($productIds as $pid) {
            $out[$pid] = $this->lastSyncFor($pid, $word, ($modified[$pid] ?? null) ? Carbon::parse($modified[$pid]) : null);
        }

        return $out;
    }

    /** @param \Closure(?string): string $word */
    private function lastSyncFor(int $pid, \Closure $word, ?Carbon $movedAt): array
    {
        $rows = LazadaProduct::query()->where('product_id', $pid)->orderBy('id')->get();
        $listing = $rows->first(fn ($l) => !empty($l->lazada_item_id) && !$l->unlinked_at) ?? $rows->last();

        if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
            $at = $listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : null;
            return ['tone' => 'failed', 'headline' => 'Last push' . ($at ? ' ' . $at->diffForHumans() : '') . ' failed:', 'detail' => trim((string) $listing->last_push_error), 'at' => $at];
        }
        $failedSync = $rows->filter(fn ($r) => $r->last_sync_ok !== null && !$r->last_sync_ok && trim((string) ($r->last_sync_error_message ?? '')) !== '')->sortByDesc('last_synced_at')->first();
        if ($failedSync) {
            $at = $failedSync->last_synced_at ? Carbon::parse($failedSync->last_synced_at) : null;
            return ['tone' => 'failed', 'headline' => ucfirst($word($failedSync->last_sync_action)) . ' sync' . ($at ? ' ' . $at->diffForHumans() : '') . ' failed:', 'detail' => trim((string) $failedSync->last_sync_error_message), 'at' => $at];
        }
        $candidates = [];
        foreach ($rows as $r) {
            if ($r->last_synced_at && ($r->last_sync_ok === null || (bool) $r->last_sync_ok)) {
                $candidates[] = [Carbon::parse($r->last_synced_at), $word($r->last_sync_action)];
            }
            if ($r->last_pushed_at) {
                $candidates[] = [Carbon::parse($r->last_pushed_at), 'listing'];
            }
        }
        usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);
        if ($candidates === []) {
            return ['tone' => 'none', 'headline' => 'Never synced', 'detail' => null, 'at' => null];
        }
        [$at, $what] = $candidates[0];
        $stale = $movedAt && $movedAt->gt($at);
        return ['tone' => 'ok', 'headline' => 'Synced ' . $at->diffForHumans() . ' (' . $what . ')' . ($stale ? ', catalog changed since' : ''), 'detail' => null, 'at' => $at];
    }
}
