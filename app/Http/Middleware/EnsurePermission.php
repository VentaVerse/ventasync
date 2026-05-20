<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePermission
{
    /**
     * Middleware parameter format:
     *   perm:key1|key2|key3
     * Any one match grants access.
     */
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

    /**
     * Build a permission-denied response shaped to match the caller.
     *
     * - JSON / AJAX callers: 403 with a stable error envelope.
     * - HTML callers: redirect back with a flashed error message that
     *   `layouts/app.blade.php` already renders via `session('error')`.
     *   If `back()` would loop (no Referer, or Referer is the current
     *   URL), fall back to `/dashboard` so the user isn't stranded.
     */
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

        $referer = $request->headers->get('referer');
        $current = $request->fullUrl();
        $fallback = route('dashboard', [], false);

        // Avoid redirect loops: if the previous URL is missing or is the
        // exact page we just failed on, send the user to the dashboard.
        $target = $fallback;
        if ($referer && $referer !== $current) {
            // Strip the host to keep it a same-origin redirect — never bounce
            // the user off-site even if Referer is forged.
            $parsed = parse_url($referer);
            $host = $parsed['host'] ?? null;
            if ($host === null || $host === $request->getHost()) {
                $target = $referer;
            }
        }

        return redirect($target)->with('error', $message);
    }
}
