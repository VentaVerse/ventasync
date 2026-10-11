<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Catalog\ProductController;
use App\Http\Controllers\Catalog\ProductImageController;
use App\Http\Controllers\Catalog\ProductVariationController;
use App\Http\Controllers\Catalog\BrandController;
use App\Http\Controllers\Catalog\CategoryController;
use App\Http\Controllers\Api\CatalogController as ApiCatalogController;

Route::group([], function () {
    Route::get('/lookup/brands', [BrandController::class, 'lookup'])->name('brands.lookup');
    Route::get('/lookup/categories', [CategoryController::class, 'lookup'])->name('categories.lookup');
    Route::get('/api/catalog/brands', [ApiCatalogController::class, 'brands'])->name('api.catalog.brands');
    Route::get('/api/catalog/categories', [ApiCatalogController::class, 'categories'])->name('api.catalog.categories');
    Route::get('/api/catalog/variation-types', [ApiCatalogController::class, 'variationTypes'])->name('api.catalog.variation_types');
    Route::get('/api/catalog/variation-types/{variationTypeId}/values', [ApiCatalogController::class, 'variationValues'])->name('api.catalog.variation_values');
});
Route::group([], function () {
    Route::post('/api/catalog/brands', [BrandController::class, 'storeInline'])->name('api.catalog.brands.store');
});

Route::prefix('catalog')->group(function () {
    Route::group([], function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/{id}/sales', [ProductController::class, 'salesHistory'])->whereNumber('id')->name('products.sales');
        Route::get('/products/{id}/stock-history', [ProductController::class, 'stockHistory'])->whereNumber('id')->name('products.stock_history');
        Route::get('/products/images/browse', [ProductImageController::class, 'browse'])->name('products.images.browse');
        Route::get('/products/images/thumb', [ProductImageController::class, 'thumb'])->name('products.images.thumb');
    });

    Route::group([], function () {
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::post('/products/bulk', [ProductController::class, 'bulkAction'])->name('products.bulk');
        Route::post('/products/check-skus', [ProductController::class, 'checkSkus'])->name('products.check_skus');
        Route::post('/products/images/folder', [ProductImageController::class, 'createFolder'])->name('products.images.create_folder');
        Route::get('/products/{id}/edit', [ProductController::class, 'edit'])->whereNumber('id')->name('products.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/products/{id}', [ProductController::class, 'update'])->whereNumber('id')->name('products.update');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])->whereNumber('id')->name('products.destroy');

        Route::post('/products/images/upload', [ProductImageController::class, 'upload'])->name('products.images.upload');
        Route::post('/products/images/import-url', [ProductImageController::class, 'importUrl'])->name('products.images.import_url');
        Route::post('/products/images/upload-to-catalog', [ProductImageController::class, 'uploadToCatalog'])->name('products.images.upload_to_catalog');
        Route::post('/products/images/import-url-to-catalog', [ProductImageController::class, 'importUrlToCatalog'])->name('products.images.import_url_to_catalog');
        Route::post('/products/images/delete', [ProductImageController::class, 'deleteFromCatalog'])->name('products.images.delete');

        Route::post('/products/video/upload', [\App\Http\Controllers\Catalog\ProductVideoController::class, 'upload'])->name('products.video.upload');
        Route::post('/products/video/delete', [\App\Http\Controllers\Catalog\ProductVideoController::class, 'destroy'])->name('products.video.delete');

        Route::get('/products/{id}/variations', [ProductVariationController::class, 'edit'])->whereNumber('id')->name('products.variations.edit')
        ->defaults('permission_tier', 'manage');
        Route::post('/products/{id}/variations', [ProductVariationController::class, 'update'])->whereNumber('id')->name('products.variations.update');
    });

    Route::group([], function () {
        Route::get('/brands', [BrandController::class, 'index'])->name('brands.index');
    });
    Route::group([], function () {
        Route::get('/brands/create', [BrandController::class, 'create'])->name('brands.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/brands', [BrandController::class, 'store'])->name('brands.store');
        Route::get('/brands/{id}/edit', [BrandController::class, 'edit'])->name('brands.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/brands/{id}', [BrandController::class, 'update'])->name('brands.update');
        Route::delete('/brands/{id}', [BrandController::class, 'destroy'])->name('brands.destroy');
        Route::post('/brands/bulk-delete', [BrandController::class, 'bulkDestroy'])->name('brands.bulk_delete');
    });

    Route::group([], function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
    });
    Route::group([], function () {
        Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::post('/categories/bulk', [CategoryController::class, 'bulkAction'])->name('categories.bulk');
        Route::get('/categories/{id}/edit', [CategoryController::class, 'edit'])->name('categories.edit')
        ->defaults('permission_tier', 'manage');
        Route::put('/categories/{id}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });
});
