<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\ChannelConfigController;
use App\Http\Controllers\Api\DashboardController as ApiDashboardController;
use App\Http\Controllers\Api\DeviceTokenController;
use App\Http\Controllers\Api\MarketplaceConfigController;
use App\Http\Controllers\Api\OrderController as ApiOrderController;
use App\Http\Controllers\Api\MarketplaceOrderController as ApiMarketplaceOrderController;
use App\Http\Controllers\Api\ProductController as ApiProductController;
use Extensions\VentaCart\Controllers\Api\VentaCartOrderApiController;
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

    Route::get('/channels', [ChannelConfigController::class, 'index']);
    Route::get('/channels/{channel}/stores', [ChannelConfigController::class, 'stores'])->where('channel', '[a-z]+');
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

    foreach (['channels', 'marketplace'] as $prefix) {
        Route::get('/' . $prefix . '/{channel}/orders', [ApiMarketplaceOrderController::class, 'index'])->where('channel', '[a-z]+');
        Route::get('/' . $prefix . '/{channel}/orders/{id}', [ApiMarketplaceOrderController::class, 'show'])->where(['channel' => '[a-z]+', 'id' => '[0-9]+']);

        Route::prefix('/' . $prefix . '/{channel}/orders/{id}')->where(['channel' => '[a-z]+', 'id' => '[0-9]+'])->group(function () {
            foreach (['shipping-options', 'awb', 'tracking'] as $read) {
                Route::get('/' . $read, [ApiMarketplaceOrderController::class, 'fulfil'])->defaults('action', $read);
            }
            foreach (['pack', 'ship'] as $booking) {
                Route::post('/' . $booking, [ApiMarketplaceOrderController::class, 'fulfil'])->defaults('action', $booking)
                    ->middleware(['packing.check:channel', 'booking.lock:channel']);
            }
            foreach (['rts', 'repack'] as $step) {
                Route::post('/' . $step, [ApiMarketplaceOrderController::class, 'fulfil'])->defaults('action', $step)
                    ->middleware('booking.lock:channel');
            }
        });
    }

    Route::middleware('perm:manage_ventacart/order')->group(function () {
        foreach (['channels/ventacart/stores', ...AppDoor::channelNames('ventacart')] as $prefix) {
            Route::get('/' . $prefix . '/{store}/orders', [VentaCartOrderApiController::class, 'index'])->whereNumber('store');
            Route::get('/' . $prefix . '/{store}/orders/{id}', [VentaCartOrderApiController::class, 'show'])->whereNumber(['store', 'id']);
        }
        foreach (AppDoor::channelNames('ventacart') as $channel) {
            Route::get('/' . $channel . '-stores', [VentaCartOrderApiController::class, 'stores']);
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
    foreach (['fulfillment', 'fulfilment'] as $prefix) {
        Route::get('/' . $prefix . '/packing-check/{channel}/{order}', [\App\Http\Controllers\Fulfillment\PackingCheckController::class, 'show'])
            ->where('channel', '[a-z]+')->whereNumber('order');
        Route::post('/' . $prefix . '/packing-check/{channel}/{order}', [\App\Http\Controllers\Fulfillment\PackingCheckController::class, 'verify'])
            ->where('channel', '[a-z]+')->whereNumber('order')->middleware('throttle:60,1');
    }
    foreach (['stock', 'inventory'] as $prefix) {
        Route::get('/' . $prefix, [\App\Http\Controllers\Api\V1\InventoryController::class, 'index'])->middleware('perm:view_catalog/product');
        Route::post('/' . $prefix . '/adjust', [\App\Http\Controllers\Api\V1\InventoryController::class, 'adjust'])->middleware('perm:manage_catalog/product');
    }
});
