<?php

namespace App\Support;

use App\Integrations\IntegrationCard;
use App\Integrations\IntegrationRegistry;
use App\Integrations\MenuItem;
use App\Integrations\StoreLink;
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
     *
     * $channel is a workspace key, which for a multi-store channel carries
     * the store: `venta:2`. See resolve() for what that accepts.
     */
    public function card(string $channel, ?User $user): ?IntegrationCard
    {
        return $this->resolve($this->registry->visibleCards($user), $channel);
    }

    /**
     * The card for a channel whose page the ROUTE has already authorised.
     *
     * Same lookup as card(), minus the card-level permission gate. The
     * workspace entry point (/integrations/{channel}) keeps card(), because
     * there "may this user open this channel" is exactly the question being
     * asked and there is no other gate. The layout composer must not ask it a
     * second time: a user holding only view_shopee_orders reaches
     * /shopee/orders through perm:view_shopee_orders|manage_shopee_orders,
     * and the Shopee card declares view_shopee, which this user does not hold
     * because this app removed parent/child permission cascades. Re-gating
     * there 404s a page the route authorised, which is what it did before
     * this fix.
     *
     * The sidebar this feeds is still filtered per item by groups() ->
     * allows(), so a narrow user gets a correspondingly narrow sidebar rather
     * than links they cannot follow.
     */
    public function cardForAuthorisedPage(string $channel, ?User $user): ?IntegrationCard
    {
        return $this->resolve($this->registry->cardsForAuthorisedRequest($user), $channel);
    }

    /**
     * Find the card a workspace key names, or null when the key names
     * nothing this list holds.
     *
     * A key is either a channel id (`shopee`) or a channel id and a store
     * (`venta:2`), which is the format StoreLink::$id and OrderTab::$id
     * already use, so the same string identifies a store here, on the
     * Fulfilment hub and on the status board.
     *
     * Everything that does not resolve to a real destination returns null,
     * and never throws, because every caller turns null into a 404:
     *  - an unknown channel, or one this list has gated away
     *  - a bare `venta` on a multi-store channel: every one of its routes is
     *    store-scoped, so there is no storeless page to land on
     *  - a store that does not exist on this card (`venta:999`)
     *  - a store suffix on a single-store channel (`shopee:2`)
     *  - a malformed key (`venta:`, `venta:abc`, `venta:2:3`, `venta:0`)
     *
     * @param IntegrationCard[] $cards
     */
    private function resolve(array $cards, string $channel): ?IntegrationCard
    {
        $parsed = $this->parseKey($channel);

        if ($parsed === null) {
            return null;
        }

        [$channelId, $storeId] = $parsed;

        $card = null;

        foreach ($cards as $candidate) {
            if ($candidate->id === $channelId) {
                $card = $candidate;
                break;
            }
        }

        if (! $card) {
            return null;
        }

        // No stores on this card: either a single-store channel, or a
        // multi-store one with nothing configured yet. Either way a store
        // suffix names something that does not exist. A bare key is fine
        // here; hasWorkspace() is what then decides whether a storeless
        // channel has a workspace at all.
        if ($card->stores === []) {
            return $storeId === null ? $card : null;
        }

        return $this->storeOn($card, $storeId) ? $card : null;
    }

    /**
     * A workspace key in URL form: `venta:2` -> `venta/2`, `shopee` -> `shopee`.
     *
     * IA migration (2026-08-08, Task 2), spec decision 3. The colon key is
     * this application's internal identity for a store workspace - StoreLink
     * ids, OrderTab ids, the hub's `channel=` parameter and everything
     * parseKey() reads all speak it, and none of that changes. The URL is the
     * one place it does not belong: an address bar is a navigation trail, and
     * `venta:2` reads as a scheme, not as a store inside a channel.
     *
     * So the translation lives here, at the single boundary between the two,
     * rather than as a str_replace scattered over four call sites that would
     * drift apart the first time one of them was missed.
     */
    public static function pathKey(string $key): string
    {
        return str_replace(':', '/', $key);
    }

    /** Inverse of pathKey(): the URL form back to the internal colon key. */
    public static function keyFromPath(string $path): string
    {
        return str_replace('/', ':', $path);
    }

    /**
     * The workspace Overview URL for a colon key.
     *
     * Every caller that links to a seller center goes through this, so no
     * caller has to remember that the URL and the key differ. Route NAME is
     * unchanged (frozen); only the generated path shape moved.
     */
    public static function overviewUrl(string $key): string
    {
        return route('integrations.workspace', ['channel' => self::pathKey($key)]);
    }

    /**
     * Split a workspace key into its channel id and its optional store id.
     *
     * Deliberately strict. The store id reaches a database lookup and a
     * route parameter, so anything that is not a positive integer is
     * rejected here rather than coerced: `venta:abc` would otherwise read as
     * store 0, and `venta:2:3` as store 2, both of which silently serve a
     * page for a key the operator did not ask for.
     *
     * @return array{0: string, 1: ?int}|null
     */
    private function parseKey(string $channel): ?array
    {
        if ($channel === '') {
            return null;
        }

        if (! str_contains($channel, ':')) {
            return [$channel, null];
        }

        $parts = explode(':', $channel);

        if (count($parts) !== 2) {
            return null;
        }

        [$channelId, $storeId] = $parts;

        if ($channelId === '' || ! ctype_digit($storeId) || (int) $storeId <= 0) {
            return null;
        }

        return [$channelId, (int) $storeId];
    }

    /** The StoreLink this card carries for that numeric store id, if any. */
    private function storeOn(IntegrationCard $card, ?int $storeId): ?StoreLink
    {
        if ($storeId === null) {
            return null;
        }

        foreach ($card->stores as $store) {
            if ($store->id === $card->id . ':' . $storeId) {
                return $store;
            }
        }

        return null;
    }

    /**
     * The store a workspace key names on this card, or null when the key is
     * bare, malformed, or for a different channel. Public because the layout
     * composer needs the store's label for the sidebar head and breadcrumb:
     * "which store am I in" is the first question a multi-store workspace
     * has to answer on screen.
     */
    public function store(IntegrationCard $card, ?string $channel): ?StoreLink
    {
        if ($channel === null) {
            return null;
        }

        $parsed = $this->parseKey($channel);

        if ($parsed === null || $parsed[0] !== $card->id) {
            return null;
        }

        return $this->storeOn($card, $parsed[1]);
    }

    /**
     * The menu a workspace key's sidebar is built from: the store's own menu
     * for a store key, the card's menu otherwise.
     *
     * A multi-store channel keeps its real destinations on the STORE menus,
     * each already carrying its ['store' => id] params, so scoping is a
     * matter of reading the right menu rather than rewriting params.
     *
     * @return MenuItem[]
     */
    private function menuFor(IntegrationCard $card, ?string $channel): array
    {
        return $this->store($card, $channel)?->menu ?? $card->menu;
    }

    /**
     * Whether this channel has been converted to a workspace.
     *
     * The honest signal is the card declaring at least one MenuItem with a
     * non-null group, because grouping the menu is precisely the conversion
     * work. Every channel qualifies as of Round F: the last two, Pedallion
     * and Shopify, converted with this round.
     *
     * groups() is not that signal and must not be used as one: it buckets an
     * ungrouped item into 'Channel' rather than dropping it, so it is
     * non-empty for every channel with any parameterless menu item. Reading
     * it that way linked every then-unconverted channel into a half-built
     * workspace, and cost the multi-store ones their per-store destinations
     * entirely, since those live on store cards and a workspace sidebar built
     * from the top card never shows them.
     */
    public function hasWorkspace(IntegrationCard $card): bool
    {
        // A multi-store channel keeps its pages on the STORE menus; the top
        // card carries only whatever is global to the channel (OpenCart's
        // Reviews, which stays out of every store workspace). Reading the
        // card menu here would call a converted channel unconverted, and
        // building a sidebar from it would lose every per-store destination.
        //
        // A card with no stores configured is not converted either: there is
        // nothing to open. The board sends those to the module page, where
        // the operator can create the first store.
        if ($card->stores !== []) {
            foreach ($card->stores as $store) {
                foreach ($store->menu as $item) {
                    if ($item->group !== null) {
                        return true;
                    }
                }
            }

            return false;
        }

        foreach ($card->menu as $item) {
            if ($item->group !== null) {
                return true;
            }
        }

        return false;
    }

    /**
     * Mirrors IntegrationController@index's $deriveState closure exactly:
     * setup when the token is missing or expired, attention when it expires
     * within 7 days or the last sync is older than 24 hours, connected
     * otherwise. Two divergent definitions of "connected" in one product is
     * a defect, so this reads the same thresholds and the same underlying
     * figures rather than inventing its own.
     *
     * $channel scopes the reading to one store on a multi-store channel,
     * which is the only level those channels track health at: their settings
     * rows ARE their stores.
     */
    public function state(IntegrationCard $card, ?string $channel = null): string
    {
        $store = $this->store($card, $channel);

        // Same first test the board applies to a store row: a store created
        // but not yet enabled needs setting up, whatever its columns hold.
        if ($store && $store->status === 'setup_pending') {
            return 'setup';
        }

        $figures = $this->tokenFigures($card->id, $this->storeIdOf($store));

        $now = Carbon::now();
        $staleAfter = $now->copy()->subHours(24);
        $expiringWithin = $now->copy()->addDays(7);

        // Nothing measurable for this channel: no token state and no last
        // sync. Every test below would then be answering a question the data
        // cannot answer - in particular `! $lastSyncAt` is true when the
        // figure is simply not tracked, which reported "Needs attention" for
        // a healthy OpenCart store that the status board on the same screen
        // called "Connected". Say what is true instead: not tracked. This is
        // the same reading the Overview's health strip already gives these
        // channels, so the two now agree.
        //
        // Shopee, Lazada and TikTok never reach this: tokenFigures() returns
        // a real boolean hasToken for them even when their settings row is
        // missing, so Shopee's behaviour is unchanged.
        if ($figures['hasToken'] === null && $figures['lastSyncAt'] === null) {
            return 'untracked';
        }

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
    public function waiting(IntegrationCard $card, ?User $user, ?string $channel = null): array
    {
        $tab = null;

        // A multi-store channel registers one OrderTab per store under the
        // same `venta:2` id this key uses, and each tab's counters are
        // already scoped to that store's marketplace_source. Matching on the
        // store key is therefore the whole of the store scoping here: match
        // on the card id instead and a two-store seller would read one
        // store's figures under both stores' names.
        //
        // Only ENABLED stores register a tab, so a store that is still being
        // set up reports nothing waiting rather than a fabricated zero.
        $wanted = $this->store($card, $channel)?->id ?? $card->id;

        // visibleOrderTabs() already applies the same permission gate the
        // Orders workspace uses, so a user who cannot see this channel's
        // orders gets no counts rather than a gap in the gate.
        foreach ($this->registry->visibleOrderTabs($user) as $candidate) {
            if ($candidate->id === $wanted) {
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

    /**
     * Each row carries a tone as well as a reading, because the Overview
     * printed the most time-critical fact on the page in the same grey as
     * the least: an access token that expired a fortnight ago rendered
     * identically to "Not tracked", while the red banner at the top of the
     * same screen was already shouting about it. Tone is what lets the
     * connection rail rank its three rows by urgency instead of listing
     * them.
     *
     * 'bad' needs someone now, 'attn' needs someone this week, 'good' is
     * settled, and null is a reading with nothing to say either way. The
     * view pairs every tone with words, so the colour is never the only
     * thing carrying it.
     *
     * Comparisons are made with isPast()/lessThan() rather than a diff:
     * Carbon 3 returns a SIGNED difference, so `now()->diffInDays($past)`
     * is negative, and reading it as a magnitude is what once made every
     * stale channel on the dashboard report as healthy.
     *
     * @return array<int, array{label: string, value: ?string, tone: ?string, note: ?string}>
     */
    public function health(IntegrationCard $card, ?string $channel = null): array
    {
        $figures = $this->tokenFigures($card->id, $this->storeIdOf($this->store($card, $channel)));

        $now = Carbon::now();
        $expiry = $figures['tokenExpiresAt'];
        $lastSync = $figures['lastSyncAt'];
        $errors = $figures['errors24h'];

        // A sync is judged against the same 24 hours state() uses, so the
        // rail and the sidebar badge cannot call the same channel two
        // different things.
        $syncTone = match (true) {
            $lastSync === null => null,
            $lastSync->lessThan($now->copy()->subHours(24)) => 'attn',
            default => 'good',
        };

        $expiryTone = match (true) {
            $expiry === null => null,
            $expiry->isPast() => 'bad',
            $expiry->lessThanOrEqualTo($now->copy()->addDays(7)) => 'attn',
            default => 'good',
        };

        return [
            [
                'label' => 'Last sync',
                'value' => $lastSync?->diffForHumans(),
                'tone' => $syncTone,
                // Names the problem, not the threshold that detected it. The
                // date already says how long ago; what a seller needs added
                // is that this is not normal.
                'note' => $syncTone === 'attn' ? 'Overdue' : null,
            ],
            [
                'label' => 'Token expiry',
                'value' => $expiry?->diffForHumans(),
                'tone' => $expiryTone,
                // The date alone does not say what it means. "2 weeks ago"
                // is a fact; "Expired" is the reading a seller acts on.
                'note' => match ($expiryTone) {
                    'bad' => 'Expired',
                    'attn' => 'Expiring soon',
                    default => null,
                },
            ],
            [
                'label' => 'API errors in 24 hours',
                'value' => $errors === null ? null : (string) $errors,
                'tone' => match (true) {
                    $errors === null => null,
                    $errors > 0 => 'bad',
                    default => 'good',
                },
                'note' => null,
            ],
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
     * Reads the card's own menu rather than a prebuilt $groups array. The
     * bucketing groups() does is irrelevant to finding one destination, and
     * taking $groups meant the Overview page had to build them a second time
     * purely to call this - a result the layout composer then overwrote with
     * its own identical copy. allows() below is the same gate groups() applies
     * per item, so this cannot offer a link the sidebar would have hidden.
     *
     * @return array{label: string, url: string}|null
     */
    public function action(IntegrationCard $card, string $state, ?User $user, ?string $channel = null): ?array
    {
        if ($state === 'setup') {
            if ($card->id === 'shopee' && Route::has('ext.shopee.authorize')) {
                return ['label' => 'Connect', 'url' => route('ext.shopee.authorize')];
            }

            return null;
        }

        // The store's own Orders item on a store key, so "View orders" lands
        // in the store whose Overview offered it.
        foreach ($this->menuFor($card, $channel) as $item) {
            if ($item->label === 'Orders' && $this->allows($item, $user)) {
                return ['label' => 'View orders', 'url' => route($item->routeName, $item->routeParams)];
            }
        }

        return null;
    }

    /**
     * The raw token/sync figures IntegrationController@index reads for the
     * single-store OAuth marketplaces (Shopee, Lazada, TikTok) - same table
     * shape, same columns, same sandbox/live selection by `mode`.
     *
     * Venta, OpenCart and Shopify are read per STORE, which is the only level they
     * track anything at: their settings rows ARE their stores, and the same
     * row is what IntegrationController@index reads for that store's row on
     * the board. Asked about the card as a whole (no store id) they still
     * report nothing tracked, because picking one arbitrary row to speak for
     * a multi-store channel is how the board and this page came to disagree
     * about a healthy OpenCart store in the first place.
     *
     * Pedallion is single-store and authenticates with a stored API key
     * rather than OAuth, so it has a row here of its own shape: a key
     * instead of a token, no expiry column at all, and an error count that
     * has to be derived from status codes because pedallion_api_logs carries
     * no `ok` column. Shopify is multi-store and is handled in
     * storeFigures() with Venta and OpenCart.
     *
     * @return array{hasToken: ?bool, tokenExpiresAt: ?Carbon, lastSyncAt: ?Carbon, errors24h: ?int}
     */
    private function tokenFigures(string $channelId, ?int $storeId = null): array
    {
        $figures = $this->rawTokenFigures($channelId, $storeId);

        // An expiry belongs to a token. When the token is gone the date left
        // behind in the row describes a credential that no longer exists, and
        // printing it produced a card that argued with itself: Shopee showed
        // "Setup needed" - which is what an empty access_token means - beside
        // "Token expires 1 week from now", read from an expires_at the last
        // authorisation left behind. Both came from the same row. Suppressing
        // it here fixes the board and the workspace at once, since both read
        // their figures through this method.
        //
        // state() is unaffected: it tests hasToken before it tests the expiry,
        // so a channel that reached this branch was already going to be setup.
        if ($figures['hasToken'] === false) {
            $figures['tokenExpiresAt'] = null;
        }

        return $figures;
    }

    /**
     * The figures exactly as their source row holds them, before the
     * orphan-expiry rule above is applied.
     *
     * @return array{hasToken: ?bool, tokenExpiresAt: ?Carbon, lastSyncAt: ?Carbon, errors24h: ?int}
     */
    private function rawTokenFigures(string $channelId, ?int $storeId = null): array
    {
        if ($storeId !== null) {
            return $this->storeFigures($channelId, $storeId);
        }

        if ($channelId === 'pedallion') {
            return $this->pedallionFigures();
        }

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
     * One store's figures, read exactly where IntegrationController@index's
     * multi-store branch reads them so the board and this workspace cannot
     * disagree about the same store: the store's own settings row, its own
     * token column, and its own error source.
     *
     * Token expiry is genuinely absent from both schemas (neither
     * venta_settings, opencart_settings nor shopify_settings has an expiry
     * column, and a Shopify custom-app token does not expire on a clock), so it
     * reads as not tracked rather than as a fabricated date. That is a
     * recorded schema gap, not an oversight here.
     *
     * @return array{hasToken: ?bool, tokenExpiresAt: ?Carbon, lastSyncAt: ?Carbon, errors24h: ?int}
     */
    private function storeFigures(string $channelId, int $storeId): array
    {
        $tokenColumn = match ($channelId) {
            'venta' => 'api_token',
            'opencart' => 'api_key',
            // A Shopify custom app's Admin API access token. It is a
            // long-lived credential with no expiry the API reports, so
            // tokenExpiresAt below stays null for the same honest reason it
            // does for the other two: the schema has no such column and a
            // fabricated date would read as a fact.
            'shopify' => 'access_token',
            default => null,
        };

        if ($tokenColumn === null) {
            return ['hasToken' => null, 'tokenExpiresAt' => null, 'lastSyncAt' => null, 'errors24h' => null];
        }

        $row = DB::table($channelId . '_settings')->where('id', $storeId)->first();
        $lastSyncRaw = $row?->last_order_sync_at;
        $since = Carbon::now()->subHours(24);

        // venta_api_logs is written only while that store's api_logging
        // switch is on, so a zero with it off would read as "no errors" when
        // it means "never measured". opencart_sync_log has no such switch
        // and is written by every sync run, so its zero is a real zero.
        $errors24h = null;

        if ($row && $channelId === 'venta') {
            $errors24h = ((bool) ($row->api_logging ?? false))
                ? DB::table('venta_api_logs')
                    ->where('venta_setting_id', $row->id)
                    ->where('created_at', '>=', $since)
                    ->where('ok', 0)
                    ->count()
                : null;
        } elseif ($row && $channelId === 'opencart') {
            $errors24h = DB::table('opencart_sync_log')
                ->where('opencart_setting_id', $row->id)
                ->where('created_at', '>=', $since)
                ->where('status', 'failed')
                ->count();
        } elseif ($row && $channelId === 'shopify') {
            // Same shape as Venta's: shopify_api_logs is written only while
            // that store's api_logging switch is on, so a zero with it off
            // means "never measured", not "no errors".
            $errors24h = ((bool) ($row->api_logging ?? false))
                ? DB::table('shopify_api_logs')
                    ->where('shopify_setting_id', $row->id)
                    ->where('created_at', '>=', $since)
                    ->where('ok', 0)
                    ->count()
                : null;
        }

        return [
            'hasToken' => $row ? ! empty($row->{$tokenColumn}) : false,
            'tokenExpiresAt' => null,
            'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
            'errors24h' => $errors24h,
        ];
    }

    /**
     * Pedallion's single settings row, read the same way the three OAuth
     * marketplaces' rows are but against the columns this channel actually
     * has.
     *
     * Three differences from tokenFigures()' OAuth branch, each one a fact
     * about Pedallion rather than a shortcut:
     *
     *  - the credential is `api_key`, an operator-pasted key, so "has token"
     *    is "has a key";
     *  - there is no expiry column, because an API key does not expire on a
     *    clock, so token expiry reads as not tracked rather than as a
     *    fabricated date;
     *  - pedallion_api_logs has no `ok` column (see its migration), only a
     *    status_code, so a failure is `status_code >= 400`. That is exactly
     *    the test IntegrationController@index applies to the same table for
     *    the same figure on the status board, and it is stated here as a
     *    mirror rather than an independent judgement: two definitions of
     *    "error" over one table is how the board and this page come to
     *    disagree about the same channel on the same screen.
     *
     * @return array{hasToken: ?bool, tokenExpiresAt: ?Carbon, lastSyncAt: ?Carbon, errors24h: ?int}
     */
    private function pedallionFigures(): array
    {
        $row = DB::table('pedallion_settings')->first();
        $lastSyncRaw = $row?->last_order_sync_at;

        return [
            'hasToken' => $row ? ! empty($row->api_key) : false,
            'tokenExpiresAt' => null,
            'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
            'errors24h' => ((bool) ($row?->logging_enabled ?? false))
                ? DB::table('pedallion_api_logs')
                    ->where('created_at', '>=', Carbon::now()->subHours(24))
                    ->where('status_code', '>=', 400)
                    ->count()
                : null,
        ];
    }

    /** The numeric half of a StoreLink id ("venta:2" -> 2). */
    private function storeIdOf(?StoreLink $store): ?int
    {
        if (! $store) {
            return null;
        }

        $colon = strpos($store->id, ':');

        if ($colon === false) {
            return null;
        }

        $suffix = substr($store->id, $colon + 1);

        return ctype_digit($suffix) ? (int) $suffix : null;
    }

    /**
     * @return array<int, array{label: string, items: array<int, array{label: string, route: string, url: string}>}>
     */
    public function groups(IntegrationCard $card, ?User $user, ?string $channel = null): array
    {
        $buckets = array_fill_keys(self::GROUPS, []);

        foreach ($this->menuFor($card, $channel) as $item) {
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
