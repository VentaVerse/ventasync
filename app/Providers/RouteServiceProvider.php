<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to your application's "home" route.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/dashboard';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            // A person and an application can share an id number; each counts apart.
            $caller = $request->user();
            $key = $caller === null ? 'ip:' . $request->ip()
                : ($caller instanceof \App\Models\ApiClient ? 'app:' : 'user:') . $caller->getKey();

            return Limit::perMinute(60)->by($key);
        });

        // Global numeric constraint for DB-id route parameters.
        //
        // Without this, a literal segment like /transfers/create matches an
        // earlier-registered /transfers/{id} route with id="create", the
        // controller does findOrFail('create'), and the user sees a 404 on
        // a button they were meant to click. Extensions are especially
        // exposed because READ routes ({id}, {id}/edit) typically register
        // before WRITE routes (/create, /import, etc.).
        //
        // Applied to every parameter name that holds a numeric DB id across
        // core + extensions. To opt out for a non-numeric id, a specific
        // route can override with ->where('id', '...').
        foreach (['id', 'store', 'order', 'product', 'productId', 'group', 'category_id'] as $param) {
            Route::pattern($param, '[0-9]+');
        }

        $this->routes(function () {
            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
