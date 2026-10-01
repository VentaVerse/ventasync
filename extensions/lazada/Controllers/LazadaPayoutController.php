<?php

namespace Extensions\lazada\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Payouts\PayoutRegistry;
use App\Services\Payouts\StorePayoutsPage;

/**
 * A Lazada store's Payouts page, under Sell (the owner, 2026-10-01): the money
 * at Lazada now, and the orders it has not truly paid for. Its own
 * permission (lazada/payout), because what a store earns is not every order
 * clerk's business. A turned-off store has no page.
 */
class LazadaPayoutController extends Controller
{
    public function index(StorePayoutsPage $page, PayoutRegistry $registry)
    {
        $store = app()->bound('lazada.route-store') ? app('lazada.route-store') : null;
        $channel = $registry->channel('lazada');
        abort_unless($store && $store->enabled && $channel, 404);

        return $page->view($channel, $store);
    }
}
