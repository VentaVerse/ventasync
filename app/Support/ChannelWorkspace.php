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

class ChannelWorkspace
{
    public const GROUPS = ['Sell', 'Catalog', 'Channel'];

    public function __construct(private IntegrationRegistry $registry)
    {
    }

    public function card(string $channel, ?User $user): ?IntegrationCard
    {
        return $this->resolve($this->registry->visibleCards($user), $channel);
    }

    public function cardForAuthorisedPage(string $channel, ?User $user): ?IntegrationCard
    {
        return $this->resolve($this->registry->cardsForAuthorisedRequest($user), $channel);
    }

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

        if ($card->stores === []) {
            return $storeId === null ? $card : null;
        }

        return $this->storeOn($card, $storeId) ? $card : null;
    }

    public static function pathKey(string $key): string
    {
        return str_replace(':', '/', $key);
    }

    public static function keyFromPath(string $path): string
    {
        return str_replace('/', ':', $path);
    }

    public static function overviewUrl(string $key): string
    {
        [$channel, $store] = array_pad(explode(':', $key, 2), 2, null);

        return route('ext.' . $channel . '.dashboard', $store === null ? [] : ['store' => $store]);
    }

    // Reject any key that is not a positive integer store id instead of coercing it.
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

    private function menuFor(IntegrationCard $card, ?string $channel): array
    {
        return $this->store($card, $channel)?->menu ?? $card->menu;
    }

    public function hasWorkspace(IntegrationCard $card): bool
    {
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

    public function state(IntegrationCard $card, ?string $channel = null): string
    {
        return $this->stateWithReason($card, $channel)['state'];
    }

    public function stateWithReason(IntegrationCard $card, ?string $channel = null): array
    {
        $store = $this->store($card, $channel);

        if ($store && $store->status === 'setup_pending') {
            return ['state' => 'setup', 'reason' => 'disabled'];
        }

        $figures = $this->tokenFigures($card->id, $this->storeIdOf($store));

        $now = Carbon::now();
        $staleAfter = $now->copy()->subHours(24);
        $expiringWithin = $now->copy()->addDays(7);

        if ($figures['hasToken'] === null && $figures['lastSyncAt'] === null) {
            return ['state' => 'untracked', 'reason' => null];
        }

        if ($figures['hasToken'] === false) {
            return ['state' => 'setup', 'reason' => 'no_token'];
        }
        if ($figures['tokenExpiresAt'] && $figures['tokenExpiresAt']->isPast()) {
            return ['state' => 'setup', 'reason' => 'expired'];
        }

        if ($figures['tokenExpiresAt'] && $figures['tokenExpiresAt']->lessThanOrEqualTo($expiringWithin)) {
            return ['state' => 'attention', 'reason' => 'expiring'];
        }

        if (! $figures['lastSyncAt'] || $figures['lastSyncAt']->lessThan($staleAfter)) {
            return ['state' => 'attention', 'reason' => 'stale'];
        }

        return ['state' => 'connected', 'reason' => null];
    }

    public static function badge(string $state, ?string $reason = null): array
    {
        return match (true) {
            $state === 'setup' && $reason === 'disabled' => ['label' => 'Turned off', 'tone' => 'neutral'],
            $state === 'setup' && $reason === 'expired' => ['label' => 'Sign-in expired', 'tone' => 'danger'],
            $state === 'setup' => ['label' => 'Not connected', 'tone' => 'danger'],

            $state === 'attention' && $reason === 'expiring' => ['label' => 'Sign-in expires soon', 'tone' => 'warning'],
            $state === 'attention' && $reason === 'stale' => ['label' => 'Sync overdue', 'tone' => 'warning'],
            $state === 'attention' => ['label' => 'Needs attention', 'tone' => 'warning'],

            $state === 'connected' => ['label' => 'Connected', 'tone' => 'success'],

            default => ['label' => 'Not tracked', 'tone' => 'neutral'],
        };
    }

    public function waiting(IntegrationCard $card, ?User $user, ?string $channel = null): array
    {
        $tab = null;

        $wanted = $this->store($card, $channel)?->id ?? $card->id;

        foreach ($this->registry->visibleOrderTabs($user) as $candidate) {
            if ($candidate->id === $wanted) {
                $tab = $candidate;
                break;
            }
        }

        if (! $tab) {
            return [];
        }

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

    public function health(IntegrationCard $card, ?string $channel = null): array
    {
        $figures = $this->tokenFigures($card->id, $this->storeIdOf($this->store($card, $channel)));

        $now = Carbon::now();
        $expiry = $figures['tokenExpiresAt'];
        $lastSync = $figures['lastSyncAt'];
        $errors = $figures['errors24h'];

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
                'note' => $syncTone === 'attn' ? 'Overdue' : null,
            ],
            [
                'label' => 'Sign-in expires',
                'value' => $expiry?->diffForHumans(),
                'tone' => $expiryTone,
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

    public function action(IntegrationCard $card, string $state, ?User $user, ?string $channel = null): ?array
    {
        if ($state === 'setup') {
            if ($card->id === 'shopee' && Route::has('ext.shopee.authorize')) {
                $storeKey = $this->store($card, $channel)?->id;
                $bareStore = $storeKey !== null ? (explode(':', $storeKey, 2)[1] ?? null) : null;

                return ['label' => 'Connect', 'url' => route('ext.shopee.authorize', $bareStore !== null ? ['store' => $bareStore] : [])];
            }

            return null;
        }

        foreach ($this->menuFor($card, $channel) as $item) {
            if ($item->label === 'Orders' && $this->allows($item, $user)) {
                return ['label' => 'View orders', 'url' => route($item->routeName, $item->routeParams)];
            }
        }

        return null;
    }

    public static function signInExpiry(?object $row, bool $isSandbox): ?Carbon
    {
        if (! $row) {
            return null;
        }
        $refreshToken = $isSandbox ? ($row->sandbox_refresh_token ?? null) : ($row->refresh_token ?? null);
        $refreshExpires = $isSandbox ? ($row->sandbox_refresh_expires_at ?? null) : ($row->refresh_expires_at ?? null);
        $accessExpires = $isSandbox ? ($row->sandbox_expires_at ?? null) : ($row->expires_at ?? null);

        if ($refreshExpires) {
            return Carbon::parse($refreshExpires);
        }
        if (! empty($refreshToken)) {
            return null;
        }

        return $accessExpires ? Carbon::parse($accessExpires) : null;
    }

    private function tokenFigures(string $channelId, ?int $storeId = null): array
    {
        $figures = $this->rawTokenFigures($channelId, $storeId);

        if ($figures['hasToken'] === false) {
            $figures['tokenExpiresAt'] = null;
        }

        return $figures;
    }

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

        $lastSyncRaw = $row?->last_order_sync_at;
        $loggingOn = (($row?->api_log_mode ?? 'off') !== 'off');

        return [
            'hasToken' => $row ? ! empty($isSandbox ? $row->sandbox_access_token : $row->access_token) : false,
            'tokenExpiresAt' => self::signInExpiry($row, $isSandbox),
            'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
            'errors24h' => $loggingOn
                ? DB::table($channelId . '_api_logs')->where('created_at', '>=', Carbon::now()->subHours(24))->where('ok', 0)->count()
                : null,
        ];
    }

    private function storeFigures(string $channelId, int $storeId): array
    {
        if ($channelId === 'shopee') {
            $row = DB::table('shopee_settings')->where('id', $storeId)->first();
            $isSandbox = ($row?->mode ?? 'live') === 'sandbox';
            $lastSyncRaw = $row?->last_order_sync_at;
            $loggingOn = (($row?->api_log_mode ?? 'off') !== 'off');

            return [
                'hasToken' => $row ? ! empty($isSandbox ? $row->sandbox_access_token : $row->access_token) : false,
                'tokenExpiresAt' => self::signInExpiry($row, $isSandbox),
                'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
                'errors24h' => $loggingOn
                    ? DB::table('shopee_api_logs')
                        ->where('shopee_setting_id', $storeId)
                        ->where('created_at', '>=', Carbon::now()->subHours(24))
                        ->where('ok', 0)->count()
                    : null,
            ];
        }

        $tokenColumn = match ($channelId) {
            'ventacart' => 'api_token',
            'opencart' => 'api_key',
            'shopify' => 'access_token',
            default => null,
        };

        if ($tokenColumn === null) {
            return ['hasToken' => null, 'tokenExpiresAt' => null, 'lastSyncAt' => null, 'errors24h' => null];
        }

        $row = DB::table($channelId . '_settings')->where('id', $storeId)->first();
        $lastSyncRaw = $row?->last_order_sync_at;
        $since = Carbon::now()->subHours(24);

        $errors24h = null;

        if ($row && $channelId === 'ventacart') {
            $errors24h = ((($row->api_log_mode ?? 'off') !== 'off'))
                ? DB::table('ventacart_api_logs')
                    ->where('ventacart_setting_id', $row->id)
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
            $errors24h = ((($row->api_log_mode ?? 'off') !== 'off'))
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

    private function pedallionFigures(): array
    {
        $row = DB::table('pedallion_settings')->first();
        $lastSyncRaw = $row?->last_order_sync_at;

        return [
            'hasToken' => $row ? ! empty($row->api_key) : false,
            'tokenExpiresAt' => null,
            'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
            'errors24h' => ((($row?->api_log_mode ?? 'off') !== 'off'))
                ? DB::table('pedallion_api_logs')
                    ->where('created_at', '>=', Carbon::now()->subHours(24))
                    ->where('status_code', '>=', 400)
                    ->count()
                : null,
        ];
    }

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

    public function groups(IntegrationCard $card, ?User $user, ?string $channel = null): array
    {
        $buckets = array_fill_keys(self::GROUPS, []);

        foreach ($this->menuFor($card, $channel) as $item) {
            if (! $this->allows($item, $user)) {
                continue;
            }

            $group = in_array($item->group, self::GROUPS, true) ? $item->group : 'Channel';

            $url = route($item->routeName, $item->routeParams);
            $buckets[$group][] = [
                'label' => $item->label,
                'route' => $item->routeName,
                'url' => $url,
                'badge' => $item->badge > 0 ? $item->badge : null,
                'children' => array_values(array_map(fn ($c) => [
                    'label' => (string) $c['label'],
                    'url' => $url . (str_contains($url, '?') ? '&' : '?') . 'tab=' . $c['section'],
                ], array_filter($item->children, function ($c) use ($user) {
                    return empty($c['permission']) || ($user?->hasPermission($c['permission']) ?? false);
                }))),
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

    public function allows(MenuItem $item, ?User $user): bool
    {
        if (! $user) {
            return false;
        }

        if (! Route::has($item->routeName)) {
            return false;
        }

        $route = Route::getRoutes()->getByName($item->routeName);

        preg_match_all('/\{([^}?]+)\}/', $route->uri(), $matches);

        foreach ($matches[1] as $parameter) {
            if (! array_key_exists($parameter, $item->routeParams)) {
                return false;
            }
        }

        if ($item->permission !== null && ! $this->holdsAny($user, $item->permission)) {
            return false;
        }

        $action = (string) $route->getActionName();

        if (! str_contains($action, '@')) {
            return true;
        }

        [$class] = explode('@', $action);
        $catalog = app(\App\Services\PermissionCatalogue::class);
        $key = $catalog->keyForController($class);

        if ($key === null) {
            return true;
        }

        $tier = $route->defaults['permission_tier'] ?? 'view';

        return $user->hasPermission($tier . '_' . $key);
    }

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
