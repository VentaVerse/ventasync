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
            'q'           => ['nullable', 'string', 'max:120'],
            'opened_from' => ['nullable', 'date'],
            'opened_to'   => ['nullable', 'date', 'after_or_equal:opened_from'],
        ]);

        /* ONE FIELD, NOT FOUR.
         *
         * This row carried a box each for return number, order number and
         * buyer, and product search would have made a fourth. The scene that
         * decides it is the one in the critique: a customer is on the phone,
         * and the agent has whatever the customer just said - a return
         * number, an order number, their own name, or "the flat patch cable".
         * Four labelled boxes ask the agent to classify that before they can
         * type it, and classifying it is the part they cannot do.
         *
         * So one box matches any of them. The cost is that the field no
         * longer names what it searches, which the placeholder carries
         * instead. */
        $find = trim((string) ($filters['q'] ?? ''));
        $openedFrom  = trim((string) ($filters['opened_from'] ?? ''));
        $openedTo    = trim((string) ($filters['opened_to'] ?? ''));

        /* Which end of the list you are looking at. The orders panel has had
           this and returns had not, so a returns list could only ever be read
           newest-touched-first - and "touched" moves every time a sync writes
           the row, which is not an order anyone asked for. Opened is the
           column an operator actually thinks in. */
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

        /* A TAB NAMES WHAT ITS ROWS SAY.
         *
         * These were written independently of the row labels in
         * ChannelStatusTone, and the two drifted: the tab "Rejected /
         * Cancelled" held rows reading "Closed", and "Under review" held rows
         * reading "Requested" while the rows that DO say "Under review"
         * (JUDGING) sat under "Disputed". Filtering by a word returned rows
         * that never used it.
         *
         * Each tab now takes the wording of the states it groups. Keys are
         * untouched - they are the wire, and no URL changes. */
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

        $region = $auth['region'] ?? 'ph';
        $query = ShopeeReturn::query()->where('region', $region);
        $baseCountQuery = ShopeeReturn::query()->where('region', $region);

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

                    /* The buyer and the products live on the order, not on
                       the return, so these reach through the relation. Both
                       raw spellings, because Shopee has used each. */
                    ->orWhereHas('order', function ($o) use ($like) {
                        $o->where('raw->buyer_username', 'like', $like)
                            ->orWhere('raw->buyer_user_name', 'like', $like);
                    })
                    ->orWhereHas('order.products', function ($p) use ($like) {
                        $p->where('name', 'like', $like)
                            ->orWhere('sku', 'like', $like)
                            ->orWhere('variation', 'like', $like);
                    })

                    /* And the return's own items, which is the only path for
                       a return whose order was never synced - there the
                       relation is null and the two whereHas above match
                       nothing. The trade-off is that this searches a JSON
                       blob, so a very short numeric term can hit an id or a
                       price rather than a product. A term worth typing at a
                       phone desk is a name or a code, and those do not
                       collide. */
                    ->orWhere('items', 'like', $like);
            });
        }

        /* Opened, not synced. whereDate so the operator's To date includes
           the whole of that day rather than stopping at midnight. */
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

        // A stable tiebreak, so two returns sharing a timestamp do not swap
        // places between pages.
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
