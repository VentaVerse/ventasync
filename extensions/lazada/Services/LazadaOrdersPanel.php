<?php

namespace Extensions\lazada\Services;

use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Http\Request;

/**
 * Assembles the view data for the Lazada orders list: filters, tab counts,
 * sort persistence, pagination and the AWB-saved lookup. Shared by the
 * Lazada seller center (LazadaOrderController@index) and the fulfilment hub,
 * both of which render the same ext-lazada::orders._panel partial with this
 * array. Extracted from LazadaOrderController@index without any logic
 * change - mirrors Extensions\shopee\Services\ShopeeOrdersPanel exactly, see
 * docs/superpowers/plans/2026-08-07-lazada-conversion.md Task 3.
 */
class LazadaOrdersPanel
{
    public function build(Request $request): array
    {
        $setting = LazadaSetting::query()->first();

        $filters = $request->validate([
            'order_number' => ['nullable', 'string', 'max:80'],
            'buyer_name' => ['nullable', 'string', 'max:120'],
        ]);

        $orderNumber = trim((string)($filters['order_number'] ?? ''));
        $buyerName = trim((string)($filters['buyer_name'] ?? ''));

        $perPage = (int)$request->query('per_page', 10);
        if (!in_array($perPage, [10,20,50], true)) { $perPage = 10; }

        $tab = strtoupper((string)$request->query('tab', 'ALL'));

        // Lazada "TO_SHIP" has internal workflow steps (to pack -> to arrange shipment -> to handover).
        $pendingSubtab = (string) $request->query('pending_sub', $tab === 'TO_SHIP' ? 'to_pack' : '');
        if ($tab !== 'TO_SHIP') {
            $pendingSubtab = '';
        }

        // Failed Delivery has sub-tabs: Failed Delivery / Lost & Damaged
        $fdSub = (string) $request->query('fd_sub', $tab === 'FAILED_DELIVERY' ? 'failed_delivery' : '');
        if ($tab !== 'FAILED_DELIVERY') {
            $fdSub = '';
        }

        $allowedTabs = [
            'ALL',
            'UNPAID',
            'TO_SHIP',
            'SHIPPING',
            'DELIVERED',
            'CANCELLATION',
            'FAILED_DELIVERY',
        ];
        if (!in_array($tab, $allowedTabs, true)) { $tab = 'ALL'; }

        // Per-marketplace sort persistence, see ShopeeOrderController
        // for the rationale. Session key is namespaced to "lazada".
        $defaultDir = ($tab === 'TO_SHIP') ? 'asc' : 'desc';
        $sortKey = strtolower((string)$request->query('sort', ''));
        if ($sortKey !== '') {
            session(['marketplace_sort.lazada' => $sortKey]);
        } else {
            $sortKey = strtolower((string) session('marketplace_sort.lazada', ''));
        }
        if ($sortKey === '') {
            $sortKey = 'created_' . $defaultDir;
        }
        $allowedSort = [
            'created_asc',
            'created_desc',
            'confirmed_created_asc',
            'confirmed_created_desc',
            'confirmed_updated_asc',
            'confirmed_updated_desc',
            'promised_shipping_asc',
            'promised_shipping_desc',
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
            'UNPAID'          => ['unpaid'],
            'TO_SHIP'         => ['pending', 'repacked', 'packed', 'ready_to_ship'],
            'SHIPPING'        => ['shipped'],
            'DELIVERED'       => ['delivered', 'confirmed'],
            'CANCELLATION'    => ['canceled', 'cancelled'],
            'FAILED_DELIVERY' => ['failed_delivery', 'lost_by_3pl', 'damaged_by_3pl', 'shipped_back', 'shipped_back_success'],
        ];

        $tabs = [
            'ALL'             => 'All',
            'UNPAID'          => 'Unpaid',
            'TO_SHIP'         => 'To Ship',
            'SHIPPING'        => 'Shipping',
            'DELIVERED'       => 'Delivered',
            'CANCELLATION'    => 'Cancellation',
            'FAILED_DELIVERY' => 'Failed Delivery',
        ];

        $query = LazadaOrder::query()
            ->where('region', $setting->region ?? 'ph')
            ->with('products');

        // Base query used for badge counts (DB only)
        $baseCountQuery = LazadaOrder::query()->where('region', $setting->region ?? 'ph');


        if ($tab !== 'ALL') {
            if ($tab === 'TO_SHIP' && $pendingSubtab !== '') {
                $pendingMap = [
                    'to_pack' => ['pending', 'repacked'],
                    'to_arrange' => ['packed'],
                    'to_handover' => ['ready_to_ship'],
                ];
                $statuses = $pendingMap[$pendingSubtab] ?? ['pending'];
                $query->whereIn('status', $statuses);
            } elseif ($tab === 'FAILED_DELIVERY' && $fdSub !== '') {
                $fdMap = [
                    'failed_delivery' => ['failed_delivery'],
                    'shipped_back' => ['shipped_back', 'shipped_back_success'],
                    'lost_damaged' => ['lost_by_3pl', 'damaged_by_3pl'],
                ];
                $statuses = $fdMap[$fdSub] ?? ['failed_delivery'];
                $query->whereIn('status', $statuses);
            } else {
                $statuses = $tabStatusMap[$tab] ?? [];
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        }


        // Counts for TO_SHIP sub-tabs (DB only)
        $pending_sub_counts = [
            'to_pack' => (clone $baseCountQuery)->whereIn('status', ['pending', 'repacked'])->count(),
            'to_arrange' => (clone $baseCountQuery)->whereIn('status', ['packed'])->count(),
            'to_handover' => (clone $baseCountQuery)->whereIn('status', ['ready_to_ship'])->count(),
        ];

        // Counts for FAILED_DELIVERY sub-tabs (DB only)
        $fd_sub_counts = [
            'failed_delivery' => (clone $baseCountQuery)->whereIn('status', ['failed_delivery'])->count(),
            'shipped_back' => (clone $baseCountQuery)->whereIn('status', ['shipped_back', 'shipped_back_success'])->count(),
            'lost_damaged' => (clone $baseCountQuery)->whereIn('status', ['lost_by_3pl', 'damaged_by_3pl'])->count(),
        ];
        // Search filters (safe, parameter-bound). LazadaOrder currently stores customer info in raw JSON.
        if ($orderNumber !== '') {
            $query->where('order_id', 'like', '%' . $orderNumber . '%');
        }

        if ($buyerName !== '') {
            $query->where(function ($q) use ($buyerName) {
                $like = '%' . $buyerName . '%';
                $q->where('raw->customer_first_name', 'like', $like)
                    ->orWhere('raw->customer_name', 'like', $like)
                    ->orWhere('raw->buyer_name', 'like', $like);
            });
        }

        // Counts for tab badges (DB only; UI will still show live status per row).
        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            if (!empty($statuses)) {
                if ($k === 'TO_SHIP') {
                    $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', ['pending', 'repacked'])->count();
                } elseif ($k === 'FAILED_DELIVERY') {
                    $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', ['failed_delivery', 'shipped_back', 'shipped_back_success'])->count();
                } else {
                    $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
                }
            } else {
                $tab_counts[$k] = 0;
            }
        }

        if ($sortBy === 'confirmed_created') {
            $query->orderByRaw("JSON_UNQUOTE(JSON_EXTRACT(raw, '$.created_at')) {$sortDir}");
        } elseif ($sortBy === 'confirmed_updated') {
            $query->orderByRaw("JSON_UNQUOTE(JSON_EXTRACT(raw, '$.updated_at')) {$sortDir}");
        } elseif ($sortBy === 'promised_shipping') {
            $query->orderByRaw("JSON_UNQUOTE(JSON_EXTRACT(raw, '$.promised_shipping_times')) {$sortDir}");
        }
        $orders = $query
            ->orderBy('order_created_at', $sortDir)
            ->orderBy('created_at', $sortDir)
            ->paginate($perPage)
            ->appends($request->query());

        // IMPORTANT: Do NOT call Lazada APIs from the listing page.
        // Doing so quickly hits SellerCallLimit and prevents products from syncing reliably.
        // Products (and any status refresh) should happen during explicit user actions (Fetch Orders / Pack / RTS).
        $live_statuses = [];

        // Detect which orders have saved AWB PDFs locally
        $awbDir = storage_path('app/lazada-awb');
        $savedAwbs = [];
        foreach ($orders as $o) {
            $oid = preg_replace('/[^0-9A-Za-z_-]/', '', $o->order_id ?? '');
            if ($oid !== '' && file_exists($awbDir . '/' . $oid . '.pdf')) {
                $savedAwbs[$o->order_id] = true;
            }
        }

        return [
            'setting' => $setting,
            'orders' => $orders,
            'per_page' => $perPage,
            'last_result' => session('lazada_orders_last_result'),
            'tabs' => $tabs,
            'active_tab' => $tab,
            'pending_subtab' => $pendingSubtab,
            'fd_subtab' => $fdSub,
            'tab_counts' => $tab_counts,
            'pending_sub_counts' => $pending_sub_counts,
            'fd_sub_counts' => $fd_sub_counts,
            'to_ship_courier_counts' => $this->buildToShipCourierCounts($setting->region ?? 'ph'),
            'live_statuses' => $live_statuses,
            'savedAwbs' => $savedAwbs,
            'sort' => $sortKey,
            'filters' => [
                'order_number' => $orderNumber,
                'buyer_name' => $buyerName,
            ],
        ];
    }

