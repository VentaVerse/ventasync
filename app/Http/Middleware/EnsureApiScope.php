<?php

namespace App\Http\Middleware;

use App\Models\ApiClient;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

// Require a real ApiClient token: a session user's transient token passes every ability check.
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
