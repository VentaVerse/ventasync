<?php

namespace Extensions\shopee\Services;

use Extensions\shopee\Models\ShopeeReturn;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Http\Request;

/**
 * Assembles the view data for the Shopee returns list: filters, tab counts
 * and pagination.
 *
 * The exact counterpart of ShopeeOrdersPanel, and extracted for the same
 * reason. Returns used to be reachable only as its own seller-centre page,
 * so the "Returns and refunds" link on the fulfilment hub threw the operator
 * out of the hub and into the channel workspace - a different shell, with a
 * different sidebar, from a link that looked like every other filter beside
 * it. A service plus a partial lets one returns list render in both places,
 * which is what the orders list already does.
 *
 * Lifted from ShopeeOrderController@returns without a logic change.
 */
class ShopeeReturnsPanel
{
    public function build(Request $request): array
    {
        $settingRaw = ShopeeSetting::query()->first();
        $setting = $settingRaw?->decrypted();
        $auth = ShopeeSetting::activeAuth($setting);

        $filters = $request->validate([
            'return_sn' => ['nullable', 'string', 'max:80'],
            'order_sn'  => ['nullable', 'string', 'max:80'],
        ]);

        $returnSn = trim((string) ($filters['return_sn'] ?? ''));
        $orderSn  = trim((string) ($filters['order_sn'] ?? ''));

        $perPage = (int) $request->query('per_page', 10);
        if (!in_array($perPage, [10, 20, 50], true)) {
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
            'REQUESTED' => 'Under Review',
            'ACCEPTED'  => 'Returning',
            'REFUND'    => 'Refunded',
            'DISPUTE'   => 'Disputed',
            'CLOSED'    => 'Rejected / Cancelled',
        ];

        if (!array_key_exists($tab, $tabs)) {
            $tab = 'ALL';
        }

        $region = $auth['region'] ?? 'ph';
        $query = ShopeeReturn::query()->where('region', $region);
        $baseCountQuery = ShopeeReturn::query()->where('region', $region);

        if ($tab !== 'ALL') {
            $statuses = $tabStatusMap[$tab] ?? [];
            if (!empty($statuses)) {
                $query->whereIn('status', $statuses);
            }
        }

        if ($returnSn !== '') {
            $query->where('return_sn', 'like', '%' . $returnSn . '%');
        }
        if ($orderSn !== '') {
            $query->where('order_sn', 'like', '%' . $orderSn . '%');
        }

        $tab_counts = [];
        $tab_counts['ALL'] = (clone $baseCountQuery)->count();
        foreach ($tabStatusMap as $k => $statuses) {
            $tab_counts[$k] = (clone $baseCountQuery)->whereIn('status', $statuses)->count();
        }

        $returns = $query
            ->with('order.products')
            ->orderByDesc('return_updated_at')
            ->orderByDesc('created_at')
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
            'filters'     => [
                'return_sn' => $returnSn,
                'order_sn'  => $orderSn,
            ],
        ];
    }
}
