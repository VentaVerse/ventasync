<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that the authenticated API token carries the given scope
 * ability (e.g. `scope:products:read`). Scopes are Sanctum token
 * abilities granted when an API Application's token is issued — see
 * config/api_scopes.php and App\Models\ApiClient.
 */
class EnsureApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $request->user();

        if (! $user || ! method_exists($user, 'tokenCan') || ! $user->tokenCan($scope)) {
            return response()->json([
                'message' => "Missing required scope: {$scope}",
            ], 403);
        }

        $request->attributes->set('api_scope', $scope);

        return $next($request);
    }
}
