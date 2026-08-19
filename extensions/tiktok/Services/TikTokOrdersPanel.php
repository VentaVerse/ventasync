<?php

namespace Extensions\tiktok\Services;

use Extensions\tiktok\Models\TikTokOrder;
use Illuminate\Http\Request;

/**
 * Assembles the view data for the TikTok orders list: filters, tab and
 * sub-tab counts, sort persistence, the handover courier tally and
 * pagination. Shared by the TikTok seller center (TikTokOrderController@index)
 * and the fulfilment hub, both of which render the same
 * ext-tiktok::orders._panel partial with this array. Extracted from
 * TikTokOrderController@index without any logic change - mirrors
 * Extensions\shopee\Services\ShopeeOrdersPanel and
 * Extensions\lazada\Services\LazadaOrdersPanel exactly, see
 * docs/superpowers/plans/2026-08-07-tiktok-conversion.md Task 2.
 */
class TikTokOrdersPanel
{
    // TikTok order status → tab mapping. Moved verbatim from the controller,
    // which was the only reader of it.
    private array $tabStatusMap = [
        'UNPAID'           => ['UNPAID'],
        'TO_SHIP'          => ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'],
        'IN_TRANSIT'       => ['IN_TRANSIT'],
        'DELIVERED'        => ['DELIVERED'],
        'COMPLETED'        => ['COMPLETED'],
        'CANCELLED'        => ['CANCELLED'],
    ];

    public function build(Request $request): array
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'placed_from' => ['nullable', 'date'],
            'placed_to' => ['nullable', 'date', 'after_or_equal:placed_from'],
        ]);

        /* One field matching order number, buyer and product, plus a range
         * over when the order was placed - the same pair every other
         * fulfilment list carries. See the note on the Shopee orders panel
         * for why this replaced two labelled boxes. */
        $find = trim((string) ($filters['q'] ?? ''));
        $placedFrom = trim((string) ($filters['placed_from'] ?? ''));
        $placedTo = trim((string) ($filters['placed_to'] ?? ''));

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50], true)) {
            $perPage = 10;
        }

        $tab = strtoupper((string) $request->query('tab', 'TO_SHIP'));
        $allowedTabs = ['ALL', 'UNPAID', 'TO_SHIP', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED', 'CANCELLED'];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'ALL';
        }

        // TO_SHIP has subtabs: to_pack (AWAITING_SHIPMENT) and to_handover (AWAITING_COLLECTION)
        $pendingSubtab = (string) $request->query('pending_sub', $tab === 'TO_SHIP' ? 'to_pack' : '');
        if ($tab !== 'TO_SHIP') {
            $pendingSubtab = '';
        }

        $tabs = [
            'ALL'          => 'All',
            'UNPAID'       => 'Unpaid',
            'TO_SHIP'      => 'To Ship',
            'IN_TRANSIT'   => 'Shipping',
            'DELIVERED'    => 'Delivered',
            'COMPLETED'    => 'Completed',
            'CANCELLED'    => 'Cancelled',
        ];

        // Per-marketplace sort persistence, see ShopeeOrderController
        // for the rationale. Session key is namespaced to "tiktok".
        $defaultDir = ($tab === 'TO_SHIP') ? 'asc' : 'desc';
        $sortKey = strtolower((string) $request->query('sort', ''));
        if ($sortKey !== '') {
            session(['marketplace_sort.tiktok' => $sortKey]);
        } else {
            $sortKey = strtolower((string) session('marketplace_sort.tiktok', ''));
        }
        if (!in_array($sortKey, ['created_asc', 'created_desc'], true)) {
            $sortKey = 'created_' . $defaultDir;
        }
        $sortDir = str_ends_with($sortKey, '_asc') ? 'asc' : 'desc';

        $query = TikTokOrder::query()->with('products');
        $baseCountQuery = TikTokOrder::query();

        if ($tab !== 'ALL') {
            // Apply subtab filter for TO_SHIP
            if ($tab === 'TO_SHIP' && $pendingSubtab !== '') {
                $subStatuses = match ($pendingSubtab) {
                    'to_pack'     => ['AWAITING_SHIPMENT'],
                    'to_handover' => ['AWAITING_COLLECTION'],
                    default       => ['AWAITING_SHIPMENT', 'AWAITING_COLLECTION'],
                };
                $query->whereIn('status', $subStatuses);
            } else {
                $statuses = $this->tabStatusMap[$tab] ?? [];
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        }

        // Search
        if ($find !== '') {
            $like = '%' . $find . '%';

            $query->where(function ($w) use ($like) {
                $w->where('order_id', 'like', $like)
                    ->orWhere('buyer_name', 'like', $like)
                    ->orWhereHas('products', function ($p) use ($like) {
                        $p->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('variation', 'like', $like);
                    });
            });
        }

        if ($placedFrom !== '') {
            $query->whereDate('order_created_at', '>=', $placedFrom);
        }
        if ($placedTo !== '') {
            $query->whereDate('order_created_at', '<=', $placedTo);
        }

        // Badge counts
        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($this->tabStatusMap as $k => $statuses) {
            $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
        }
        // Subtab counts for TO_SHIP
        $tab_counts['to_pack'] = (clone $baseCountQuery)->whereIn('status', ['AWAITING_SHIPMENT'])->count();
        $tab_counts['to_handover'] = (clone $baseCountQuery)->whereIn('status', ['AWAITING_COLLECTION'])->count();

        // Courier tally for To Handover
        $courierCounts = [];
        if ($tab === 'TO_SHIP') {
            $handoverOrders = TikTokOrder::query()
                ->where('status', 'AWAITING_COLLECTION')
                ->get(['id', 'raw']);
            foreach ($handoverOrders as $ho) {
                $hoRaw = is_array($ho->raw) ? $ho->raw : [];
                $courier = (string)($hoRaw['shipping_provider'] ?? $hoRaw['shipping_provider_name'] ?? '');
                if ($courier === '' && !empty($hoRaw['line_items'])) {
                    $courier = (string)(($hoRaw['line_items'][0] ?? [])['shipping_provider_name'] ?? '');
                }
                $label = $courier !== '' ? $courier : 'Unknown';
                $key = mb_strtolower($label);
                $courierCounts[$key] = ['label' => $label, 'count' => ($courierCounts[$key]['count'] ?? 0) + 1];
            }
        }

        $orders = $query
            ->orderBy('order_created_at', $sortDir)
            ->orderBy('created_at', $sortDir)
            ->paginate($perPage)
            ->appends($request->query());

        return [
            'orders'        => $orders,
            'per_page'      => $perPage,
            'tabs'          => $tabs,
            'active_tab'    => $tab,
            'pending_sub'   => $pendingSubtab,
            'tab_counts'    => $tab_counts,
            'sort'          => $sortKey,
            'last_result'   => session('tiktok_orders_last_result'),
            'courier_counts' => $courierCounts,
            'filters'       => [
                'q' => $find,
                'placed_from' => $placedFrom,
                'placed_to' => $placedTo,
            ],
        ];
    }
}
