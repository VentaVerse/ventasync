<?php

namespace Extensions\shopee\Http;

use Closure;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

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

            // Forget the {store} parameter, or Laravel fills the next scalar controller argument with it.
            $request->route()->forgetParameter('store');
            $request->attributes->set('channel.store', (int) $store->id);
        }

        return $next($request);
    }
}
