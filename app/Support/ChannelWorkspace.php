<?php

namespace App\Support;

use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
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
     * Mirrors IntegrationController@index's $deriveState closure exactly:
     * setup when the token is missing or expired, attention when it expires
     * within 7 days or the last sync is older than 24 hours, connected
     * otherwise. Two divergent definitions of "connected" in one product is
     * a defect, so this reads the same thresholds and the same underlying
     * figures rather than inventing its own.
     */
    public function state(IntegrationCard $card): string
    {
        $figures = $this->tokenFigures($card->id);

        $now = Carbon::now();
        $staleAfter = $now->copy()->subHours(24);
        $expiringWithin = $now->copy()->addDays(7);

        if ($figures['hasToken'] === false) {
            return 'setup';
        }
        if ($figures['tokenExpiresAt'] && $figures['tokenExpiresAt']->isPast()) {
            return 'setup';
        }

        $needsAttention = ($figures['tokenExpiresAt'] && $figures['tokenExpiresAt']->lessThanOrEqualTo($expiringWithin))
            || (! $figures['lastSyncAt'] || $figures['lastSyncAt']->lessThan($staleAfter));

        return $needsAttention ? 'attention' : 'connected';
    }

    /**
     * Health rows for the Overview page: Last sync, Token expiry, and API
     * errors in 24 hours. A null value means the figure genuinely is not
     * tracked (no token/expiry column for this channel, or api_logging is
     * switched off on its settings row) - the view renders "Not tracked"
     * rather than a fabricated zero, since a real zero would read as "no
     * errors" when it actually means "never measured".
     *
     * @return array<int, array{label: string, value: ?string}>
     */
    public function waiting(IntegrationCard $card, ?User $user): array
    {
        $tab = null;

        // visibleOrderTabs() already applies the same permission gate the
        // Orders workspace uses, so a user who cannot see this channel's
        // orders gets no counts rather than a gap in the gate.
        foreach ($this->registry->visibleOrderTabs($user) as $candidate) {
            if ($candidate->id === $card->id) {
                $tab = $candidate;
                break;
            }
        }

        if (! $tab) {
            return [];
        }

        // These closures are the same ones the Orders summary page reads, so
        // the workspace cannot disagree with it about how many orders are
        // waiting. A null closure means the channel never implemented that
        // metric, which is "not tracked" rather than zero.
        return [
            [
                'label' => 'Orders to process',
                'value' => $tab->unprocessedCounter
                    ? number_format((int) ($tab->unprocessedCounter)())
                    : null,
            ],
            [
                'label' => 'Orders today',
                'value' => $tab->dailyOrdersCounter
                    ? number_format((int) ($tab->dailyOrdersCounter)())
                    : null,
            ],
            [
                'label' => 'Revenue today',
                'value' => $tab->dailyRevenueCounter
                    ? Money::base((float) ($tab->dailyRevenueCounter)())
                    : null,
            ],
        ];
    }

    public function health(IntegrationCard $card): array
    {
        $figures = $this->tokenFigures($card->id);

        return [
            ['label' => 'Last sync', 'value' => $figures['lastSyncAt']?->diffForHumans()],
            ['label' => 'Token expiry', 'value' => $figures['tokenExpiresAt']?->diffForHumans()],
            ['label' => 'API errors in 24 hours', 'value' => $figures['errors24h'] === null ? null : (string) $figures['errors24h']],
        ];
    }

    /**
     * The workspace's own most-needed action, per spec 3.4: Connect when the
     * channel needs setup, otherwise the same secondary action the status
     * board already offers for it (IntegrationController@index's "View
     * orders" button, sourced from the card's own "Orders" menu item) -
     * never both, so the page is never asking for two things at once.
     *
     * The Connect destination is channel-specific and today only exists for
     * Shopee (ext.shopee.authorize). That route is deliberately absent from
     * the sidebar - a nav item that redirects the user off-site to Shopee's
     * own consent screen is a hostile control there - but is exactly what a
     * first-time visitor to this page needs. Wiring Lazada/TikTok's own
     * OAuth start routes here is a follow-up alongside grouping their menus
     * (see ChannelWorkspace::groups() and Task 6 of this plan).
     *
     * @param array<int, array{label: string, items: array<int, array{label: string, route: string, url: string}>}> $groups
     * @return array{label: string, url: string}|null
     */
    public function action(IntegrationCard $card, string $state, array $groups): ?array
    {
        if ($state === 'setup') {
            if ($card->id === 'shopee' && Route::has('ext.shopee.authorize')) {
                return ['label' => 'Connect', 'url' => route('ext.shopee.authorize')];
            }

            return null;
        }

        foreach ($groups as $group) {
            foreach ($group['items'] as $item) {
                if ($item['label'] === 'Orders') {
                    return ['label' => 'View orders', 'url' => $item['url']];
                }
            }
        }

        return null;
    }

    /**
     * The raw token/sync figures IntegrationController@index reads for the
     * single-store OAuth marketplaces (Shopee, Lazada, TikTok) - same table
     * shape, same columns, same sandbox/live selection by `mode`.
     *
     * Venta, OpenCart, Shopify and Pedallion are deliberately not handled
     * here: they are multi-store or API-key integrations with no single
     * settings row that represents "the card" (IntegrationController computes
     * their health per STORE for exactly that reason), and none of them has
     * a token-expiry column at all. Rather than pick an arbitrary row or
     * invent a column, every figure reads as not tracked for those channels
     * until they get their own workspace grouping - recorded as a follow-up,
     * not fixed here, per this task's "no migration" constraint.
     *
     * @return array{hasToken: ?bool, tokenExpiresAt: ?Carbon, lastSyncAt: ?Carbon, errors24h: ?int}
     */
    private function tokenFigures(string $channelId): array
    {
        if (! in_array($channelId, ['shopee', 'lazada', 'tiktok'], true)) {
            return ['hasToken' => null, 'tokenExpiresAt' => null, 'lastSyncAt' => null, 'errors24h' => null];
        }

        $row = DB::table($channelId . '_settings')->first();
        $isSandbox = ($row?->mode ?? 'live') === 'sandbox';

        $expiresRaw = $row ? ($isSandbox ? $row->sandbox_expires_at : $row->expires_at) : null;
        $lastSyncRaw = $row?->last_order_sync_at;
        $loggingOn = (bool) ($row?->api_logging ?? false);

        return [
            'hasToken' => $row ? ! empty($isSandbox ? $row->sandbox_access_token : $row->access_token) : false,
            'tokenExpiresAt' => $expiresRaw ? Carbon::parse($expiresRaw) : null,
            'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
            // api_logging is off in this sandbox for every channel today, so
            // this reads null (not tracked), not a fabricated zero.
            'errors24h' => $loggingOn
                ? DB::table($channelId . '_api_logs')->where('created_at', '>=', Carbon::now()->subHours(24))->where('ok', 0)->count()
                : null,
        ];
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
