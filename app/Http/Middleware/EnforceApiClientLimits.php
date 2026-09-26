<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * An application's own limits, on every door it can use: the partner API,
 * each extension's API and /mcp. It must be active, call from an address
 * it allows, and stay within its calls per minute, counted once across
 * all doors. A person's token passes through untouched.
 */
class EnforceApiClientLimits
{
    public function handle(Request $request, Closure $next): Response
    {
        $client = $request->user();
        if (! $client instanceof ApiClient) {
            return $next($request);
        }

        if (! $client->active) {
            return $this->refuse(401, 'This application is switched off.');
        }
        if (! $client->allowsIp($request->ip())) {
            return $this->refuse(403, 'This application does not accept calls from ' . $request->ip() . '.');
        }

        $cap = (int) ($client->calls_per_minute ?? 0);
        if ($cap > 0 && ! RateLimiter::attempt('api-client:' . $client->id, $cap, fn () => true, 60)) {
            return $this->refuse(429, "This application is capped at {$cap} calls per minute. Try again shortly.");
        }

        return $next($request);
    }

    private function refuse(int $status, string $message): Response
    {
        return response()->json(['message' => $message], $status);
    }
}
