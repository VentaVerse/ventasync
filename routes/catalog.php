<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductImageController;
use App\Http\Controllers\Catalog\ProductOptionController;
use App\Http\Controllers\Catalog\ManufacturerController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Api\CatalogController as ApiCatalogController;

// IA migration (2026-08-08, Task 4): Catalogue section. Required from
// routes/web.php inside the same ['auth'] group the rest of core used to
// share as one file. URIs are unchanged from pre-split web.php: this file
// only regroups the registrations and, where every route in a block already
// shared the /catalog segment, wraps them in Route::prefix('catalog') and
// drops the now-redundant literal from each line. The lookup and
// /api/catalog/* endpoints below do NOT start with /catalog (their second
// segment is "catalog", not their first) so they stay outside the prefix
// group with their original full literals, unchanged.

// ── Catalog lookups + read APIs (any catalog tier holder can use) ──
Route::middleware(['perm:view_products|manage_products|view_categories|manage_categories|view_manufacturers|manage_manufacturers|manage_catalog'])->group(function () {
    Route::get('/lookup/manufacturers', [ManufacturerController::class, 'lookup'])->name('manufacturers.lookup');
    Route::get('/lookup/categories', [CategoryController::class, 'lookup'])->name('categories.lookup');
    Route::get('/api/catalog/manufacturers', [ApiCatalogController::class, 'manufacturers'])->name('api.catalog.manufacturers');
    Route::get('/api/catalog/categories', [ApiCatalogController::class, 'categories'])->name('api.catalog.categories');
    Route::get('/api/catalog/options', [ApiCatalogController::class, 'options'])->name('api.catalog.options');
    Route::get('/api/catalog/options/{optionId}/values', [ApiCatalogController::class, 'optionValues'])->name('api.catalog.option_values');
});
// Inline write: storing a manufacturer from the catalog UI requires the
// manage_manufacturers tier specifically.
Route::middleware(['perm:manage_manufacturers'])->group(function () {
    Route::post('/api/catalog/manufacturers', [ManufacturerController::class, 'storeInline'])->name('api.catalog.manufacturers.store');
});

Route::prefix('catalog')->group(function () {
    // ── Products — READ ──
    // {id} is constrained to numeric. /products/create + /products/images/*
    // live in the write-tier group below; without the constraint they would
    // be caught by /products/{id}/sales' router fall-through.
    Route::middleware(['perm:view_products|manage_products'])->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{id}/sales', [ProductController::class, 'salesHistory'])->whereNumber('id')->name('products.sales');
        Route::get('/products/{id}/stock-history', [ProductController::class, 'stockHistory'])->whereNumber('id')->name('products.stock_history');
        // Browse the catalog's image library — read-only operation.
        Route::get('/products/images/browse', [ProductImageController::class, 'browse'])->name('products.images.browse');
    });

    // ── Products — WRITE ──
    Route::middleware(['perm:manage_products'])->group(function () {
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::post('/products/bulk', [ProductController::class, 'bulkAction'])->name('products.bulk');
        Route::post('/products/check-skus', [ProductController::class, 'checkSkus'])->name('products.check_skus');
        // Making a folder is a WRITE to the library, so it sits in the
        // manage tier beside upload and delete rather than with browse.
        Route::post('/products/images/folder', [ProductImageController::class, 'createFolder'])->name('products.images.create_folder');
        Route::get('/products/{id}/edit', [ProductController::class, 'edit'])->whereNumber('id')->name('products.edit');
        Route::put('/products/{id}', [ProductController::class, 'update'])->whereNumber('id')->name('products.update');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])->whereNumber('id')->name('products.destroy');

        // Product images — uploads, imports, deletions all mutate storage.
        Route::post('/products/images/upload', [ProductImageController::class, 'upload'])->name('products.images.upload');
        Route::post('/products/images/import-url', [ProductImageController::class, 'importUrl'])->name('products.images.import_url');
        Route::post('/products/images/upload-to-catalog', [ProductImageController::class, 'uploadToCatalog'])->name('products.images.upload_to_catalog');
        Route::post('/products/images/import-url-to-catalog', [ProductImageController::class, 'importUrlToCatalog'])->name('products.images.import_url_to_catalog');
        Route::post('/products/images/delete', [ProductImageController::class, 'deleteFromCatalog'])->name('products.images.delete');

        // Product options
        Route::get('/products/{id}/options', [ProductOptionController::class, 'edit'])->whereNumber('id')->name('products.options.edit');
        Route::post('/products/{id}/options', [ProductOptionController::class, 'update'])->whereNumber('id')->name('products.options.update');
    });

    // Standalone catalog Options page removed (2026-06) — options/variations are
    // created at the product level (ProductOptionController::resolveOptionId,
    // find-or-create by name). The OptionController, its routes, and the
    // options index view were dead weight. Product-level option editing lives
    // under products.options.* (gated by manage_products), unaffected.

    // ── Manufacturers — READ ──
    Route::middleware(['perm:view_manufacturers|manage_manufacturers'])->group(function () {
        Route::get('/manufacturers', [ManufacturerController::class, 'index'])->name('manufacturers.index');
    });
    // ── Manufacturers — WRITE ──
    Route::middleware(['perm:manage_manufacturers'])->group(function () {
        Route::get('/manufacturers/create', [ManufacturerController::class, 'create'])->name('manufacturers.create');
        Route::post('/manufacturers', [ManufacturerController::class, 'store'])->name('manufacturers.store');
        Route::get('/manufacturers/{id}/edit', [ManufacturerController::class, 'edit'])->name('manufacturers.edit');
        Route::put('/manufacturers/{id}', [ManufacturerController::class, 'update'])->name('manufacturers.update');
        Route::delete('/manufacturers/{id}', [ManufacturerController::class, 'destroy'])->name('manufacturers.destroy');
        Route::post('/manufacturers/bulk-delete', [ManufacturerController::class, 'bulkDestroy'])->name('manufacturers.bulk_delete');
    });

    // ── Categories — READ ──
    Route::middleware(['perm:view_categories|manage_categories'])->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    });
    // ── Categories — WRITE ──
    Route::middleware(['perm:manage_categories'])->group(function () {
        Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::post('/categories/bulk', [CategoryController::class, 'bulkAction'])->name('categories.bulk');
        Route::get('/categories/{id}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('/categories/{id}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });
});
