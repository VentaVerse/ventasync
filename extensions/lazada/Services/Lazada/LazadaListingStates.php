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

        // Readiness for every row, on Lazada or not (the owner, 2026-09-06, the
        // Ready column): a listed row answers the update's checklist, which
        // on Lazada is the create's; products with no row at all answer
        // from the catalogue.
        $rowReadiness = $this->readiness->forListings(collect(array_values($chosen)));
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

    /**
     * The Error column (the owner, 2026-09-07): the message a Lazada listing is
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
        $rowsByProduct = LazadaProduct::query()->whereIn('product_id', $productIds)->orderBy('id')->get()->groupBy('product_id');

        $out = [];
        foreach ($productIds as $pid) {
            $rows = $rowsByProduct->get($pid) ?? collect();
            $listing = $rows->first(fn ($l) => ! empty($l->lazada_item_id) && ! $l->unlinked_at) ?? $rows->last();

            $found = [];
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $found[] = [$listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : Carbon::createFromTimestamp(0), trim((string) $listing->last_push_error)];
            }
            // A failure with no saved message still says it failed.
            $failedSync = $rows->filter(fn ($r) => $r->last_sync_ok !== null && ! $r->last_sync_ok)
                ->sortByDesc('last_synced_at')->first();
            if ($failedSync) {
                $found[] = [$failedSync->last_synced_at ? Carbon::parse($failedSync->last_synced_at) : Carbon::createFromTimestamp(0),
                    trim((string) $failedSync->last_sync_error_message) ?: \App\Integrations\Push\PushLedger::NO_REASON];
            }
            usort($found, fn ($a, $b) => $b[0] <=> $a[0]);
            $out[$pid] = $found === [] ? null : $found[0][1];
        }

        return $out;
    }
}
