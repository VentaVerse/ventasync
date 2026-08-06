<?php

namespace App\Support;

use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Models\User;
use Illuminate\Support\Facades\Route;

/**
 * Builds a channel's workspace sidebar from the IntegrationRegistry.
 *
 * The registry is the single source of truth for what a channel contains.
 * This class filters that menu down to what the current user can actually
 * reach and buckets it into the three fixed groups every channel shares.
 */
class ChannelWorkspace
{
    /** Fixed across every channel. A channel with nothing in a group omits it. */
    public const GROUPS = ['Sell', 'Catalogue', 'Channel'];

    public function __construct(private IntegrationRegistry $registry)
    {
    }

    /**
     * The card for a channel, or null when the channel does not exist or the
     * user cannot see it. visibleCards() already applies the card-level
     * permission gate, so an unauthorised channel is indistinguishable from
     * an unknown one, which is what we want for a 404.
     */
    public function card(string $channel, ?User $user): ?IntegrationCard
    {
        foreach ($this->registry->visibleCards($user) as $card) {
            if ($card->id === $channel) {
                return $card;
            }
        }

        return null;
    }

    /**
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string, url: string}>}>
     */
    public function groups(IntegrationCard $card, ?User $user): array
    {
        $buckets = array_fill_keys(self::GROUPS, []);

        foreach ($card->menu as $item) {
            if (! $this->allows($item, $user)) {
                continue;
            }

            // A null or unrecognised group falls under Channel rather than
            // vanishing. An item registered before workspaces existed should
            // still be reachable.
            $group = in_array($item->group, self::GROUPS, true) ? $item->group : 'Channel';

            $buckets[$group][] = [
                'label' => $item->label,
                'route' => $item->routeName,
                'url' => route($item->routeName, $item->routeParams),
            ];
        }

        $out = [];

        foreach (self::GROUPS as $group) {
            if ($buckets[$group] !== []) {
                $out[] = ['label' => $group, 'items' => $buckets[$group]];
            }
        }

        return $out;
    }

    /**
     * Both gates must pass: the permission the registry declares on the item,
     * and the permission the destination route declares in its own perm:
     * middleware. The route is the authority on what it demands, so a
     * registry entry cannot grant more than the route allows.
     *
     * Never can(): this app registers no Gates or Policies, so can() returns
     * false for everyone including admins.
     */
    public function allows(MenuItem $item, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (! Route::has($item->routeName)) {
            return false;
        }

        $route = Route::getRoutes()->getByName($item->routeName);

        // Route::has() proves existence, not linkability. Every REQUIRED URI
        // parameter must be supplied, or route() throws UrlGenerationException
        // and takes down every page rendering this sidebar rather than hiding
        // one link. {store} is required; {store?} is not.
        preg_match_all('/\{([^}?]+)\}/', $route->uri(), $matches);

        foreach ($matches[1] as $parameter) {
            if (! array_key_exists($parameter, $item->routeParams)) {
                return false;
            }
        }

        if ($item->permission !== null && ! $this->holdsAny($user, $item->permission)) {
            return false;
        }

        $routePermission = Navigation::permissionFor($item->routeName);

        if ($routePermission !== null && ! $this->holdsAny($user, $routePermission)) {
            return false;
        }

        return true;
    }

    /** Same pipe-delimited any-match parsing EnsurePermission uses. */
    private function holdsAny(User $user, string $permission): bool
    {
        foreach (explode('|', $permission) as $key) {
            if ($user->hasPermission(trim($key))) {
                return true;
            }
        }

        return false;
    }
}
