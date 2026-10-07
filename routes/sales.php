<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Sales\OrderController;
use App\Http\Controllers\Sales\OrderPaymentController;

Route::prefix('sales')->group(function () {
    Route::group([], function () {
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/search-products', [OrderController::class, 'searchProducts'])->name('orders.search_products');
        Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('orders.show');
        Route::get('/order-payments', [OrderPaymentController::class, 'paymentsReport'])->name('orders.payments_report');
    });

    Route::group([], function () {
        Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::post('/orders/{id}/update-product-cost', [OrderController::class, 'updateProductCost'])->whereNumber('id')->name('orders.update_product_cost');
        Route::post('/orders/{id}/backfill-costs', [OrderController::class, 'backfillCosts'])->whereNumber('id')->name('orders.backfill_costs');
        Route::post('/orders/{id}/update-shipping-cost', [OrderController::class, 'updateShippingCost'])->whereNumber('id')->name('orders.update_shipping_cost');
        Route::post('/orders/{id}/fees', [OrderController::class, 'storeFee'])->whereNumber('id')->name('orders.store_fee');
        Route::put('/orders/{id}/fees/{feeId}', [OrderController::class, 'updateFee'])->whereNumber('id')->whereNumber('feeId')->name('orders.update_fee');
        Route::delete('/orders/{id}/fees/{feeId}', [OrderController::class, 'destroyFee'])->whereNumber('id')->whereNumber('feeId')->name('orders.destroy_fee');
        Route::post('/orders/bulk', [OrderController::class, 'bulkAction'])->name('orders.bulk');
        Route::post('/orders/{id}/status', [OrderController::class, 'updateStatus'])->whereNumber('id')->name('orders.update_status');
        Route::post('/orders/{id}/toggle-override', [OrderController::class, 'toggleOverride'])->whereNumber('id')->name('orders.toggle_override');
        Route::get('/orders/{id}/edit', [OrderController::class, 'edit'])->whereNumber('id')->name('orders.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/orders/{id}', [OrderController::class, 'update'])->whereNumber('id')->name('orders.update');
        Route::delete('/orders/{id}', [OrderController::class, 'destroy'])->whereNumber('id')->name('orders.destroy');
        Route::post('/orders/{id}/payments', [OrderPaymentController::class, 'storePayment'])->whereNumber('id')->name('orders.store_payment');
        Route::delete('/orders/{id}/payments/{paymentId}', [OrderPaymentController::class, 'destroyPayment'])->whereNumber('id')->whereNumber('paymentId')->name('orders.destroy_payment');
        Route::post('/orders/{id}/toggle-payments', [OrderPaymentController::class, 'togglePayments'])->whereNumber('id')->name('orders.toggle_payments');
    });
});
