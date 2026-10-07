<?php

namespace Extensions\tiktok\Services;

use Extensions\tiktok\Models\TikTokReturn;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Http\Request;

class TikTokReturnsPanel
{
    public const TAB_STATUSES = [
        'PENDING' => ['RETURN_OR_REFUND_REQUEST_PENDING'],
        'RETURNING' => ['AWAITING_BUYER_SHIP', 'BUYER_SHIPPED_ITEM', 'RETURN_OR_REFUND_REQUEST_SUCCESS'],
        'REFUNDED' => ['RETURN_OR_REFUND_REQUEST_COMPLETE'],
        'REJECTED' => ['REFUND_OR_RETURN_REQUEST_REJECT', 'REJECTED'],
        'CANCELLED' => ['RETURN_OR_REFUND_REQUEST_CANCEL'],
    ];

    public const TABS = [
        'ALL' => 'All',
        'PENDING' => 'Waiting for your answer',
        'RETURNING' => 'Package returning',
        'REFUNDED' => 'Refunded',
        'REJECTED' => 'Rejected',
        'CANCELLED' => 'Cancelled by buyer',
    ];

    public const STATUS_MAP = [
        'RETURN_OR_REFUND_REQUEST_PENDING' => ['Waiting for your answer', 'warning'],
        'REFUND_OR_RETURN_REQUEST_REJECT' => ['Rejected', 'neutral'],
        'REJECTED' => ['Rejected', 'neutral'],
        'AWAITING_BUYER_SHIP' => ['Waiting for the buyer to ship', 'info'],
        'BUYER_SHIPPED_ITEM' => ['Package on its way back', 'info'],
        'RETURN_OR_REFUND_REQUEST_SUCCESS' => ['Approved', 'success'],
        'RETURN_OR_REFUND_REQUEST_COMPLETE' => ['Refunded', 'success'],
        'RETURN_OR_REFUND_REQUEST_CANCEL' => ['Cancelled by buyer', 'neutral'],
    ];

    public const TYPE_MAP = [
        'REFUND' => 'Refund only',
        'RETURN_AND_REFUND' => 'Return and refund',
        'REPLACEMENT' => 'Replacement',
    ];

    public function build(Request $request, ?int $storeId = null): array
    {
        $setting = $storeId !== null
            ? TikTokSetting::query()->find($storeId)
            : TikTokSetting::defaultStore();
        if ($storeId !== null && $setting !== null) {
            app()->instance('tiktok.route-store', $setting);
            \Illuminate\Support\Facades\URL::defaults(['store' => (int) $setting->id]);
        }

        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:120'],
            'opened_from' => ['nullable', 'date'],
            'opened_to' => ['nullable', 'date', 'after_or_equal:opened_from'],
        ]);
        $find = trim((string) ($filters['q'] ?? ''));
        $openedFrom = trim((string) ($filters['opened_from'] ?? ''));
        $openedTo = trim((string) ($filters['opened_to'] ?? ''));

        $sortKey = strtolower((string) $request->query('sort', ''));
        if (!in_array($sortKey, ['opened_desc', 'opened_asc', 'updated_desc', 'updated_asc'], true)) {
            $sortKey = 'updated_desc';
        }
        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50, 100], true)) {
            $perPage = 10;
        }
        $tab = strtoupper((string) $request->query('tab', 'ALL'));
        $tabs = self::TABS;
        if (!array_key_exists($tab, $tabs) && $tab !== 'OTHER') {
            $tab = 'ALL';
        }

        $mapped = array_values(array_unique(array_merge(...array_values(self::TAB_STATUSES))));
        $query = TikTokReturn::query();
        if ($tab === 'OTHER') {
            $query->whereNotIn('return_status', $mapped);
        } elseif ($tab !== 'ALL') {
            $query->whereIn('return_status', self::TAB_STATUSES[$tab]);
        }

        if ($find !== '') {
            $like = '%' . $find . '%';
            $query->where(function ($w) use ($like) {
                $w->where('return_id', 'like', $like)
                    ->orWhere('order_id', 'like', $like)
                    ->orWhere('items', 'like', $like);
            });
        }
        if ($openedFrom !== '') {
            $query->whereDate('return_created_at', '>=', $openedFrom);
        }
        if ($openedTo !== '') {
            $query->whereDate('return_created_at', '<=', $openedTo);
        }

        $tabCounts = ['ALL' => TikTokReturn::query()->count()];
        foreach (self::TAB_STATUSES as $key => $statuses) {
            $tabCounts[$key] = TikTokReturn::query()->whereIn('return_status', $statuses)->count();
        }
        $otherCount = TikTokReturn::query()->whereNotIn('return_status', $mapped)->count();
        if ($otherCount > 0) {
            $tabs['OTHER'] = 'Other';
            $tabCounts['OTHER'] = $otherCount;
        }

        match ($sortKey) {
            'opened_asc' => $query->orderBy('return_created_at'),
            'opened_desc' => $query->orderByDesc('return_created_at'),
            'updated_asc' => $query->orderBy('return_updated_at'),
            default => $query->orderByDesc('return_updated_at'),
        };
        $orders = $query->orderByDesc('id')->paginate($perPage)->appends($request->query());

        $orderIds = collect($orders->items())->pluck('order_id')->filter()->unique()->values();
        $erpMap = $orderIds->isEmpty() ? collect() : \DB::table('tiktok_orders')
            ->whereIn('order_id', $orderIds->all())
            ->whereNotNull('catalog_order_id')
            ->when(app()->bound('tiktok.route-store'), fn ($q) => $q->where('tiktok_setting_id', app('tiktok.route-store')->id))
            ->pluck('catalog_order_id', 'order_id');

        return [
            'orders' => $orders,
            'tabs' => $tabs,
            'active_tab' => $tab,
            'tab_counts' => $tabCounts,
            'filters' => $filters,
            'sort' => $sortKey,
            'per_page' => $perPage,
            'erpMap' => $erpMap,
            'last_result' => session('tiktok_returns_last_result'),
        ];
    }
}
