<?php

namespace Extensions\lazada\Http;

use Closure;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class ResolveLazadaStore
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->route('store');
        if ($id !== null) {
            $store = LazadaSetting::query()->find((int) $id);
            abort_unless($store !== null, 404);

            app()->instance('lazada.route-store', $store);
            URL::defaults(['store' => (int) $store->id]);

            $request->route()->forgetParameter('store');
            $request->attributes->set('channel.store', (int) $store->id);
        }

        return $next($request);
    }
}
