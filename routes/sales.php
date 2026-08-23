<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Sales\OrderController;

// IA migration (2026-08-08, Task 4): Sales section. Required from
// routes/web.php inside the ['auth'] group. Order Statuses used to sit
// physically after Orders in the monolithic web.php but is classified as
// Settings (spec decision 2, Task 3): it now lives in routes/settings.php,
// not here; this file carries Orders only. Every route already shared the
// /sales segment, so the whole file is wrapped in Route::prefix('sales')
// with the redundant literal dropped from each line.

Route::prefix('sales')->group(function () {
    // ── Orders — READ ──
    // Read-only operators can browse the list, view detail pages, and pull
    // server-rendered docs (payments report is a derived view). No status
    // changes, payments writes, fee CRUD, cost edits, or bulk actions.
    //
    // {id} is constrained to numeric so that literal segments declared in the
    // write-tier group below (/orders/create, /orders/bulk, etc.) win the
    // match order — otherwise Laravel's first-match-wins router would
    // dispatch them to OrderController@show with id="create".
    Route::group([], function () {
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/search-products', [OrderController::class, 'searchProducts'])->name('orders.search_products');
        Route::get('/orders/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('orders.show');
        Route::get('/order-payments', [OrderController::class, 'paymentsReport'])->name('orders.payments_report');
    });

    // ── Orders — WRITE ──
    Route::group([], function () {
        Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
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
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
        Route::put('/orders/{id}', [OrderController::class, 'update'])->whereNumber('id')->name('orders.update');
        Route::delete('/orders/{id}', [OrderController::class, 'destroy'])->whereNumber('id')->name('orders.destroy');
        Route::post('/orders/{id}/payments', [OrderController::class, 'storePayment'])->whereNumber('id')->name('orders.store_payment');
        Route::delete('/orders/{id}/payments/{paymentId}', [OrderController::class, 'destroyPayment'])->whereNumber('id')->whereNumber('paymentId')->name('orders.destroy_payment');
        Route::post('/orders/{id}/toggle-payments', [OrderController::class, 'togglePayments'])->whereNumber('id')->name('orders.toggle_payments');
    });
});
