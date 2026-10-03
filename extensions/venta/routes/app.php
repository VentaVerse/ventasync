<?php

use Extensions\venta\Controllers\Api\VentaOrderApiController;
use Illuminate\Support\Facades\Route;

/*
| VentaCart booking for the mobile app: the web's booking modal, answered
| as JSON. Mounted under /api by ExtensionProvider with the app door's own
| sign-in check. A booking waits for the packing check and holds its order
| while it runs, as on the web.
*/
Route::middleware('perm:view_venta/order')->group(function () {
    Route::get('/venta/{store}/orders/{id}/serviceability', [VentaOrderApiController::class, 'serviceability'])->whereNumber(['store', 'id']);
    Route::get('/venta/{store}/orders/{id}/pickup-slots', [VentaOrderApiController::class, 'pickupSlots'])->whereNumber(['store', 'id']);
    Route::get('/venta/{store}/pickup-addresses', [VentaOrderApiController::class, 'pickupAddresses'])->whereNumber('store');
    Route::get('/venta/{store}/shipping-couriers', [VentaOrderApiController::class, 'shippingCouriers'])->whereNumber('store');
    Route::get('/venta/{store}/orders/{id}/tracking', [VentaOrderApiController::class, 'tracking'])->whereNumber(['store', 'id']);
    Route::get('/venta/{store}/orders/{id}/awb', [VentaOrderApiController::class, 'awb'])->whereNumber(['store', 'id']);
});

Route::middleware('perm:manage_venta/order')->group(function () {
    Route::post('/venta/{store}/orders/{id}/estimate', [VentaOrderApiController::class, 'estimate'])->whereNumber(['store', 'id']);
    Route::post('/venta/{store}/orders/{id}/book', [VentaOrderApiController::class, 'book'])->whereNumber(['store', 'id'])
        ->middleware(['packing.check:venta', 'booking.lock:venta']);
    Route::post('/venta/{store}/orders/{id}/book-manual', [VentaOrderApiController::class, 'bookManual'])->whereNumber(['store', 'id'])
        ->middleware(['packing.check:venta', 'booking.lock:venta']);
});
