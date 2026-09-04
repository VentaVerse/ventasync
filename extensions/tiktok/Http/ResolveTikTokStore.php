<?php

namespace Extensions\tiktok\Http;

use Closure;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Resolve the {store} segment of a TikTok page into the store the whole
 * request acts for (multi-store, the owner 2026-09-05 - the same shape
 * ResolveLazadaStore/ResolveShopeeStore have). The row is bound into the
 * container so TikTokSetting::defaultStore() answers with it for the rest of
 * the request, and URL::defaults makes every route() call on the page fill its
 * own {store} without being edited.
 *
 * An unknown store id is a 404, like a page that does not exist; a disabled
 * store stays reachable so its setup can be finished.
 */
class ResolveTikTokStore
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->route('store');
        if ($id !== null) {
            $store = TikTokSetting::query()->find((int) $id);
            abort_unless($store !== null, 404);

            app()->instance('tiktok.route-store', $store);
            URL::defaults(['store' => (int) $store->id]);

            /* CONSUMED, or Laravel fills unclaimed scalar controller arguments
             * from route parameters positionally and a left-behind {store}
             * lands in the first int argument it meets. Controllers read
             * defaultStore(), not a $store param. */
            $request->route()->forgetParameter('store');
            $request->attributes->set('channel.store', (int) $store->id);
        }

        return $next($request);
    }
}
