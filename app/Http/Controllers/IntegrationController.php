<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

class IntegrationController extends Controller
{
    /**
     * Integrations status board — one card per channel, one card per store
     * for the multi-store channels (Venta, OpenCart, Shopify), answering
     * "is everything healthy, and where do I need to go" without a trip
     * through each channel's own settings page.
     *
     * Health data is read straight from each channel's *_settings table
     * (and, where api logging is switched on, its *_api_logs table) rather
     * than through a shared contract, because the fields genuinely differ
     * per channel and forcing a common shape would mean inventing numbers
     * for channels that don't track them. Where a figure truly isn't
     * tracked (no column, or logging switched off) the value stays null so
     * the view renders "not tracked" instead of a fabricated zero.
     */
    public function index(Request $request, IntegrationRegistry $registry)
    {
        $user = $request->user();
        $cards = $registry->visibleCards($user);

        $now = Carbon::now();
        $staleAfter = $now->copy()->subHours(24);
        $expiringWithin = $now->copy()->addDays(7);

        // Resolve a menu item's URL by its visible label, applying the same
        // per-item permission gate the existing Integrations views already
        // apply to $card->menu / $store->menu. Returns null when the item
        // isn't defined or the user can't see it.
        $menuUrl = function (array $menu, string $label) use ($user) {
            foreach ($menu as $item) {
                if ($item->label !== $label) {
                    continue;
                }
                if ($item->permission && (!$user || !$user->hasPermission($item->permission))) {
                    continue;
                }
                return route($item->routeName, $item->routeParams);
            }
            return null;
        };

        // state = setup when the token is missing/expired, attention when
        // it expires within 7 days or the last sync is older than 24h,
        // connected otherwise. $hasToken === null means token presence
        // itself isn't tracked for this channel, so only sync freshness
        // decides between attention and connected.
        $deriveState = function (?bool $hasToken, ?Carbon $tokenExpiresAt, ?Carbon $lastSyncAt) use ($staleAfter, $expiringWithin) {
            if ($hasToken === false) {
                return 'setup';
            }
            if ($tokenExpiresAt && $tokenExpiresAt->isPast()) {
                return 'setup';
            }

            $needsAttention = ($tokenExpiresAt && $tokenExpiresAt->lessThanOrEqualTo($expiringWithin))
                || (!$lastSyncAt || $lastSyncAt->lessThan($staleAfter));

            return $needsAttention ? 'attention' : 'connected';
        };

        $channels = [];

        foreach ($cards as $card) {
            $baseId = $card->id;
            $entries = [];

            if (in_array($baseId, ['shopee', 'lazada', 'tiktok'], true)) {
                // Single-store OAuth marketplaces: live/sandbox creds live in
                // parallel columns on one settings row, selected by `mode`.
                $row = DB::table($baseId . '_settings')->first();
                $isSandbox = ($row?->mode ?? 'live') === 'sandbox';
                $hasToken = $row ? !empty($isSandbox ? $row->sandbox_access_token : $row->access_token) : false;
                $expiresRaw = $row ? ($isSandbox ? $row->sandbox_expires_at : $row->expires_at) : null;
                $lastSyncRaw = $row?->last_order_sync_at;
                $loggingOn = (bool) ($row?->api_logging ?? false);

                $entries[] = [
                    'key' => $baseId,
                    'label' => $card->name,
                    'store' => null,
                    'hasToken' => $hasToken,
                    'tokenExpiresAt' => $expiresRaw ? Carbon::parse($expiresRaw) : null,
                    'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
                    // api_logging is off in this sandbox for every channel, so
                    // this is null (not tracked) today, not a fabricated zero.
                    'errors24h' => $loggingOn
                        ? DB::table($baseId . '_api_logs')->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                        : null,
                    'menu' => $card->menu,
                ];
            } elseif (in_array($baseId, ['venta', 'opencart', 'shopify', 'pedallion'], true)) {
                // Multi-store (Venta/OpenCart/Shopify) or single-store
                // (Pedallion) API-key integrations: no token expiry column
                // exists on any of these tables, so tokenExpiry always
                // reads "not tracked" here — that is a real schema gap, not
                // an oversight, and is recorded as a follow-up.
                $tokenColumn = match ($baseId) {
                    'venta' => 'api_token',
                    'opencart' => 'api_key',
                    'shopify' => 'access_token',
                    'pedallion' => 'api_key',
                };
                $stores = $card->stores ?: [null];

                foreach ($stores as $store) {
                    $storeId = $store ? (int) substr($store->id, strpos($store->id, ':') + 1) : null;
                    $row = $storeId
                        ? DB::table($baseId . '_settings')->where('id', $storeId)->first()
                        : DB::table($baseId . '_settings')->first();

                    $errors24h = null;
                    if ($row && $baseId === 'venta') {
                        $errors24h = ((bool) ($row->api_logging ?? false))
                            ? DB::table('venta_api_logs')->where('venta_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                            : null;
                    } elseif ($row && $baseId === 'shopify') {
                        $errors24h = ((bool) ($row->api_logging ?? false))
                            ? DB::table('shopify_api_logs')->where('shopify_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('ok', 0)->count()
                            : null;
                    } elseif ($row && $baseId === 'opencart') {
                        // opencart_sync_log has no logging on/off switch — it
                        // is always written by every sync run, so a zero
                        // here is a real "no failures", not an absent metric.
                        $errors24h = DB::table('opencart_sync_log')->where('opencart_setting_id', $row->id)->where('created_at', '>=', $staleAfter)->where('status', 'failed')->count();
                    } elseif ($row && $baseId === 'pedallion') {
                        $errors24h = ((bool) ($row->logging_enabled ?? false))
                            ? DB::table('pedallion_api_logs')->where('created_at', '>=', $staleAfter)->where('status_code', '>=', 400)->count()
                            : null;
                    }

                    $lastSyncRaw = $row?->last_order_sync_at;

                    $entries[] = [
                        'key' => $store->id ?? $baseId,
                        'label' => $card->name,
                        'store' => $store->label ?? null,
                        'hasToken' => $row ? !empty($row->{$tokenColumn}) : false,
                        'tokenExpiresAt' => null,
                        'lastSyncAt' => $lastSyncRaw ? Carbon::parse($lastSyncRaw) : null,
                        'errors24h' => $errors24h,
                        'menu' => $store->menu ?? $card->menu,
                        'storeStatus' => $store->status ?? null,
                    ];
                }
            } else {
                // Unrecognized future channel — degrade to "nothing tracked"
                // rather than guessing at a schema nobody has inspected yet.
                $entries[] = [
                    'key' => $baseId,
                    'label' => $card->name,
                    'store' => null,
                    'hasToken' => null,
                    'tokenExpiresAt' => null,
                    'lastSyncAt' => null,
                    'errors24h' => null,
                    'menu' => $card->menu,
                ];
            }

            foreach ($entries as $entry) {
                $state = (($entry['storeStatus'] ?? null) === 'setup_pending')
                    ? 'setup'
                    : $deriveState($entry['hasToken'], $entry['tokenExpiresAt'], $entry['lastSyncAt']);

                $ordersUrl = $menuUrl($entry['menu'], 'Orders');

                $channels[] = [
                    'key' => $entry['key'],
                    'label' => $entry['label'],
                    'store' => $entry['store'],
                    'state' => $state,
                    'lastSync' => $entry['lastSyncAt']?->diffForHumans(),
                    'tokenExpiry' => $entry['tokenExpiresAt']?->diffForHumans(),
                    'errors24h' => $entry['errors24h'],
                    'enterUrl' => $menuUrl($entry['menu'], 'Settings') ?? route('integrations.module', ['module' => $baseId]),
                    'actionLabel' => $ordersUrl ? 'View orders' : null,
                    'actionUrl' => $ordersUrl,
                ];
            }
        }

        return view('integrations.board', compact('channels'));
    }

    /**
     * Dedicated sub-page for one module — lists all configured stores
     * with breathing room. Only useful for multi-store modules; for
     * single-store modules it just shows the same card content.
     */
    public function module(Request $request, IntegrationRegistry $registry, string $module)
    {
        $card = collect($registry->visibleCards($request->user()))
            ->firstWhere('id', $module);

        if ($card === null) {
            abort(404);
        }

        return view('integrations.module', compact('card'));
    }

    public function orders(Request $request, IntegrationRegistry $registry)
    {
        $user = $request->user();
        $tabs = $registry->visibleOrderTabs($user);

        // Empty state — no fulfillable surfaces visible to this user
        if (empty($tabs)) {
            return view('integrations.orders', [
                'hasMarketplaces' => false,
                'topProducts' => [],
                'recentOrders' => [],
            ]);
        }

        // Combined top-products leaderboard. Each tab returns its own top
        // products (scoped per store for multi-store marketplaces); we merge
        // by SKU, sum qty + revenue, and remember which tabs contributed so
        // the leaderboard row can show small per-tab dots next to the SKU.
        $merged = [];
        foreach ($tabs as $tab) {
            foreach ($tab->topProductsToday(10) as $tp) {
                $key = $tp->sku;
                if (!isset($merged[$key])) {
                    $merged[$key] = [
                        'sku' => $tp->sku,
                        'name' => $tp->name,
                        'imageUrl' => $tp->imageUrl,
                        'qtySold' => 0,
                        'revenue' => 0.0,
                        'contributors' => [],
                    ];
                }
                $merged[$key]['qtySold'] += $tp->qtySold;
                $merged[$key]['revenue'] += $tp->revenue;
                if ($tp->imageUrl && empty($merged[$key]['imageUrl'])) {
                    $merged[$key]['imageUrl'] = $tp->imageUrl;
                }
                $merged[$key]['contributors'][] = [
                    'label' => $tab->label,
                    'accent' => $tab->accent,
                    'qty' => $tp->qtySold,
                ];
            }
        }
        usort($merged, fn ($a, $b) => $b['qtySold'] <=> $a['qtySold']);
        $topProducts = array_slice(array_values($merged), 0, 10);

        // Combined recent activity feed — newest first across all tabs.
        $recent = [];
        foreach ($tabs as $tab) {
            foreach ($tab->recentOrders(5) as $ro) {
                $recent[] = [
                    'order' => $ro,
                    'tab' => $tab,
                ];
            }
        }
        usort($recent, function ($a, $b) {
            $aTs = $a['order']->orderedAt?->getTimestamp() ?? 0;
            $bTs = $b['order']->orderedAt?->getTimestamp() ?? 0;
            return $bTs <=> $aTs;
        });
        $recentOrders = array_slice($recent, 0, 15);

        return view('integrations.orders', [
            'hasMarketplaces' => true,
            'topProducts' => $topProducts,
            'recentOrders' => $recentOrders,
        ]);
    }
}
