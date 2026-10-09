<?php

namespace Extensions\TikTok\Http;

use Closure;
use Extensions\TikTok\Models\TikTokStore;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;

class ResolveTikTokStore
{
    public function handle(Request $request, Closure $next)
    {
        $id = $request->route('store');
        if ($id !== null) {
            $store = TikTokStore::query()->find((int) $id);
            abort_unless($store !== null, 404);

            app()->instance('tiktok.route-store', $store);
            URL::defaults(['store' => (int) $store->id]);

            $request->route()->forgetParameter('store');
            $request->attributes->set('channel.store', (int) $store->id);
        }

        return $next($request);
    }
}
