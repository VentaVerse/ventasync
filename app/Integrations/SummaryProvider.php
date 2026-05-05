<?php

namespace App\Integrations;

use App\Integrations\Dto\RecentOrder;
use App\Integrations\Dto\TopProduct;

/**
 * Optional sub-contract for integrations that can contribute to the
 * Orders summary page (status cards, top-products leaderboard, recent
 * activity feed).
 *
 * Integrations that don't implement this interface simply don't appear
 * in those summary blocks. Implementing it is opt-in, so utility-style
 * extensions (purchasing, warehousing) aren't forced to provide order
 * data they don't have.
 */
interface SummaryProvider extends IntegrationProvider
{
    /**
     * Orders received today (in the configured app timezone).
     */
    public function dailyOrdersCount(): int;

    /**
     * Sum of order totals received today, in PHP base currency where
     * applicable (or this marketplace's reporting currency — the summary
     * page renders without unit conversion).
     */
    public function dailyRevenue(): float;

    /**
     * Top products sold today, ranked by quantity sold.
     *
     * @return TopProduct[]
     */
    public function topProductsToday(int $limit = 5): array;

    /**
     * Most recent orders for this marketplace, newest first.
     *
     * @return RecentOrder[]
     */
    public function recentOrders(int $limit = 5): array;
}
