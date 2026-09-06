<?php

namespace Extensions\shopee\Services\Shopee;

use App\Integrations\Listings\ListingState;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The Shopee engine behind ListingState (Phase B, #184): one computation,
 * batch-shaped, that every Shopee surface renders from. It reads only the
 * ERP's own records - the link mirror, the listing record, the catalogue
 * row, the readiness service - and never calls Shopee: freshness is the
 * mirror's job (the 30-minute automation, the Refresh button, and the
 * write-through every push now performs).
 *
 * A product can hold several link rows (one per variation model, rarely
 * several items). One product still gets ONE state: the rows' statuses
 * are folded by severity, worst first, because "one of its variations is
 * banned" IS the product's situation. Severity: attention > reviewing >
 * inactive > live; unknown only when no row has ever been checked.
 *
 * Drift (#100): the catalogue moved after the last push from here. Judged
 * on product.date_modified vs listing.last_pushed_at - a timestamp, not a
 * content hash, so it can cry once for a change the push would not carry;
 * it can never stay silent about one it would. A linked product never
 * pushed from the ERP (matched or reconciled in) has nothing to compare,
 * and inSync stays null rather than pretending.
 */
class ShopeeListingStates
{
    public function __construct(private readonly ShopeeListingReadiness $readiness)
    {
    }

    /**
     * The shop's listing troubles in two numbers, for the dashboard chip:
     * how many products need a human (violation, deleted, vanished) and
     * how many are live but behind the catalogue. Aggregate SQL, not the
     * per-product path: the dashboard must not walk two thousand rows.
     *
     * @return array{attention: int, drift: int}
     */
    public function summary(): array
    {
        $pfx = (string) config('catalog.prefix');

        $attention = ShopeeProductLink::query()
            ->whereIn('live_status', ['BANNED', 'SELLER_DELETE', 'SHOPEE_DELETE', 'MISSING'])
            ->distinct()->count('product_id');

        $drift = ShopeeProductLink::query()
            ->whereIn('live_status', ['NORMAL', 'UNLIST'])
            ->join('shopee_listings as sl', 'sl.product_id', '=', 'shopee_product_links.product_id')
            ->join($pfx . 'product as p', 'p.product_id', '=', 'shopee_product_links.product_id')
            ->whereNotNull('sl.last_pushed_at')
            ->whereColumn('p.date_modified', '>', 'sl.last_pushed_at')
            ->distinct()->count('shopee_product_links.product_id');

        return ['attention' => $attention, 'drift' => $drift];
    }

    /** Worst first; the fold keeps the first status this list names. */
    private const SEVERITY = ['BANNED', 'SHOPEE_DELETE', 'SELLER_DELETE', 'MISSING', 'REVIEWING', 'UNLIST', 'NORMAL'];

    /**
     * The Last sync column, one answer for the Listings page and every
     * group page (the owner, 2026-09-05): a refusal wins and is told in full,
     * whichever surface pushed or synced; otherwise the newest successful
     * sync, stock and price on the link rows or the listing push, with
     * what it was; otherwise never.
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

        // The link's action codes, in words.
        $word = fn (?string $action): string => match (strtolower(trim((string) $action))) {
            'sync_qty', 'qty', 'stock', 'update_stock' => 'stock',
            'sync_price', 'price', 'update_price' => 'price',
            'push', 'listing', 'add_item', 'update_item' => 'listing',
            '' => 'sync',
            default => str_replace('_', ' ', strtolower(trim((string) $action))),
        };

        $listings = ShopeeListing::query()->whereIn('product_id', $productIds)->get()->keyBy('product_id');
        $links = ShopeeProductLink::query()->whereIn('product_id', $productIds)->get()->groupBy('product_id');
        $modified = DB::table((string) config('catalog.prefix') . 'product')
            ->whereIn('product_id', $productIds)->pluck('date_modified', 'product_id');

        $out = [];
        foreach ($productIds as $pid) {
            $listing = $listings->get($pid);
            $rows = $links->get($pid) ?? collect();

            // A refused push, from whichever surface.
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $at = $listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : null;
                $out[$pid] = [
                    'tone' => 'failed',
                    'headline' => 'Last push' . ($at ? ' ' . $at->diffForHumans() : '') . ' failed:',
                    'detail' => trim((string) $listing->last_push_error),
                    'at' => $at,
                ];
                continue;
            }

            // A refused stock or price sync on a link row, newest first.
            $failedSync = $rows->filter(fn ($r) => $r->last_sync_ok === false || (int) $r->last_sync_ok === 0 && $r->last_sync_ok !== null)
                ->filter(fn ($r) => trim((string) ($r->last_sync_error_message ?? '')) !== '')
                ->sortByDesc('last_synced_at')->first();
            if ($failedSync) {
                $at = $failedSync->last_synced_at ? Carbon::parse($failedSync->last_synced_at) : null;
                $what = ucfirst($word($failedSync->last_sync_action));
                $out[$pid] = [
                    'tone' => 'failed',
                    'headline' => $what . ' sync' . ($at ? ' ' . $at->diffForHumans() : '') . ' failed:',
                    'detail' => trim((string) $failedSync->last_sync_error_message),
                    'at' => $at,
                ];
                continue;
            }

            // The newest success: a link sync (stock, price) or the listing push.
            $candidates = [];
            foreach ($rows as $r) {
                if ($r->last_synced_at && ($r->last_sync_ok === null || (bool) $r->last_sync_ok)) {
                    $candidates[] = [Carbon::parse($r->last_synced_at), $word($r->last_sync_action)];
                }
            }
            if ($listing?->last_pushed_at) {
                $candidates[] = [Carbon::parse($listing->last_pushed_at), 'listing'];
            }
            usort($candidates, fn ($a, $b) => $b[0] <=> $a[0]);

            if ($candidates === []) {
                $out[$pid] = ['tone' => 'none', 'headline' => 'Never synced', 'detail' => null, 'at' => null];
                continue;
            }

            [$at, $what] = $candidates[0];
            // The catalog moved after that sync (#100): the marketplace is
            // behind what a push would send now. Said here, on both pages.
            $movedAt = ($modified[$pid] ?? null) ? Carbon::parse($modified[$pid]) : null;
            $stale = $movedAt && $movedAt->gt($at);
            $out[$pid] = [
                'tone' => 'ok',
                'headline' => 'Synced ' . $at->diffForHumans(),
                'what' => $what,
                'stale' => $stale,
                'detail' => null,
                'at' => $at,
            ];
        }

        return $out;
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

        $links = ShopeeProductLink::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->groupBy('product_id');

        $listings = ShopeeListing::query()
            ->whereIn('product_id', $productIds)
            ->get()
            ->keyBy('product_id');

        $modified = DB::table($pfx . 'product')
            ->whereIn('product_id', $productIds)
            ->pluck('date_modified', 'product_id');

        $unlinked = array_values(array_filter($productIds, fn ($id) => !$links->has($id)));
        $readiness = $this->readiness->forProducts($unlinked);

        $out = [];
        foreach ($productIds as $productId) {
            $listing = $listings->get($productId);
            $pushedAt = $listing?->last_pushed_at ? Carbon::parse($listing->last_pushed_at) : null;

            // One latest error per product (the owner, 2026-09-05): the last
            // refusal, from whichever surface pushed, told as a sentence on
            // every surface until a push succeeds.
            $failure = [];
            if ($listing && trim((string) ($listing->last_push_error ?? '')) !== '') {
                $failedAt = $listing->last_push_failed_at ? Carbon::parse($listing->last_push_failed_at) : null;
                $failure[] = 'Last push' . ($failedAt ? ' ' . $failedAt->diffForHumans() : '') . ' failed: ' . trim((string) $listing->last_push_error);
            }

            if (!$links->has($productId)) {
                $r = $readiness[$productId] ?? ['ready' => false, 'missing' => [], 'gaps' => []];
                $out[$productId] = new ListingState(
                    state: ListingState::NOT_LISTED,
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

            // Drift is a fact about live and inactive listings; a listing
            // Shopee no longer shows has bigger problems than staleness.
            $inSync = null;
            $reasons = $failure;
            if (in_array($worst, ['NORMAL', 'UNLIST'], true) && $pushedAt !== null) {
                $movedAt = ($modified[$productId] ?? null) ? Carbon::parse($modified[$productId]) : null;
                $inSync = !($movedAt && $movedAt->gt($pushedAt));
                if ($inSync === false) {
                    $reasons[] = 'The catalogue changed after the last push. Push an update when ready.';
                }
            }

            // The attention label is Shopee's own word for the trouble;
            // core ListingState knows no marketplace's vocabulary (#184 C).
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

            $out[$productId] = new ListingState(
                state: $state,
                inSync: $inSync,
                reasons: $reasons,
                checkedAt: $checkedAt,
                pushedAt: $pushedAt,
                channelStatusRaw: $worst,
                attentionLabel: $attentionLabel,
            );
        }

        return $out;
    }
}
