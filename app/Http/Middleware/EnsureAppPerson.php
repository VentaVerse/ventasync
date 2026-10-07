<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\AppDoor;
use Closure;
use Illuminate\Http\Request;
use Laravel\Sanctum\PersonalAccessToken;
use Symfony\Component\HttpFoundation\Response;

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

        if (($refused = AppDoor::refusal($user)) !== null) {
            return response()->json(['message' => $refused], 403);
        }

        $request->attributes->set(AppDoor::ATTRIBUTE, AppDoor::VALUE);

        return $next($request);
    }
}
