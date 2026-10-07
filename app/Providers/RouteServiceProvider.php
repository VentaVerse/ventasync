<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

class RouteServiceProvider extends ServiceProvider
{
    public const HOME = '/dashboard';

    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            $caller = $request->user();
            $key = $caller === null ? 'ip:' . $request->ip()
                : ($caller instanceof \App\Models\ApiClient ? 'app:' : 'user:') . $caller->getKey();

            return Limit::perMinute(60)->by($key);
        });

        foreach (['id', 'store', 'order', 'product', 'productId', 'group', 'category_id'] as $param) {
            Route::pattern($param, '[0-9]+');
        }

        $this->routes(function () {
            \App\Support\AppDoor::mount($this->app['router'], ['api'], base_path('routes/app.php'));

            Route::middleware('api')
                ->prefix('api')
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->group(base_path('routes/web.php'));
        });
    }
}
