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

Route::post('/login', [AuthController::class, 'login']);

Route::middleware('auth:sanctum')->group(function () {
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

    Route::middleware('perm:manage_orders')->group(function () {
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

    Route::middleware('perm:manage_venta_orders')->group(function () {
        Route::get('/venta-stores', [VentaOrderApiController::class, 'stores']);
        Route::get('/venta/{store}/orders', [VentaOrderApiController::class, 'index']);
        Route::get('/venta/{store}/orders/{id}', [VentaOrderApiController::class, 'show']);
    });

    Route::middleware('perm:manage_products')->group(function () {
        Route::get('/products', [ApiProductController::class, 'index']);
        Route::get('/products/{id}/quantity', [ApiProductController::class, 'showQuantity']);
        Route::put('/products/{id}/quantity', [ApiProductController::class, 'updateQuantity']);
    });
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
Route::prefix('v1')->middleware(['auth:sanctum', 'api.audit'])->group(function () {
    Route::middleware('scope:products:read')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'index']);
        Route::get('/products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'show']);
    });
});
