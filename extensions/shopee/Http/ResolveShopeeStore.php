<?php

namespace Extensions\shopee\Http;

use Closure;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

/**
 * Resolve the {store} segment of a Shopee page into the store the whole
 * request acts for (multi-store S4, #194). The row is bound into the
 * container - ShopeeSetting::defaultStore() answers with it for the
 * rest of the request - and URL::defaults makes every route() call on
 * the page fill its own {store} without being edited.
 *
 * Routes without a {store} segment (the OAuth pair, the settings hub)
 * pass through untouched. An unknown store id is a 404, exactly like a
 * page that does not exist; a disabled store stays reachable, the way
 * Venta keeps a setup-pending store's pages open to finish configuring.
 */
class ResolveShopeeStore
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->route('store');
        if ($id !== null) {
            $store = ShopeeSetting::query()->find((int) $id);
            abort_unless($store !== null, 404);

            app()->instance('shopee.route-store', $store);
            URL::defaults(['store' => (int) $store->id]);

            /* CONSUMED, or it poisons every controller signature: Laravel
             * fills unclaimed scalar method arguments from route parameters
             * POSITIONALLY (the ChannelDashboardController lesson), so a
             * left-behind {store} would land in the first int $productId it
             * meets. The layout composer reads the attribute instead. */
            $request->route()->forgetParameter('store');
            $request->attributes->set('channel.store', (int) $store->id);
        }

        return $next($request);
    }
}
