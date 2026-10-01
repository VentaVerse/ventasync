<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Payouts\PayoutRegistry;
use App\Services\Payouts\StorePayoutsPage;

/**
 * A TikTok Shop store's Payouts page, under Sell (the owner, 2026-10-01): the money
 * at TikTok Shop now, and the orders it has not truly paid for. Its own
 * permission (tiktok/payout), because what a store earns is not every order
 * clerk's business. A turned-off store has no page.
 */
class TikTokPayoutController extends Controller
{
    public function index(StorePayoutsPage $page, PayoutRegistry $registry)
    {
        $store = app()->bound('tiktok.route-store') ? app('tiktok.route-store') : null;
        $channel = $registry->channel('tiktok');
        abort_unless($store && $store->enabled && $channel, 404);

        return $page->view($channel, $store);
    }
}
