<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class LogApiRequest
{
    public function handle(Request $request, Closure $next): Response
    {
        return $next($request);
    }

    public function terminate(Request $request, Response $response): void
    {
        $user = $request->user();
        \App\Services\Api\ApiCallLog::record(
            $user instanceof ApiClient ? $user : null,
            $request->method(),
            $request->path(),
            $request->attributes->get('api_scope'),
            $response->getStatusCode(),
            $request->ip(),
        );
    }
}
