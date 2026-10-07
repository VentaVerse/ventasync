<?php

namespace App\Integrations;

use Closure;

class OrderTab
{
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
        public ?array $stages = null,
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

    public function stageCounts(): ?array
    {
        if ($this->stages === null) {
            return null;
        }
        $out = [];
        foreach ($this->stages as $stage) {
            $out[] = ['key' => (string) $stage['key'], 'label' => (string) $stage['label'], 'count' => (int) ($stage['counter'])()];
        }

        return $out;
    }
}
