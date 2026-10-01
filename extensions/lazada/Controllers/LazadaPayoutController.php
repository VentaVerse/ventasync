<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Payouts\StorePayoutsPage;
use Extensions\lazada\Services\Lazada\LazadaBalance;
use Extensions\lazada\Services\Lazada\LazadaPayoutSync;

/**
 * A Lazada store's Payouts page, under Sell (the owner, 2026-10-01): the money
 * at Lazada now, and the orders Lazada has not truly paid for. Its own
 * permission (lazada/payout). A turned-off store has no page.
 */
class LazadaPayoutController extends Controller
{
    public function index(StorePayoutsPage $page, LazadaBalance $balance)
    {
        $store = app()->bound('lazada.route-store') ? app('lazada.route-store') : null;
        abort_unless($store && $store->enabled, 404);

        return $page->view(LazadaPayoutSync::attention(), $store, 'Lazada', fn () => $balance->read($store));
    }
}
