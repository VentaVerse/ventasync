<?php

namespace App\Http\Middleware;

use App\Support\Fulfilment\PackingCheck;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A booking route waits for the packing check while it is switched on:
 * every order in the request must have been counted correctly by the person
 * booking it. The page asks for the count first; this is what refuses a
 * request that skipped it. A booking spends the count it used.
 */
class RequirePackingCheck
{
    public function handle(Request $request, Closure $next, string $channel): Response
    {
        // The app's marketplace routes name their channel in the URL.
        if ($channel === 'platform') {
            $channel = (string) $request->route('platform');
        }

        if (! PackingCheck::enabled() || $request->user() === null) {
            return $next($request);
        }

        $user = $request->user();
        $ids = PackingCheck::ordersOf($channel, $request);
        foreach ($ids as $id) {
            if (! PackingCheck::passed($user, $channel, $id)) {
                if ($request->expectsJson()) {
                    return response()->json(['ok' => false, 'error' => 'count_needed', 'message' => PackingCheck::MISSING], 422);
                }

                return back()
                    ->with('error', PackingCheck::MISSING)
                    ->with($channel . '_orders_last_result', ['ok' => false, 'message' => PackingCheck::MISSING]);
            }
        }

        $response = $next($request);
        PackingCheck::spend($user, $channel, PackingCheck::booked($channel, $ids, $request, $response));

        return $response;
    }
}
