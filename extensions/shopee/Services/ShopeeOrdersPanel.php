<?php

namespace Extensions\shopee\Services;

use App\Support\Fulfilment\CourierTally;
use App\Support\FulfilmentSteps;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Http\Request;

class ShopeeOrdersPanel
{
    public function build(Request $request, ?int $storeId = null): array
    {
        $settingRaw = $storeId !== null
            ? ShopeeSetting::query()->find($storeId)
            : ShopeeSetting::defaultStore();
        if ($storeId !== null && $settingRaw !== null) {
            app()->instance('shopee.route-store', $settingRaw);
            \Illuminate\Support\Facades\URL::defaults(['store' => (int) $settingRaw->id]);
        }
        $setting = $settingRaw?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);
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
            'FAILED_DELIVERY',
            'OTHER',
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
            'PENDING'             => ['READY_TO_SHIP', 'PROCESSED'],
            'SHIPPING'            => ['SHIPPED', 'TO_CONFIRM_RECEIVE'],
            'DELIVERED_COMPLETED' => ['COMPLETED'],
            'CANCELLED'           => ['CANCELLED', 'IN_CANCEL'],
            'FAILED_DELIVERY'     => ['RETRY_SHIP'],
        ];

        $mappedStatuses = array_values(array_unique(array_merge(...array_values($tabStatusMap))));

        $tabs = FulfilmentSteps::tabs([
            'ALL'                 => 'all',
            'UNPAID'              => 'unpaid',
            'PENDING'             => 'to_ship',
            'SHIPPING'            => 'shipping',
            'DELIVERED_COMPLETED' => 'delivered',
            'CANCELLED'           => 'cancelled',
            'FAILED_DELIVERY'     => 'failed',
        ]);

        $subStepStatuses = ['to_pack' => ['READY_TO_SHIP'], 'to_handover' => ['PROCESSED']];
        $courierTally = ($tab === 'PENDING' && isset($subStepStatuses[$pendingSubtab]))
            ? $this->buildToShipCourierTally($settingId, $subStepStatuses[$pendingSubtab])
            : new CourierTally();

        $query = ShopeeOrder::query()
            ->where('shopee_setting_id', $settingId)
            ->with('products');

        $baseCountQuery = ShopeeOrder::query()->where('shopee_setting_id', $settingId);

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

        if ($tab === 'PENDING' && isset($subStepStatuses[$pendingSubtab]) && $courierKey !== '') {
            $courierIds = $courierTally->idsFor($courierKey);
            if ($courierIds !== null) {
                $query->whereIn('id', $courierIds);
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

        $otherCount = (clone $baseCountQuery)->whereNotIn('status', $mappedStatuses)->count();
        if ($otherCount > 0) {
            $tabs['OTHER'] = FulfilmentSteps::label('other');
            $tab_counts['OTHER'] = $otherCount;
        }

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
            if ($sn !== '' && file_exists(\App\Support\Fulfilment\Waybills::path('shopee', (int) $o->shopee_setting_id, $sn))) {
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

    private function buildToShipCourierTally(int $settingId, array $statuses): CourierTally
    {
        $tally = new CourierTally();

        ShopeeOrder::query()
            ->where('shopee_setting_id', $settingId)
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
