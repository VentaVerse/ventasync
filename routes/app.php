<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DashboardController as ApiDashboardController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\MarketplaceConfigController;
use App\Http\Controllers\Api\OrderController as ApiOrderController;
use App\Http\Controllers\Api\MarketplaceOrderController as ApiMarketplaceOrderController;
use App\Http\Controllers\Api\ProductController as ApiProductController;
use Extensions\ventacart\Controllers\Api\VentaCartOrderApiController;
use App\Support\AppDoor;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');

Route::middleware(['auth:sanctum', 'app.person', 'app.once'])->group(function () {
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

    Route::middleware('perm:manage_sales/order')->group(function () {
        Route::get('/orders/pending-count', [ApiOrderController::class, 'pendingCount']);
        Route::get('/orders', [ApiOrderController::class, 'index']);
        Route::get('/orders/{id}', [ApiOrderController::class, 'show']);
        Route::post('/orders/{id}/status', [ApiOrderController::class, 'updateStatus']);
        Route::post('/orders/{id}/ship', [ApiOrderController::class, 'ship']);
        Route::get('/order-statuses', [ApiOrderController::class, 'statuses']);
    });

    Route::get('/marketplace/{platform}/orders', [ApiMarketplaceOrderController::class, 'index']);
    Route::get('/marketplace/{platform}/orders/{id}', [ApiMarketplaceOrderController::class, 'show']);

    Route::prefix('/marketplace/{platform}/orders/{id}')->where(['platform' => '[a-z]+', 'id' => '[0-9]+'])->group(function () {
        foreach (['shipping-options', 'awb', 'tracking'] as $read) {
            Route::get('/' . $read, [ApiMarketplaceOrderController::class, 'fulfil'])->defaults('action', $read);
        }
        foreach (['pack', 'ship'] as $booking) {
            Route::post('/' . $booking, [ApiMarketplaceOrderController::class, 'fulfil'])->defaults('action', $booking)
                ->middleware(['packing.check:platform', 'booking.lock:platform']);
        }
        foreach (['rts', 'repack'] as $step) {
            Route::post('/' . $step, [ApiMarketplaceOrderController::class, 'fulfil'])->defaults('action', $step)
                ->middleware('booking.lock:platform');
        }
    });

    Route::middleware('perm:manage_ventacart/order')->group(function () {
        foreach (AppDoor::channelNames('ventacart') as $channel) {
            Route::get('/' . $channel . '-stores', [VentaCartOrderApiController::class, 'stores']);
            Route::get('/' . $channel . '/{store}/orders', [VentaCartOrderApiController::class, 'index']);
            Route::get('/' . $channel . '/{store}/orders/{id}', [VentaCartOrderApiController::class, 'show']);
        }
    });

    Route::middleware('perm:manage_catalog/product')->group(function () {
        Route::get('/products', [ApiProductController::class, 'index']);
        Route::get('/products/{id}/quantity', [ApiProductController::class, 'showQuantity']);
        Route::put('/products/{id}/quantity', [ApiProductController::class, 'updateQuantity']);
    });

    Route::middleware('perm:view_sales/order')->group(function () {
        Route::get('/customers', [\App\Http\Controllers\Api\V1\CustomerController::class, 'index']);
        Route::get('/customers/history', [\App\Http\Controllers\Api\V1\CustomerController::class, 'history']);
    });
    Route::get('/receivables', [\App\Http\Controllers\Api\V1\ReceivableController::class, 'index'])->middleware('perm:view_sales/order_payment');
    Route::post('/receivables/payments', [\App\Http\Controllers\Api\V1\ReceivableController::class, 'recordPayment'])->middleware('perm:manage_sales/order_payment');
    Route::get('/fulfilment/packing-check/{channel}/{order}', [\App\Http\Controllers\Fulfilment\PackingCheckController::class, 'show'])
        ->where('channel', '[a-z]+')->whereNumber('order');
    Route::post('/fulfilment/packing-check/{channel}/{order}', [\App\Http\Controllers\Fulfilment\PackingCheckController::class, 'verify'])
        ->where('channel', '[a-z]+')->whereNumber('order')->middleware('throttle:60,1');
    Route::get('/inventory', [\App\Http\Controllers\Api\V1\InventoryController::class, 'index'])->middleware('perm:view_catalog/product');
    Route::post('/inventory/adjust', [\App\Http\Controllers\Api\V1\InventoryController::class, 'adjust'])->middleware('perm:manage_catalog/product');
});
