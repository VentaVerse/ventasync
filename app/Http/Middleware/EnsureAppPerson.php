<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AppDoor;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

/**
 * The app door admits a person only: the token their login returned. An
 * outside application's API key belongs to the partner API and the
 * assistant, under its own scopes; here it would reach screens no scope
 * covers, such as the dashboard, so it is refused.
 */
class EnsureAppPerson
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || ! $user->currentAccessToken() instanceof PersonalAccessToken) {
            return response()->json([
                'message' => 'This address is for the VentaSync app. Sign in with a person\'s account; an API key works on /api/v1 instead.',
            ], 401);
        }

        if (! AppDoor::mayEnter($user)) {
            return response()->json(['message' => AppDoor::SIGN_IN_REFUSED], 403);
        }

        $request->attributes->set(AppDoor::ATTRIBUTE, AppDoor::VALUE);

        return $next($request);
    }
}
