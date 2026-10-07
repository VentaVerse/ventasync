<?php

use App\Support\AppDoor;
use Extensions\ventacart\Controllers\Api\VentaCartOrderApiController;
use Illuminate\Support\Facades\Route;

foreach (AppDoor::channelNames('ventacart') as $channel) {
    Route::middleware('perm:view_ventacart/order')->group(function () use ($channel) {
        Route::get('/' . $channel . '/{store}/orders/{id}/serviceability', [VentaCartOrderApiController::class, 'serviceability'])->whereNumber(['store', 'id']);
        Route::get('/' . $channel . '/{store}/orders/{id}/pickup-slots', [VentaCartOrderApiController::class, 'pickupSlots'])->whereNumber(['store', 'id']);
        Route::get('/' . $channel . '/{store}/pickup-addresses', [VentaCartOrderApiController::class, 'pickupAddresses'])->whereNumber('store');
        Route::get('/' . $channel . '/{store}/shipping-couriers', [VentaCartOrderApiController::class, 'shippingCouriers'])->whereNumber('store');
        Route::get('/' . $channel . '/{store}/orders/{id}/tracking', [VentaCartOrderApiController::class, 'tracking'])->whereNumber(['store', 'id']);
        Route::get('/' . $channel . '/{store}/orders/{id}/awb', [VentaCartOrderApiController::class, 'awb'])->whereNumber(['store', 'id']);
    });

    Route::middleware('perm:manage_ventacart/order')->group(function () use ($channel) {
        Route::post('/' . $channel . '/{store}/orders/{id}/estimate', [VentaCartOrderApiController::class, 'estimate'])->whereNumber(['store', 'id']);
        Route::post('/' . $channel . '/{store}/orders/{id}/book', [VentaCartOrderApiController::class, 'book'])->whereNumber(['store', 'id'])
            ->middleware(['packing.check:ventacart', 'booking.lock:ventacart']);
        Route::post('/' . $channel . '/{store}/orders/{id}/book-manual', [VentaCartOrderApiController::class, 'bookManual'])->whereNumber(['store', 'id'])
            ->middleware(['packing.check:ventacart', 'booking.lock:ventacart']);
    });
}
