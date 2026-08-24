<?php

namespace App\Http\Middleware;

use App\Services\PermissionCatalogue;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A route is gated on what it is, not on what it remembered to declare.
 *
 * This is the half of OpenCart's model that matters most and is easiest to
 * miss. There, every controller method opens with
 * `if (!$this->user->hasPermission('modify', 'catalog/product'))`. The key is
 * not configuration - it IS the controller's identity, so a new screen cannot
 * be added without a permission, and a permission cannot exist for a screen
 * that is gone.
 *
 * Ours does the same thing once, centrally: resolve the route's controller,
 * ask the catalog what key it answers to, and require the tier the verb
 * implies. The practical consequence is that a NEW CONTROLLER IS PROTECTED
 * THE MOMENT IT EXISTS. Under the per-route middleware this replaces, a route
 * added without `perm:` was simply open, and nothing said so - measured
 * before this: 20 authenticated routes carried no permission at all.
 *
 * TIER FROM THE VERB, WITH A DECLARED OVERRIDE. GET and HEAD need view;
 * everything else needs manage. Three routes are POSTs that are semantically
 * reads - two Lazada payload previews and the bulk AWB fetch, all POSTs only
 * because a query string could not carry the body - and a viewer holds them
 * today. They say so on the route:
 *
 *     Route::post(...)->defaults('permission_tier', 'view')
 *
 * On the route rather than in a list here, because the fact belongs to the
 * route and a list is one more thing to drift.
 */
class EnsureControllerPermission extends EnsurePermission
{
    public function __construct(private PermissionCatalogue $catalog)
    {
    }

    public function handle(Request $request, Closure $next, string $unused = ''): Response
    {
        $route = $request->route();

        if (! $route) {
            return $next($request);
        }

        $action = $route->getActionName();

        /* A closure route has no controller to key on. It is also not
         * something an extension can add, so the set is small, known and
         * reviewable - and leaving it open is the same position the previous
         * middleware took. Gating it would need a key nothing can derive. */
        if (! str_contains($action, '@')) {
            return $next($request);
        }

        [$class] = explode('@', $action);

        $key = $this->catalog->keyForController($class);

        if ($key === null) {
            return $next($request); // sign-in, dashboard, search: never gated
        }

        $user = $request->user();

        /* NO USER IS NOT THIS MIDDLEWARE'S QUESTION.
         *
         * Authentication is the `auth` middleware's job. Where a route has
         * it, we are never reached without a user; where a route deliberately
         * has none, it is public and refusing it here breaks it. The Lazada
         * OAuth callback is exactly that: Lazada's own servers redirect the
         * browser to it with no session, and a 401 there turns "connect your
         * shop" into a dead end nobody can debug.
         *
         * This middleware answers one question - may THIS user reach this
         * controller - and a guest on a public route is not that question. */
        if (! $user) {
            return $next($request);
        }

        $tier = $route->defaults['permission_tier']
            ?? (in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 'view' : 'manage');

        if ($user->hasPermission($tier . '_' . $key)) {
            return $next($request);
        }

        /* SOME PAGES MUST REFUSE BY NOT EXISTING.
         *
         * The channel dashboards 404 an outsider rather than redirecting
         * with a denial flash, because a denial CONFIRMS the page - and a
         * channel you may not see should not answer differently from one
         * that does not exist. The fact sits on the route, same as
         * permission_tier, because it belongs to the route. */
        if (($route->defaults['permission_denial'] ?? null) === '404') {
            abort(404);
        }

        return $this->deny($request);
    }
}
