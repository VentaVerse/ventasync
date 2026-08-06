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
            'order_number' => ['nullable', 'string', 'max:80'],
            'buyer_name' => ['nullable', 'string', 'max:120'],
        ]);

        $orderNumber = trim((string) ($filters['order_number'] ?? ''));
        $buyerName = trim((string) ($filters['buyer_name'] ?? ''));

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50], true)) {
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

        if ($orderNumber !== '') {
            $query->where('order_sn', 'like', '%' . $orderNumber . '%');
        }

        if ($buyerName !== '') {
            $query->where(function ($q) use ($buyerName) {
                $like = '%' . $buyerName . '%';
                $q->where('raw->buyer_username', 'like', $like)
                    ->orWhere('raw->buyer_user_name', 'like', $like);
            });
        }

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            if (!empty($statuses)) {
                if ($k === 'PENDING') {
                    $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', ['READY_TO_SHIP'])->count();
                } else {
                    $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
                }
            } else {
                $tab_counts[$k] = 0;
            }
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
                'order_number' => $orderNumber,
                'buyer_name' => $buyerName,
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
