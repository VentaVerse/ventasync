<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Payouts\PayoutRegistry;
use App\Services\Payouts\StorePayoutsPage;

/**
 * A Shopee store's Payouts page, under Sell (the owner, 2026-10-01): the money
 * at Shopee now, and the orders it has not truly paid for. Its own
 * permission (shopee/payout), because what a store earns is not every order
 * clerk's business. A turned-off store has no page.
 */
class ShopeePayoutController extends Controller
{
    public function index(StorePayoutsPage $page, PayoutRegistry $registry)
    {
        $store = app()->bound('shopee.route-store') ? app('shopee.route-store') : null;
        $channel = $registry->channel('shopee');
        abort_unless($store && $store->enabled && $channel, 404);

        return $page->view($channel, $store);
    }
}
