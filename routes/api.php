<?php

use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware(['auth:sanctum', 'api.limits', 'api.audit'])->group(function () {
    Route::middleware('scope:products:read')->group(function () {
        Route::get('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'index']);
        Route::get('/products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'show']);
    });

    Route::middleware('scope:products:write')->group(function () {
        Route::put('/products/{id}/price', [\App\Http\Controllers\Api\V1\ProductController::class, 'updatePrice'])->whereNumber('id');
        Route::patch('/products/{id}', [\App\Http\Controllers\Api\V1\ProductController::class, 'update'])->whereNumber('id');
        Route::patch('/products/{id}/variations', [\App\Http\Controllers\Api\V1\ProductController::class, 'updateVariations'])->whereNumber('id');
        Route::post('/products', [\App\Http\Controllers\Api\V1\ProductController::class, 'store']);
    });

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
        Route::get('/receivables', [\App\Http\Controllers\Api\V1\ReceivableController::class, 'index']);
    });

    Route::middleware('scope:orders:write')->group(function () {
        Route::post('/receivables/payments', [\App\Http\Controllers\Api\V1\ReceivableController::class, 'recordPayment']);
    });
});
