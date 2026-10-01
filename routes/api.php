<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController as ApiDashboardController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\MarketplaceConfigController;
use App\Http\Controllers\Api\OrderController as ApiOrderController;
use App\Http\Controllers\Api\MarketplaceOrderController as ApiMarketplaceOrderController;
use App\Http\Controllers\Api\ProductController as ApiProductController;
use Extensions\venta\Controllers\Api\VentaOrderApiController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| Here is where you can register API routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "api" middleware group. Make something great!
|
*/

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

// The app door: a person signed in with the mobile app's login token, never
// an outside application's API key (that is /api/v1's). Each route below
// also needs the same permission its web page needs.
Route::middleware(['auth:sanctum', 'app.person'])->group(function () {
    Route::get('/user', function (Request $request) {
        return $request->user();
    });

    Route::get('/user/permissions', function (Request $request) {
        return response()->json($request->user()->getEffectivePermissions());
    });

    Route::post('/logout', [AuthController::class, 'logout']);

    Route::post('/device-token', [DeviceTokenController::class, 'store']);
    Route::delete('/device-token', [DeviceTokenController::class, 'destroy']);

    Route::get('/marketplace-config', [MarketplaceConfigController::class, 'index']);

    Route::get('/dashboard', [ApiDashboardController::class, 'index']);
    Route::get('/dashboard/chart-data', [ApiDashboardController::class, 'chartData']);

    // Derived keys, same as everywhere: the legacy scheme is retired. The
    // door middleware does not run in this group (auth:sanctum resolves the
    // token user after group middleware would fire), so these explicit
    // gates ARE the enforcement here.
    Route::middleware('perm:manage_sales/order')->group(function () {
        Route::get('/orders/pending-count', [ApiOrderController::class, 'pendingCount']);
        Route::get('/orders', [ApiOrderController::class, 'index']);
        Route::get('/orders/{id}', [ApiOrderController::class, 'show']);
        Route::post('/orders/{id}/status', [ApiOrderController::class, 'updateStatus']);
        Route::get('/order-statuses', [ApiOrderController::class, 'statuses']);
    });

    // Per-platform permission is enforced inside MarketplaceOrderController
    // (manage_{platform}_orders) — the container 'manage_marketplace_api'
    // is intentionally view-only and only gates menu visibility.
    Route::get('/marketplace/{platform}/orders', [ApiMarketplaceOrderController::class, 'index']);
    Route::get('/marketplace/{platform}/orders/{id}', [ApiMarketplaceOrderController::class, 'show']);

    Route::middleware('perm:manage_venta/order')->group(function () {
        Route::get('/venta-stores', [VentaOrderApiController::class, 'stores']);
        Route::get('/venta/{store}/orders', [VentaOrderApiController::class, 'index']);
        Route::get('/venta/{store}/orders/{id}', [VentaOrderApiController::class, 'show']);
    });

    Route::middleware('perm:manage_catalog/product')->group(function () {
        Route::get('/products', [ApiProductController::class, 'index']);
        Route::get('/products/{id}/quantity', [ApiProductController::class, 'showQuantity']);
        Route::put('/products/{id}/quantity', [ApiProductController::class, 'updateQuantity']);
    });

    /* The same shared rules the partner API runs, answering the same JSON,
       for a person under their own permissions. An extension adds its own
       app routes from its routes/app.php. */
    Route::middleware('perm:view_sales/order')->group(function () {
        Route::get('/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'index']);
        Route::get('/customers/history', [\App\Http\Controllers\Api\V1\CustomerController::class, 'history']);
    });
    Route::get('/inventory', [\App\Http\Controllers\Api\V1\InventoryController::class, 'index'])->middleware('perm:view_catalog/product');
    Route::post('/inventory/adjust', [\App\Http\Controllers\Api\V1\InventoryController::class, 'adjust'])->middleware('perm:manage_catalog/product');
});

/*
|--------------------------------------------------------------------------
| External API (v1) — scoped 3rd-party access
|--------------------------------------------------------------------------
|
| Callers here authenticate as an App\Models\ApiClient (Settings → API),
| not a human user. Each route requires a Sanctum token ability matching
| its `scope:` middleware (see config/api_scopes.php). Every request is
| audit-logged via `api.audit` (App\Http\Middleware\LogApiRequest).
|
*/
Route::prefix('v1')->middleware(['auth:sanctum', 'api.limits', 'api.audit'])->group(function () {
    Route::middleware('scope:products:read')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'index']);
        Route::get('/products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'show']);
    });

    /* The write half (#177). A separate scope, granted only to an
       application ticked for it on the AI Tools page, so a read key can
       never reach this route. */
    Route::middleware('scope:products:write')->group(function () {
        Route::put('/products/{id}/price', [\App\Http\Controllers\Api\V1\ProductController::class, 'updatePrice'])->whereNumber('id');
        Route::patch('/products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update'])->whereNumber('id');
        Route::patch('/products/{id}/variations', [\App\Http\Controllers\Api\V1\ProductController::class, 'updateVariations'])->whereNumber('id');
        Route::post('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'store']);
    });

    // NOTE: reports endpoints live in the reports extension
    // (extensions/reports/routes/api.php) — core no longer owns them.

    Route::middleware('scope:inventory:read')->group(function () {
        Route::get('/inventory', [\App\Http\Controllers\Api\V1\InventoryController::class, 'index']);
    });

    Route::middleware('scope:inventory:write')->group(function () {
        Route::post('/inventory/adjust', [\App\Http\Controllers\Api\V1\InventoryController::class, 'adjust']);
    });

    Route::middleware('scope:orders:read')->group(function () {
        Route::get('/orders', [\App\Http\Controllers\Api\V1\OrderController::class, 'index']);
        Route::get('/orders/{id}', [\App\Http\Controllers\Api\V1\OrderController::class, 'show'])->whereNumber('id');
        Route::get('/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'index']);
        Route::get('/customers/history', [\App\Http\Controllers\Api\V1\CustomerController::class, 'history']);
    });
});
