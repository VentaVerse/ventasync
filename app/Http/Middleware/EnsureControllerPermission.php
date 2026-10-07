<?php

namespace App\Http\Middleware;

use App\Services\PermissionCatalogue;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

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

        if (! str_contains($action, '@')) {
            return $next($request);
        }

        [$class] = explode('@', $action);

        $key = $this->catalog->keyForController($class);

        if ($key === null) {
            return $next($request);
        }

        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        $tier = $route->defaults['permission_tier']
            ?? (in_array($request->getMethod(), ['GET', 'HEAD'], true) ? 'view' : 'manage');

        if ($user->hasPermission($tier . '_' . $key)) {
            return $next($request);
        }

        if (($route->defaults['permission_denial'] ?? null) === '404') {
            abort(404);
        }

        return $this->deny($request);
    }
}
