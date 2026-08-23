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
     * - HTML callers: redirect back with a flashed error message rendered
     *   from `session('error')`. layouts.channel renders it for every page it
     *   hosts (partials/legacy-page-contract); on layouts.shell it is the
     *   landing page's own @include('partials.flash') that prints it, so a
     *   shell page without that include swallows the message. Pre-existing
     *   and unrelated to this middleware. (The layout originally named here,
     *   layouts/app.blade.php, was deleted in Round F Task 4.)
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
        $method = strtoupper($request->getMethod());

        // Choose the redirect target:
        //  - For POST/PUT/PATCH/DELETE the referer is the form page (a
        //    different URL from the action), so go back there — the user
        //    sees the flashed denial in context and can correct the call.
        //  - For GET we need the loop check: if the referer equals the
        //    current URL we just denied, we'd ping-pong forever.
        //  - Always require a same-origin referer; never trust a forged
        //    host to bounce the user off-site.
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
