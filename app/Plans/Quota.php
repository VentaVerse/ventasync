<?php

namespace App\Plans;

use Closure;
use Illuminate\Support\Facades\Cache;

final class Quota
{
    private const NOUNS = [
        'products' => ['product', 'products'],
        'users' => ['user', 'users'],
        'stores' => ['store', 'stores'],
        'api_apps' => ['app', 'apps'],
        'orders_month' => ['order a month', 'orders a month'],
    ];

    public static function refusal(string $limit): ?string
    {
        $max = Plan::limit($limit);

        if ($max === null || self::count($limit) < $max) {
            return null;
        }

        [$one, $many] = self::NOUNS[$limit] ?? [$limit, $limit];

        return 'Your plan allows ' . number_format($max) . ' ' . ($max === 1 ? $one : $many) . '.';
    }

    public static function within(string $limit, Closure $make): mixed
    {
        if (Plan::limit($limit) === null) {
            return $make();
        }

        return Cache::lock('quota:' . $limit, 30)->block(10, function () use ($limit, $make) {
            if ($full = self::refusal($limit)) {
                throw new PlanLimitReached($full);
            }

            return $make();
        });
    }

    public static function importRefusal(): ?array
    {
        $full = self::refusal('products');

        return $full === null ? null : ['ok' => false, 'message' => 'Not imported. ' . $full];
    }

    public static function refuseOrder(): void
    {
        if ($full = self::refusal('orders_month')) {
            throw new PlanLimitReached($full);
        }
    }

    public static function count(string $limit): int
    {
        return match ($limit) {
            'products' => Counters::products(),
            'users' => Counters::users(),
            'stores' => Counters::stores(),
            'api_apps' => Counters::apiApps(),
            'orders_month' => Counters::ordersThisMonth(),
            default => 0,
        };
    }

    public static function standing(string $limit): array
    {
        return [self::count($limit), Plan::limit($limit)];
    }
}
