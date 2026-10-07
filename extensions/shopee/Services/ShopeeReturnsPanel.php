<?php

namespace Extensions\shopee\Services;

use Extensions\shopee\Models\ShopeeReturn;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Http\Request;

class ShopeeReturnsPanel
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
            'q'           => ['nullable', 'string', 'max:120'],
            'opened_from' => ['nullable', 'date'],
            'opened_to'   => ['nullable', 'date', 'after_or_equal:opened_from'],
        ]);

        $find = trim((string) ($filters['q'] ?? ''));
        $openedFrom  = trim((string) ($filters['opened_from'] ?? ''));
        $openedTo    = trim((string) ($filters['opened_to'] ?? ''));

        $sortKey = strtolower((string) $request->query('sort', ''));
        $allowedSort = ['opened_desc', 'opened_asc', 'updated_desc', 'updated_asc'];
        if (!in_array($sortKey, $allowedSort, true)) {
            $sortKey = 'updated_desc';
        }

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }

        $tab = strtoupper((string) $request->query('tab', 'ALL'));

        $tabStatusMap = [
            'REQUESTED' => ['REQUESTED', 'PROCESSING'],
            'ACCEPTED'  => ['ACCEPTED'],
            'REFUND'    => ['REFUND_PAID', 'SELLER_COMPENSATION'],
            'DISPUTE'   => ['JUDGING', 'SELLER_DISPUTE'],
            'CLOSED'    => ['CLOSED', 'CANCELLED'],
        ];

        $tabs = [
            'ALL'       => 'All',
            'REQUESTED' => 'Requested',
            'ACCEPTED'  => 'Accepted',
            'REFUND'    => 'Refunded',
            'DISPUTE'   => 'Disputed',
            'CLOSED'    => 'Closed or cancelled',
        ];

        if (!array_key_exists($tab, $tabs)) {
            $tab = 'ALL';
        }

        $query = ShopeeReturn::query()->where('shopee_setting_id', $settingId);
        $baseCountQuery = ShopeeReturn::query()->where('shopee_setting_id', $settingId);

        if ($tab !== 'ALL') {
            $statuses = $tabStatusMap[$tab] ?? [];
            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($find !== '') {
            $like = '%' . $find . '%';

            $query->where(function ($w) use ($like) {
                $w->where('return_sn', 'like', $like)
                    ->orWhere('order_sn', 'like', $like)

                    ->orWhereHas('order', function ($o) use ($like) {
                        $o->where('raw->buyer_username', 'like', $like)
                            ->orWhere('raw->buyer_user_name', 'like', $like);
                    })
                    ->orWhereHas('order.products', function ($p) use ($like) {
                        $p->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('variation', 'like', $like);
                    })

                    ->orWhere('items', 'like', $like);
            });
        }

        if ($openedFrom !== '') {
            $query->whereDate('return_created_at', '>=', $openedFrom);
        }
        if ($openedTo !== '') {
            $query->whereDate('return_created_at', '<=', $openedTo);
        }

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
        }

        $returns = $query->with('order.products');

        match ($sortKey) {
            'opened_asc'   => $returns->orderBy('return_created_at'),
            'opened_desc'  => $returns->orderByDesc('return_created_at'),
            'updated_asc'  => $returns->orderBy('return_updated_at'),
            default        => $returns->orderByDesc('return_updated_at'),
        };

        $returns = $returns
            ->orderByDesc('id')
            ->paginate($perPage)
            ->appends($request->query());

        return [
            'setting'     => $settingRaw,
            'returns'     => $returns,
            'per_page'    => $perPage,
            'last_result' => session('shopee_orders_last_result'),
            'tabs'        => $tabs,
            'active_tab'  => $tab,
            'tab_counts'  => $tab_counts,
            'sort'        => $sortKey,
            'filters'     => [
                'q'           => $find,
                'opened_from' => $openedFrom,
                'opened_to'   => $openedTo,
            ],
        ];
    }
}