    private function buildToShipCourierCounts(string $region): array
    {
        $counts = [];
        $display = [];
        $statuses = ['ready_to_ship'];

        LazadaOrder::query()
            ->where('region', $region)
            ->whereIn('status', $statuses)
            ->with(['products' => function ($q) {
                $q->select('id', 'lazada_order_id', 'raw');
            }])
            ->orderBy('id')
            ->chunk(200, function ($orders) use (&$counts, &$display) {
                foreach ($orders as $o) {
                    $courier = $this->extractLazadaCourier($o);
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

    private function extractLazadaCourier(LazadaOrder $order): string
    {
        $raw = is_array($order->raw) ? $order->raw : [];
        $detail = (isset($raw['_detail']) && is_array($raw['_detail'])) ? $raw['_detail'] : [];
        $firstRaw = [];
        if ($order->relationLoaded('products') && $order->products->isNotEmpty()) {
            $first = $order->products->first();
            if ($first && is_array($first->raw ?? null)) {
                $firstRaw = $first->raw;
            }
        }

        foreach ([$firstRaw, $detail, $raw] as $src) {
            foreach (['shipment_provider', 'shipping_provider', 'shipping_provider_name'] as $k) {
                $v = $src[$k] ?? null;
                if (is_array($v)) {
                    $v = implode(', ', $v);
                }
                if (is_string($v) && trim($v) !== '') {
                    return trim($v);
                }
            }
        }

        return '';
    }
}
