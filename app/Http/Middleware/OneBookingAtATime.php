<?php

namespace App\Http\Middleware;

use App\Support\Fulfilment\PackingCheck;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

/**
 * Holds each order in the request while its booking runs, so a second
 * request for the same order, from a double click, a second person or a
 * phone re-sending, is turned away instead of reaching the marketplace too.
 */
class OneBookingAtATime
{
    public const BUSY = 'Someone else is booking this order.';

    /** Longer than the slowest booking, so a lock left by a crash still clears itself. */
    private const SECONDS = 120;

    public function handle(Request $request, Closure $next, string $channel): Response
    {
        $ids = PackingCheck::ordersOf($channel, $request);
        sort($ids);

        /** @var array<int, Lock> $held */
        $held = [];
        foreach ($ids as $id) {
            $lock = Cache::lock('fulfilment:' . $channel . ':' . $id, self::SECONDS);
            if (! $lock->get()) {
                foreach ($held as $taken) {
                    $taken->release();
                }

                return $this->busy($request, $channel);
            }
            $held[] = $lock;
        }

        try {
            return $next($request);
        } finally {
            foreach ($held as $lock) {
                $lock->release();
            }
        }
    }

    private function busy(Request $request, string $channel): Response
    {
        if ($request->expectsJson()) {
            return response()->json(['ok' => false, 'error' => 'busy', 'message' => self::BUSY], 409);
        }

        return back()
            ->with('error', self::BUSY)
            ->with($channel . '_orders_last_result', ['ok' => false, 'message' => self::BUSY]);
    }
}
