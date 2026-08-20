<?php

namespace Extensions\shopee\Services;

use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Http\Request;

/**
 * Assembles the view data for the Shopee orders list: filters, tab counts,
 * sort persistence, pagination and the AWB-saved lookup. Shared by the
 * Shopee seller center (ShopeeOrderController@index) and the fulfilment hub,
 * both of which render the same ext-shopee::orders._panel partial with this
 * array. Extracted from ShopeeOrderController@index without any logic
 * change - see docs/superpowers/specs/2026-08-07-fulfilment-hub.md section 3.
 */
class ShopeeOrdersPanel
{
    public function build(Request $request): array
    {
        $settingRaw = ShopeeSetting::query()->first();
        $setting = $settingRaw?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'placed_from' => ['nullable', 'date'],
            'placed_to' => ['nullable', 'date', 'after_or_equal:placed_from'],
        ]);

        /* ONE FIELD, THE SAME ONE THE RETURNS LISTS CARRY.
         *
         * This was an order-number box beside a buyer box, and the critique's
         * standing red flag was that neither answers the call it was built
         * for: a buyer on the phone has a product and a rough date, not an
         * order number, and on Lazada every buyer name renders masked as
         * J******e, so that box was a dead control on the channel that needed
         * it most. Product was not searchable here at all.
         *
         * One box matching order number, buyer and product, and a date range
         * over when the order was placed - which this list has never had. */
        $find = trim((string) ($filters['q'] ?? ''));
        $placedFrom = trim((string) ($filters['placed_from'] ?? ''));
        $placedTo = trim((string) ($filters['placed_to'] ?? ''));

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        $tab = strtoupper((string) $request->query('tab', 'PENDING'));

        $pendingSubtab = (string) $request->query('pending_sub', $tab === 'PENDING' ? 'to_pack' : '');
        if ($tab !== 'PENDING') {
            $pendingSubtab = '';
        }

        $allowedTabs = [
            'ALL',
            'UNPAID',
            'PENDING',
            'SHIPPING',
            'DELIVERED_COMPLETED',
            'CANCELLED',
            'FAILED_DELIVERY',
            // Anything this channel's tab map does not name. The tab only
            // RENDERS when it has contents (see $otherCount below), but it
            // has to be accepted here unconditionally: this guard runs long
            // before the counts exist, and leaving it out silently bounced
            // the tab back to All, so the one control that reaches those
            // orders did nothing.
            'OTHER',
        ];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'ALL';
        }

        // Per-marketplace sort persistence: an explicit ?sort= in the
        // URL becomes the new sticky default for THIS marketplace only
        // (different marketplaces keep separate prefs). With no URL
        // param, fall back to the session value, then to the per-tab
        // default direction.
        $defaultDir = ($tab === 'PENDING') ? 'asc' : 'desc';
        $sortKey = strtolower((string) $request->query('sort', ''));
        if ($sortKey !== '') {
            session(['marketplace_sort.shopee' => $sortKey]);
        } else {
            $sortKey = strtolower((string) session('marketplace_sort.shopee', ''));
        }
        if ($sortKey === '') {
            $sortKey = 'created_' . $defaultDir;
        }
        $allowedSort = [
            'created_asc',
            'created_desc',
            'confirmed_pay_asc',
            'confirmed_pay_desc',
            'confirmed_update_asc',
            'confirmed_update_desc',
            'confirmed_create_asc',
            'confirmed_create_desc',
        ];
        if (!in_array($sortKey, $allowedSort, true)) {
            $sortKey = 'created_' . $defaultDir;
        }
        if (!preg_match('/^(.*)_(asc|desc)$/', $sortKey, $m)) {
            $sortBy = 'created';
            $sortDir = $defaultDir;
        } else {
            $sortBy = $m[1];
            $sortDir = $m[2];
        }

        $tabStatusMap = [
            'UNPAID'              => ['UNPAID'],
            'PENDING'             => ['READY_TO_SHIP', 'PROCESSED'],
            'SHIPPING'            => ['SHIPPED', 'TO_CONFIRM_RECEIVE'],
            'DELIVERED_COMPLETED' => ['COMPLETED'],
            'CANCELLED'           => ['CANCELLED', 'IN_CANCEL'],
            'FAILED_DELIVERY'     => ['RETRY_SHIP'],
        ];

        // Every status the map above does not name is reachable only by
        // paging through All, which on this channel is 191 pages. Shopee
        // sends TO_RETURN today and will send something else the next time
        // it extends its own list, so the gap is found rather than
        // enumerated: anything unmapped falls into Other, and Other only
        // appears when it has something in it. The tabs now always sum to
        // All, which is the property that was quietly untrue.
        $mappedStatuses = array_values(array_unique(array_merge(...array_values($tabStatusMap))));

        $tabs = [
            'ALL'                 => 'All',
            'UNPAID'              => 'Unpaid',
            'PENDING'             => 'To Ship',
            'SHIPPING'            => 'Shipping',
            'DELIVERED_COMPLETED' => 'Delivered',
            'CANCELLED'           => 'Cancelled',
            'FAILED_DELIVERY'     => 'Failed Delivery',
        ];

        $query = ShopeeOrder::query()
            ->where('region', $auth['region'] ?? 'ph')
            ->with('products');

        $baseCountQuery = ShopeeOrder::query()->where('region', $auth['region'] ?? 'ph');

        if ($tab !== 'ALL') {
            if ($tab === 'PENDING' && $pendingSubtab !== '') {
                $pendingMap = [
                    'to_pack'     => ['READY_TO_SHIP'],
                    'to_handover' => ['PROCESSED'],
                ];
                $statuses = $pendingMap[$pendingSubtab] ?? ['READY_TO_SHIP'];
                $query->whereIn('status', $statuses);
            } elseif ($tab === 'OTHER') {
                $query->whereNotIn('status', $mappedStatuses);
            } else {
                $statuses = $tabStatusMap[$tab] ?? [];
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        }

        $pending_sub_counts = [
            'to_pack'     => (clone $baseCountQuery)->whereIn('status', ['READY_TO_SHIP'])->count(),
            'to_handover' => (clone $baseCountQuery)->whereIn('status', ['PROCESSED'])->count(),
        ];

        if ($find !== '') {
            $like = '%' . $find . '%';

            $query->where(function ($w) use ($like) {
                $w->where('order_sn', 'like', $like)
                    ->orWhere('raw->buyer_username', 'like', $like)
                    ->orWhere('raw->buyer_user_name', 'like', $like)

                    /* The products are on the order, so this reaches through
                       the relation - the same shape the returns lists use. */
                    ->orWhereHas('products', function ($p) use ($like) {
                        $p->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('variation', 'like', $like);
                    });
            });
        }

        /* When the order was placed on the marketplace, not when a sync last
           wrote the row. whereDate so a To date includes the whole of it. */
        if ($placedFrom !== '') {
            $query->whereDate('order_created_at', '>=', $placedFrom);
        }
        if ($placedTo !== '') {
            $query->whereDate('order_created_at', '<=', $placedTo);
        }

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            // To Ship counts BOTH of its steps. It used to count only
            // READY_TO_SHIP, the To Pack sub-step, so a parcel sitting in To
            // Handover made the page say "To Ship 0" directly above a
            // sub-tab reading "To Handover 1" - the screen contradicting
            // itself about whether there was work.
            $tab_counts[$k] = !empty($statuses)
                ? (clone $baseCountQuery)->whereIn('status', $statuses)->count()
                : 0;
        }

        $otherCount = (clone $baseCountQuery)->whereNotIn('status', $mappedStatuses)->count();
        if ($otherCount > 0) {
            $tabs['OTHER'] = 'Other';
            $tab_counts['OTHER'] = $otherCount;
        }

        if ($sortBy === 'confirmed_pay') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.pay_time')) AS UNSIGNED) {$sortDir}");
        } elseif ($sortBy === 'confirmed_update') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.update_time')) AS UNSIGNED) {$sortDir}");
        } elseif ($sortBy === 'confirmed_create') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.create_time')) AS UNSIGNED) {$sortDir}");
        }
        $orders = $query
            ->orderBy('order_created_at', $sortDir)
            ->orderBy('created_at', $sortDir)
            ->paginate($perPage)
            ->appends($request->query());

        // Detect which orders have saved AWB PDFs
        $awbDir = storage_path('app/shopee-awb');
        $savedAwbs = [];
        foreach ($orders as $o) {
            $sn = preg_replace('/[^A-Za-z0-9_-]/', '', $o->order_sn ?? '');
            if ($sn !== '' && file_exists($awbDir . '/' . $sn . '.pdf')) {
                $savedAwbs[$o->order_sn] = true;
            }
        }

        return [
            'setting' => $settingRaw,
            'orders' => $orders,
            'per_page' => $perPage,
            'last_result' => session('shopee_orders_last_result'),
            'tabs' => $tabs,
            'active_tab' => $tab,
            'pending_subtab' => $pendingSubtab,
            'tab_counts' => $tab_counts,
            'pending_sub_counts' => $pending_sub_counts,
            'pending_courier_counts' => $this->buildToShipCourierCounts($auth['region'] ?? 'ph'),
            'savedAwbs' => $savedAwbs,
            'sort' => $sortKey,
            'filters' => [
                'q' => $find,
                'placed_from' => $placedFrom,
                'placed_to' => $placedTo,
            ],
        ];
    }

    private function buildToShipCourierCounts(string $region): array
    {
        $counts = [];
        $display = [];
        $statuses = ['PROCESSED'];

        ShopeeOrder::query()
            ->where('region', $region)
            ->whereIn('status', $statuses)
            ->select(['id', 'raw'])
            ->orderBy('id')
            ->chunk(300, function ($orders) use (&$counts, &$display) {
                foreach ($orders as $o) {
                    $courier = $this->extractShopeeCourier($o);
                    $label = $courier !== '' ? $courier : 'Unknown';
                    $key = mb_strtolower($label);
                    if (!isset($display[$key])) {
                        $display[$key] = $label;
                    }
                    $counts[$key] = ($counts[$key] ?? 0) + 1;
                }
            });

        arsort($counts);
        $out = [];
        foreach ($counts as $key => $count) {
            $out[$display[$key] ?? $key] = $count;
        }

        return $out;
    }

    private function extractShopeeCourier(ShopeeOrder $order): string
    {
        $raw = is_array($order->raw) ? $order->raw : [];
        foreach (['shipping_carrier', 'checkout_shipping_carrier'] as $k) {
            $v = $raw[$k] ?? null;
            if (is_array($v)) {
                $v = implode(', ', $v);
            }
            if (is_string($v) && trim($v) !== '') {
                return trim($v);
            }
        }

        return '';
    }
}
