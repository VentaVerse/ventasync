<?php

namespace App\Support;

use App\Integrations\IntegrationRegistry;
use App\Models\User;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Cache;

/**
 * How many orders are waiting TO BE PACKED across every channel this user
 * can actually see, for the count beside Fulfilment in the sidebar. To pack
 * only: parcels already packed and waiting for the courier are not in it.
 *
 * The figure comes from the same place the Fulfilment page's own tab strip
 * gets it, IntegrationRegistry::visibleOrderTabs() and OrderTab's
 * unprocessedCount(), so the sidebar and the page can never disagree. That
 * also makes it dynamic for free in both the ways it has to be: a disabled
 * integration drops out of visibleOrderTabs(), and so does one whose order
 * permission this user does not hold.
 *
 * Cached because the nav renders on EVERY page. Uncached this would be one
 * COUNT query per connected channel on every request in the application, and
 * a six-channel seller pays that to look at a settings screen. The window is
 * short enough that a badge is never meaningfully out of date, and long
 * enough that clicking through a few pages costs nothing.
 */
class FulfilmentBadge
{
    /** Seconds. Short: a stale count on a working surface is worse than a query. */
    public const TTL = 60;

    public static function pending(Authenticatable|User|null $user): int
    {
        return array_sum(self::byStore($user));
    }

    /**
     * The same count per Fulfilment tab, keyed by the tab's store key
     * ("shopee:1"), for the tabs this user can see.
     *
     * @return array<string, int>
     */
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

    /**
     * Drop whatever this user has cached, for when waiting a minute is not
     * acceptable: an integration was just enabled, or an order was just
     * processed and the operator is looking straight at the number.
     */
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

    /** @return array<string, int> */
    protected static function compute(Authenticatable|User $user): array
    {
        try {
            $counts = [];

            foreach (app(IntegrationRegistry::class)->visibleOrderTabs($user) as $tab) {
                // A channel that names its stages counts its first, To Pack;
                // one that does not keeps its whole waiting figure. A counter
                // never implemented counts as zero.
                $stages = $tab->stageCounts();
                $count = $stages ? ($stages[0]['count'] ?? 0) : ($tab->unprocessedCount() ?? 0);
                $counts[$tab->id] = max(0, (int) $count);
            }

            return $counts;
        } catch (\Throwable) {
            // A marketplace with an expired token or a missing table must not
            // take down the sidebar, and with it every page in the app. No
            // badge is the honest failure here.
            return [];
        }
    }
}
