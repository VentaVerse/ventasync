<?php

namespace App\Support;

use App\Integrations\IntegrationRegistry;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

class FulfilmentBadge
{
    public const TTL = 60;

    public static function pending(Authenticatable|User|null $user): int
    {
        return array_sum(self::byStore($user));
    }

    public static function byStore(Authenticatable|User|null $user): array
    {
        if ($user === null) {
            return [];
        }

        return (array) Cache::remember(
            self::key($user),
            self::TTL,
            fn () => self::compute($user),
        );
    }

    public static function forget(Authenticatable|User|null $user): void
    {
        if ($user !== null) {
            Cache::forget(self::key($user));
        }
    }

    private static function key(Authenticatable|User $user): string
    {
        return 'fulfilment.to_pack.' . $user->getAuthIdentifier();
    }

    protected static function compute(Authenticatable|User $user): array
    {
        try {
            $counts = [];

            foreach (app(IntegrationRegistry::class)->visibleOrderTabs($user) as $tab) {
                $stages = $tab->stageCounts();
                $count = $stages ? ($stages[0]['count'] ?? 0) : ($tab->unprocessedCount() ?? 0);
                $counts[$tab->id] = max(0, (int) $count);
            }

            return $counts;
        } catch (\Throwable) {
            return [];
        }
    }
}
