<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;

class ResolveTheme
{
    public function handle(Request $request, Closure $next)
    {
        $theme = session('theme', 'vanilla');
        view()->share('currentTheme', $theme);
        return $next($request);
    }
}
