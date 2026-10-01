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
 * request that skipped it.
 */
class RequirePackingCheck
{
    public function handle(Request $request, Closure $next, string $channel): Response
    {
        if (! PackingCheck::enabled() || $request->user() === null) {
            return $next($request);
        }

        foreach (PackingCheck::ordersOf($channel, $request) as $id) {
            if (! PackingCheck::passed($request->user(), $channel, $id)) {
                if ($request->expectsJson()) {
                    return response()->json(['ok' => false, 'message' => PackingCheck::MISSING], 422);
                }

                return back()
                    ->with('error', PackingCheck::MISSING)
                    ->with($channel . '_orders_last_result', ['ok' => false, 'message' => PackingCheck::MISSING]);
            }
        }

        return $next($request);
    }
}
