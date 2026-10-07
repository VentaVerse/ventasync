<?php

namespace Extensions\lazada\Services;

use App\Support\Fulfilment\CourierTally;
use App\Support\FulfilmentSteps;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Http\Request;

class LazadaOrdersPanel
{
    public function build(Request $request, ?int $storeId = null): array
    {
        $setting = $storeId !== null
            ? LazadaSetting::query()->find($storeId)
            : LazadaSetting::defaultStore();
        if ($storeId !== null && $setting !== null) {
            app()->instance('lazada.route-store', $setting);
            \Illuminate\Support\Facades\URL::defaults(['store' => (int) $setting->id]);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'placed_from' => ['nullable', 'date'],
            'placed_to' => ['nullable', 'date', 'after_or_equal:placed_from'],
            'courier' => ['nullable', 'string', 'max:80'],
        ]);

        $courierKey = trim((string) ($filters['courier'] ?? ''));

        $find = trim((string) ($filters['q'] ?? ''));
        $placedFrom = trim((string) ($filters['placed_from'] ?? ''));
        $placedTo = trim((string) ($filters['placed_to'] ?? ''));

        $perPage = (int)$request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50, 100], true)) { $perPage = 10; }

        $tab = strtoupper((string)$request->query('tab', 'ALL'));

        $pendingSubtab = (string) $request->query('pending_sub', $tab === 'TO_SHIP' ? 'to_pack' : '');
        if ($tab !== 'TO_SHIP') {
            $pendingSubtab = '';
        }

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
            'OTHER',
        ];
        if (!in_array($tab, $allowedTabs, true)) { $tab = 'ALL'; }

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

        $mappedStatuses = array_values(array_unique(array_merge(...array_values($tabStatusMap))));

        $tabs = FulfilmentSteps::tabs([
            'ALL'             => 'all',
            'UNPAID'          => 'unpaid',
            'TO_SHIP'         => 'to_ship',
            'SHIPPING'        => 'shipping',
            'DELIVERED'       => 'delivered',
            'CANCELLATION'    => 'cancelled',
            'FAILED_DELIVERY' => 'failed',
        ]);

        $subStepStatuses = [
            'to_pack' => ['pending', 'repacked'],
            'to_arrange' => ['packed'],
            'to_handover' => ['ready_to_ship'],
        ];
        $courierTally = ($tab === 'TO_SHIP' && isset($subStepStatuses[$pendingSubtab]))
            ? $this->buildToShipCourierTally($setting->region ?? 'ph', $subStepStatuses[$pendingSubtab])
            : new CourierTally();

        $query = LazadaOrder::query()
            ->where('region', $setting->region ?? 'ph')
            ->with('products');

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
            } elseif ($tab === 'OTHER') {
                $query->whereNotIn('status', $mappedStatuses);
            } else {
                $statuses = $tabStatusMap[$tab] ?? [];
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        }


        if ($tab === 'TO_SHIP' && isset($subStepStatuses[$pendingSubtab]) && $courierKey !== '') {
            $courierIds = $courierTally->idsFor($courierKey);
            if ($courierIds !== null) {
                $query->whereIn('id', $courierIds);
            }
        }

        $pending_sub_counts = [
            'to_pack' => (clone $baseCountQuery)->whereIn('status', ['pending', 'repacked'])->count(),
            'to_arrange' => (clone $baseCountQuery)->whereIn('status', ['packed'])->count(),
            'to_handover' => (clone $baseCountQuery)->whereIn('status', ['ready_to_ship'])->count(),
        ];

        $fd_sub_counts = [
            'failed_delivery' => (clone $baseCountQuery)->whereIn('status', ['failed_delivery'])->count(),
            'shipped_back' => (clone $baseCountQuery)->whereIn('status', ['shipped_back', 'shipped_back_success'])->count(),
            'lost_damaged' => (clone $baseCountQuery)->whereIn('status', ['lost_by_3pl', 'damaged_by_3pl'])->count(),
        ];
        if ($find !== '') {
            $like = '%' . $find . '%';

            $query->where(function ($w) use ($like) {
                $w->where('order_id', 'like', $like)
                    ->orWhere('raw->customer_first_name', 'like', $like)
                    ->orWhere('raw->customer_name', 'like', $like)
                    ->orWhere('raw->buyer_name', 'like', $like)
                    ->orWhere('raw->tracking_code', 'like', $like)
                    ->orWhere('raw->tracking_number', 'like', $like)
                    ->orWhere('raw->_detail->tracking_code', 'like', $like)

                    ->orWhereHas('products', function ($p) use ($like) {
                        $p->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('variation', 'like', $like)
                            ->orWhere('raw->tracking_code', 'like', $like);
                    });
            });
        }

        if ($placedFrom !== '') {
            $query->whereDate('order_created_at', '>=', $placedFrom);
        }
        if ($placedTo !== '') {
            $query->whereDate('order_created_at', '<=', $placedTo);
        }

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            $tab_counts[$k] = !empty($statuses)
                ? (clone $baseCountQuery)->whereIn('status', $statuses)->count()
                : 0;
        }

        $otherCount = (clone $baseCountQuery)->whereNotIn('status', $mappedStatuses)->count();
        if ($otherCount > 0) {
            $tabs['OTHER'] = FulfilmentSteps::label('other');
            $tab_counts['OTHER'] = $otherCount;
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

        $live_statuses = [];

        $savedAwbs = [];
        foreach ($orders as $o) {
            $oid = (string) ($o->order_id ?? '');
            if ($oid !== '' && file_exists(\App\Support\Fulfilment\Waybills::path('lazada', (int) $o->lazada_setting_id, $oid))) {
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
            'courier_tally' => $courierTally->forStrip($courierKey),
            'live_statuses' => $live_statuses,
            'savedAwbs' => $savedAwbs,
            'pack_print_parcel' => $this->resolvePackedParcel($setting),
            'bulk_pack' => session('lazada_bulk_pack'),
            'sort' => $sortKey,
            'filters' => [
                'q' => $find,
                'courier' => $courierKey,
                'placed_from' => $placedFrom,
                'placed_to' => $placedTo,
            ],
        ];
    }

    private function resolvePackedParcel(?LazadaSetting $setting): ?array
    {
        $orderId = (string) session('open_pack_print_modal_order_id', '');

        if ($orderId === '' || $setting === null) {
            return null;
        }

        $order = LazadaOrder::query()
            ->where('region', $setting->region)
            ->where('order_id', $orderId)
            ->with('products')
            ->first();

        return \App\Support\Fulfilment\PackedParcel::fromOrder($order, $orderId);
    }

    private function buildToShipCourierTally(string $region, array $statuses): CourierTally
    {
        $tally = new CourierTally();

        LazadaOrder::query()
            ->where('region', $region)
            ->whereIn('status', $statuses)
            ->with(['products' => function ($q) {
                $q->select('id', 'lazada_order_id', 'raw');
            }])
            ->orderBy('id')
            ->chunk(200, function ($orders) use ($tally) {
                foreach ($orders as $o) {
                    $tally->add((int) $o->id, $this->extractLazadaCourier($o));
                }
            });

        return $tally;
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
