<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $keys = ''): Response
    {
        $user = $request->user();
        if (!$user) {
            abort(401);
        }

        $keys = trim($keys);
        if ($keys === '') {
            return $next($request);
        }

        $allowed = array_values(array_filter(array_map('trim', explode('|', $keys))));
        foreach ($allowed as $k) {
            if ($k !== '' && $user->hasPermission($k)) {
                return $next($request);
            }
        }

        return $this->deny($request);
    }


    protected function deny(Request $request): Response
    {
        $message = "You don't have permission to do this action.";

        if ($request->expectsJson()
            || $request->ajax()
            || $request->wantsJson()
            || $request->is('api/*')
        ) {
            return response()->json([
                'error'   => 'permission_denied',
                'message' => $message,
            ], 403);
        }

        if (in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            abort(403);
        }

        $referer = $request->headers->get('referer');
        $current = $request->fullUrl();
        $fallback = route('dashboard', [], false);
        $method = strtoupper($request->getMethod());

        // Redirect back only to a same-origin referer that differs from the denied URL, to avoid loops and open redirects.
        $target = $fallback;
        if ($referer) {
            $parsed = parse_url($referer);
            $host = $parsed['host'] ?? null;
            $sameOrigin = ($host === null || $host === $request->getHost());

            $isWrite = in_array($method, ['POST', 'PUT', 'PATCH', 'DELETE'], true);
            if ($sameOrigin && ($isWrite || $referer !== $current)) {
                $target = $referer;
            }
        }

        return redirect($target)->with('error', $message);
    }
}
