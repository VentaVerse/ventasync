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
        if ($user === null) {
            return 0;
        }

        return (int) Cache::remember(
            'fulfilment.pending.' . $user->getAuthIdentifier(),
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
            Cache::forget('fulfilment.pending.' . $user->getAuthIdentifier());
        }
    }

    protected static function compute(Authenticatable|User $user): int
    {
        try {
            $total = 0;

            foreach (app(IntegrationRegistry::class)->visibleOrderTabs($user) as $tab) {
                /* TO PACK ONLY (the owner, 2026-08-27): the badge is the floor's
                 * "how much is untouched", so a parcel already packed and
                 * waiting for the courier does not count against it. A
                 * channel that names its stages gives its first, To Pack;
                 * one that does not keeps the whole waiting figure, which
                 * is all it knows. null means the counter was never
                 * implemented, which shows the same as zero. */
                $stages = $tab->stageCounts();
                $count = $stages ? ($stages[0]['count'] ?? 0) : ($tab->unprocessedCount() ?? 0);
                $total += max(0, (int) $count);
            }

            return $total;
        } catch (\Throwable) {
            // A marketplace with an expired token or a missing table must not
            // take down the sidebar, and with it every page in the app. No
            // badge is the honest failure here.
            return 0;
        }
    }
}
