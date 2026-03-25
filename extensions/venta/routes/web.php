<?php

use Extensions\venta\Controllers\VentaController;
use Extensions\venta\Controllers\VentaProductGroupController;
use Extensions\venta\Controllers\VentaOrderController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth', 'perm:manage_venta'])->group(function () {
    // Settings (store CRUD + status mapping only)
    Route::get('/venta', [VentaController::class, 'index'])->name('ext.venta.index');
    Route::post('/venta/save', [VentaController::class, 'save'])->name('ext.venta.save');
    Route::delete('/venta/{id}', [VentaController::class, 'destroy'])->name('ext.venta.destroy');
    Route::post('/venta/test', [VentaController::class, 'testConnection'])->name('ext.venta.test');
    Route::post('/venta/save-status-map', [VentaController::class, 'saveOrderStatusMap'])->name('ext.venta.save_status_map');
    Route::post('/venta/fetch-statuses', [VentaController::class, 'fetchVentaStatuses'])->name('ext.venta.fetch_statuses');

    // Fetch categories/brands from Venta API (AJAX)
    Route::post('/venta/fetch-categories', [VentaController::class, 'fetchCategories'])->name('ext.venta.fetch_categories');
    Route::post('/venta/fetch-brands', [VentaController::class, 'fetchBrands'])->name('ext.venta.fetch_brands');

    // Product Groups (per-store)
    Route::get('/venta/{store}/product-groups', [VentaProductGroupController::class, 'index'])->name('ext.venta.product-groups.index');
    Route::get('/venta/{store}/product-groups/create', [VentaProductGroupController::class, 'create'])->name('ext.venta.product-groups.create');
    Route::post('/venta/{store}/product-groups', [VentaProductGroupController::class, 'store'])->name('ext.venta.product-groups.store');
    Route::get('/venta/{store}/product-groups/{group}/edit', [VentaProductGroupController::class, 'edit'])->name('ext.venta.product-groups.edit');
    Route::put('/venta/{store}/product-groups/{group}', [VentaProductGroupController::class, 'update'])->name('ext.venta.product-groups.update');
    Route::delete('/venta/{store}/product-groups/{group}', [VentaProductGroupController::class, 'destroy'])->name('ext.venta.product-groups.destroy');

    // Product Group — product listing & management
    Route::get('/venta/{store}/product-groups/{group}/products', [VentaProductGroupController::class, 'products'])->name('ext.venta.product-groups.products');
    Route::post('/venta/{store}/product-groups/{group}/push', [VentaProductGroupController::class, 'pushProducts'])->name('ext.venta.product-groups.push');
    Route::post('/venta/{store}/product-groups/{group}/push-stock', [VentaProductGroupController::class, 'pushStock'])->name('ext.venta.product-groups.push-stock');
    Route::post('/venta/{store}/product-groups/{group}/push-prices', [VentaProductGroupController::class, 'pushPrices'])->name('ext.venta.product-groups.push-prices');
    Route::post('/venta/{store}/product-groups/{group}/add-products', [VentaProductGroupController::class, 'addProducts'])->name('ext.venta.product-groups.addProducts');
    Route::post('/venta/{store}/product-groups/{group}/mass-remove', [VentaProductGroupController::class, 'massRemove'])->name('ext.venta.product-groups.mass-remove');
    Route::post('/venta/{store}/product-groups/{group}/sync-ids', [VentaProductGroupController::class, 'syncIds'])->name('ext.venta.product-groups.sync-ids');
    Route::delete('/venta/{store}/product-groups/{group}/products/{product}', [VentaProductGroupController::class, 'removeProduct'])->name('ext.venta.product-groups.removeProduct');

    // Product Group — per-product actions
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/sync-id', [VentaProductGroupController::class, 'syncId'])->name('ext.venta.product-groups.sync-id');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/unlink', [VentaProductGroupController::class, 'unlinkProduct'])->name('ext.venta.product-groups.unlink');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/link', [VentaProductGroupController::class, 'linkProduct'])->name('ext.venta.product-groups.link');

    // Product search (JSON API)
    Route::get('/venta/{store}/product-groups/search-products', [VentaProductGroupController::class, 'searchProducts'])->name('ext.venta.product-groups.searchProducts');
});

// Venta Orders (separate permission)
Route::middleware(['auth', 'perm:manage_venta_orders'])->group(function () {
    Route::get('/venta/{store}/orders', [VentaOrderController::class, 'index'])->name('ext.venta.orders.index');
    Route::post('/venta/{store}/orders/fetch', [VentaOrderController::class, 'fetch'])->name('ext.venta.orders.fetch');
    Route::get('/venta/{store}/orders/{order}', [VentaOrderController::class, 'show'])->name('ext.venta.orders.show');
});
