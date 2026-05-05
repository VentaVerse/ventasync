<?php

namespace App\Integrations;

use Closure;

/**
 * One fulfillable surface for a marketplace integration. Single-store
 * marketplaces register one tab; multi-store marketplaces (Venta, OpenCart)
 * register one tab per store, scoping every counter to that store.
 *
 * The tab carries all the data the Orders summary page and the tab strip
 * need — unprocessed-order count, today's order count, today's revenue,
 * top products today, and the recent activity feed — as nullable closures.
 * Marketplaces that haven't implemented a particular metric simply leave
 * the closure null; the UI renders zeros / empty states accordingly.
 */
class OrderTab
{
    /**
     * @param Closure():int|null $unprocessedCounter
     * @param Closure():int|null $dailyOrdersCounter
     * @param Closure():float|null $dailyRevenueCounter
     * @param Closure(int):array|null $topProductsCallback Returns Dto\TopProduct[]
     * @param Closure(int):array|null $recentOrdersCallback Returns Dto\RecentOrder[]
     */
    public function __construct(
        public string $id,
        public string $label,
        public string $icon,
        public string $accent,
        public string $routeName,
        public string $permission,
        public array $routeParams = [],
        public ?Closure $unprocessedCounter = null,
        public ?Closure $dailyOrdersCounter = null,
        public ?Closure $dailyRevenueCounter = null,
        public ?Closure $topProductsCallback = null,
        public ?Closure $recentOrdersCallback = null,
    ) {
    }

    public function unprocessedCount(): ?int
    {
        return $this->safeCall($this->unprocessedCounter, fn ($v) => (int) $v);
    }

    public function dailyOrdersCount(): int
    {
        return $this->safeCall($this->dailyOrdersCounter, fn ($v) => (int) $v) ?? 0;
    }

    public function dailyRevenue(): float
    {
        return $this->safeCall($this->dailyRevenueCounter, fn ($v) => (float) $v) ?? 0.0;
    }

    /** @return \App\Integrations\Dto\TopProduct[] */
    public function topProductsToday(int $limit = 5): array
    {
        if ($this->topProductsCallback === null) {
            return [];
        }
        try {
            $rows = ($this->topProductsCallback)($limit);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    /** @return \App\Integrations\Dto\RecentOrder[] */
    public function recentOrders(int $limit = 5): array
    {
        if ($this->recentOrdersCallback === null) {
            return [];
        }
        try {
            $rows = ($this->recentOrdersCallback)($limit);
            return is_array($rows) ? $rows : [];
        } catch (\Throwable) {
            return [];
        }
    }

    private function safeCall(?Closure $fn, Closure $cast): mixed
    {
        if ($fn === null) {
            return null;
        }
        try {
            return $cast($fn());
        } catch (\Throwable) {
            return null;
        }
    }
}
