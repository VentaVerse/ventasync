<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces that the authenticated API token carries the given scope
 * ability (e.g. `scope:products:read`). Scopes are Sanctum token
 * abilities granted when an API Application's token is issued — see
 * config/api_scopes.php and App\Models\ApiClient.
 *
 * Only genuine ApiClient bearer tokens may pass. A session-authenticated
 * web user carries a Sanctum TransientToken whose ability check returns
 * true for everything — accepting it here would let any logged-in user
 * (or a CSRF-driven request from the ERP's own stateful domain) bypass
 * the scope catalog entirely. We therefore require a real
 * PersonalAccessToken bound to an active ApiClient.
 */
class EnsureApiScope
{
    public function handle(Request $request, Closure $next, string $scope): Response
    {
        $user = $request->user();
        $token = $user?->currentAccessToken();

        if (! $user instanceof ApiClient
            || ! $token instanceof PersonalAccessToken
            || ! $user->active
            || ! $token->can($scope)) {
            return response()->json([
                'message' => "Missing required scope: {$scope}",
            ], 403);
        }

        $request->attributes->set('api_scope', $scope);

        return $next($request);
    }
}
