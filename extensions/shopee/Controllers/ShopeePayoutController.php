<?php

namespace Extensions\shopee\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Payouts\StorePayoutsPage;
use Extensions\shopee\Services\Shopee\ShopeeBalance;
use Extensions\shopee\Services\Shopee\ShopeePayoutSync;

/**
 * A Shopee store's Payouts page, under Sell (the owner, 2026-10-01): the money
 * at Shopee now, and the orders Shopee has not truly paid for. Its own
 * permission (shopee/payout), because what a store earns is not every
 * order clerk's business. A turned-off store has no page.
 */
class ShopeePayoutController extends Controller
{
    public function index(StorePayoutsPage $page, ShopeeBalance $balance)
    {
        $store = app()->bound('shopee.route-store') ? app('shopee.route-store') : null;
        abort_unless($store && $store->enabled, 404);

        return $page->view(ShopeePayoutSync::attention(), $store, 'Shopee', fn () => $balance->read($store));
    }
}
