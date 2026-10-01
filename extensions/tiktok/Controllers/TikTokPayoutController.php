<?php

namespace Extensions\tiktok\Controllers;

use App\Http\Controllers\Controller;
use App\Services\Payouts\StorePayoutsPage;
use App\Support\PayoutStatus;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Services\TikTok\TikTokBalance;
use Extensions\tiktok\Services\TikTok\TikTokPayoutSync;

/**
 * A TikTok Shop store's Payouts page, under Sell (the owner, 2026-10-01): the
 * money at TikTok now, and the orders TikTok has not truly paid for. Its
 * own permission (tiktok/payout). A turned-off store has no page.
 */
class TikTokPayoutController extends Controller
{
    public function index(StorePayoutsPage $page, TikTokBalance $balance)
    {
        $store = app()->bound('tiktok.route-store') ? app('tiktok.route-store') : null;
        abort_unless($store && $store->enabled, 404);

        // Delivered or completed, in no statement yet, and not given up on.
        $toSettle = TikTokOrder::query()
            ->where('tiktok_setting_id', $store->id)
            ->whereIn('status', TikTokPayoutSync::PAYABLE_STATUSES)
            ->where(fn ($q) => $q->whereNull('payout_status')
                ->orWhereNotIn('payout_status', [PayoutStatus::PAID, PayoutStatus::NO_PAYOUT, PayoutStatus::FAILED]))
            ->count();

        return $page->view(TikTokPayoutSync::attention(), $store, 'TikTok Shop', fn () => $balance->read($store), [
            ['label' => 'To settle', 'text' => $toSettle === 1 ? '1 order' : number_format($toSettle) . ' orders'],
        ]);
    }
}
