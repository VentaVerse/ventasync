<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use App\Models\ApiRequestLog;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Throwable;

/**
 * Terminable middleware: writes one ApiRequestLog row per external API
 * request, after the response is sent, so it never adds request latency
 * and a logging failure never breaks the actual API call.
 */
class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        try {
            $user = $request->user();
            $clientId = $user instanceof ApiClient ? $user->id : null;

            ApiRequestLog::create([
                'api_client_id' => $clientId,
                'method'        => $request->method(),
                'path'          => $request->path(),
                'scope'         => $request->attributes->get('api_scope'),
                'status'        => $response->getStatusCode(),
                'ip'            => $request->ip(),
                'created_at'    => now(),
            ]);

            if ($clientId) {
                ApiClient::whereKey($clientId)->update(['last_used_at' => now()]);
            }
        } catch (Throwable $e) {
            report($e);
        }
    }
}
