<?php

namespace Extensions\Shopee\Services;

use App\Support\Fulfillment\CourierTally;
use App\Support\FulfillmentSteps;
use Extensions\Shopee\Models\ShopeeOrder;
use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Http\Request;

class ShopeeOrdersPanel
{
    public function build(Request $request, ?int $storeId = null): array
    {
        $settingRaw = $storeId !== null
            ? ShopeeStore::query()->find($storeId)
            : ShopeeStore::defaultStore();
        if ($storeId !== null && $settingRaw !== null) {
            app()->instance('shopee.route-store', $settingRaw);
            \Illuminate\Support\Facades\URL::defaults(['store' => (int) $settingRaw->id]);
        }
        $setting = $settingRaw?->decrypted();
        $auth = ShopeeStore::activeAuth($setting);
        $settingId = (int) ($settingRaw?->id ?? 0);

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
            \App\Support\OrderAttention::TAB,
        ];
        if (!in_array($tab, $allowedTabs, true)) {
            $tab = 'ALL';
        }

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
            'ship_by_asc',
            'ship_by_desc',
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
            // RETRY_SHIP is a failed pickup: the parcel is still here, so it waits in To handover, as Seller Center keeps it under To Ship.
            'PENDING'             => ['READY_TO_SHIP', 'INVOICE_PENDING', 'PROCESSED', 'RETRY_SHIP'],
            'SHIPPING'            => ['SHIPPED', 'TO_CONFIRM_RECEIVE'],
            // TO_RETURN: delivered, then the buyer asked to send it back; the return itself is on the Returns page.
            'DELIVERED_COMPLETED' => ['COMPLETED', 'TO_RETURN'],
            'CANCELLED'           => ['CANCELLED', 'IN_CANCEL'],
        ];

        $mappedStatuses = array_values(array_unique(array_merge(...array_values($tabStatusMap))));

        $tabs = FulfillmentSteps::tabs([
            'ALL'                 => 'all',
            'UNPAID'              => 'unpaid',
            'PENDING'             => 'to_ship',
            'SHIPPING'            => 'shipping',
            'DELIVERED_COMPLETED' => 'delivered',
            'CANCELLED'           => 'canceled',
        ]);

        $subStepStatuses = ['to_pack' => ['READY_TO_SHIP', 'INVOICE_PENDING'], 'to_handover' => ['PROCESSED', 'RETRY_SHIP']];
        $courierTally = ($tab === 'PENDING' && isset($subStepStatuses[$pendingSubtab]))
            ? $this->buildToShipCourierTally($settingId, $subStepStatuses[$pendingSubtab])
            : new CourierTally();

        $query = ShopeeOrder::query()
            ->where('store_id', $settingId)
            ->with('products');

        $baseCountQuery = ShopeeOrder::query()->where('store_id', $settingId);
        $attention = \App\Support\OrderAttention::reasons($baseCountQuery, $mappedStatuses,
            \Illuminate\Support\Facades\DB::table('ch_shopee_order_status_map')->pluck('shopee_status')->all());
        if ($tab === \App\Support\OrderAttention::TAB && $attention === []) {
            $tab = 'ALL';
        }

        if ($tab !== 'ALL') {
            if ($tab === 'PENDING' && $pendingSubtab !== '') {
                $pendingMap = [
                    'to_pack'     => ['READY_TO_SHIP', 'INVOICE_PENDING'],
                    'to_handover' => ['PROCESSED', 'RETRY_SHIP'],
                ];
                $statuses = $pendingMap[$pendingSubtab] ?? ['READY_TO_SHIP'];
                $query->whereIn('status', $statuses);
            } elseif ($tab === \App\Support\OrderAttention::TAB) {
                $query->whereIn('status', array_keys($attention) ?: ['']);
            } else {
                $statuses = $tabStatusMap[$tab] ?? [];
                if (!empty($statuses)) {
                    $query->whereIn('status', $statuses);
                }
            }
        }

        if ($tab === 'PENDING' && isset($subStepStatuses[$pendingSubtab]) && $courierKey !== '') {
            $courierIds = $courierTally->idsFor($courierKey);
            if ($courierIds !== null) {
                $query->whereIn('id', $courierIds);
            }
        }

        $pending_sub_counts = [
            'to_pack'     => (clone $baseCountQuery)->whereIn('status', ['READY_TO_SHIP', 'INVOICE_PENDING'])->count(),
            'to_handover' => (clone $baseCountQuery)->whereIn('status', ['PROCESSED', 'RETRY_SHIP'])->count(),
        ];

        if ($find !== '') {
            $like = '%' . $find . '%';

            $query->where(function ($w) use ($like) {
                $w->where('order_sn', 'like', $like)
                    ->orWhere('raw->buyer_username', 'like', $like)
                    ->orWhere('raw->buyer_user_name', 'like', $like)
                    ->orWhere('raw->recipient_address->name', 'like', $like)
                    ->orWhere('raw->tracking_no', 'like', $like)
                    ->orWhere('raw->tracking_number', 'like', $like)

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

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            $tab_counts[$k] = !empty($statuses)
                ? (clone $baseCountQuery)->whereIn('status', $statuses)->count()
                : 0;
        }

        [$tabs, $tab_counts] = \App\Support\OrderAttention::prepend($tabs, $tab_counts, $baseCountQuery, $attention);

        if ($sortBy === 'confirmed_pay') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.pay_time')) AS UNSIGNED) {$sortDir}");
        } elseif ($sortBy === 'confirmed_update') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.update_time')) AS UNSIGNED) {$sortDir}");
        } elseif ($sortBy === 'confirmed_create') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.create_time')) AS UNSIGNED) {$sortDir}");
        } elseif ($sortBy === 'ship_by') {
            $query->orderByRaw("CAST(JSON_UNQUOTE(JSON_EXTRACT(raw, '$.ship_by_date')) AS UNSIGNED) {$sortDir}");
        }
        $orders = $query
            ->orderBy('order_created_at', $sortDir)
            ->orderBy('created_at', $sortDir)
            ->paginate($perPage)
            ->appends($request->query());

        $savedAwbs = [];
        foreach ($orders as $o) {
            $sn = (string) ($o->order_sn ?? '');
            if ($sn !== '' && file_exists(\App\Support\Fulfillment\Waybills::path('shopee', (int) $o->store_id, $sn))) {
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
            'attention' => $attention,
            'attention_mapping_url' => route('ext.shopee.index') . '?tab=status',
            'pending_sub_counts' => $pending_sub_counts,
            'courier_tally' => $courierTally->forStrip($courierKey),
            'savedAwbs' => $savedAwbs,
            'sort' => $sortKey,
            'filters' => [
                'q' => $find,
                'courier' => $courierKey,
                'placed_from' => $placedFrom,
                'placed_to' => $placedTo,
            ],
        ];
    }

    private function buildToShipCourierTally(int $storeId, array $statuses): CourierTally
    {
        $tally = new CourierTally();

        ShopeeOrder::query()
            ->where('store_id', $storeId)
            ->whereIn('status', $statuses)
            ->select(['id', 'raw'])
            ->orderBy('id')
            ->chunk(300, function ($orders) use ($tally) {
                foreach ($orders as $o) {
                    $tally->add((int) $o->id, $this->extractShopeeCourier($o));
                }
            });

        return $tally;
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
