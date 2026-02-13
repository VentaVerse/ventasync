<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ManufacturerController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\Api\CatalogController as ApiCatalogController;
use App\Http\Controllers\ShopeeController;
use App\Http\Controllers\ProductOptionController;
use App\Http\Controllers\OptionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGroupController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\LazadaController;
use App\Http\Controllers\ErrorLogController;

Route::get('/', [ShopeeController::class, 'root'])->name('root');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Catalog (parent permission)
    Route::middleware(['perm:manage_catalog'])->group(function () {
        // Products
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::post('/products/bulk', [ProductController::class, 'bulkAction'])->name('products.bulk');
        Route::get('/products/{id}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');

        // Product options
        Route::get('/products/{id}/options', [ProductOptionController::class, 'edit'])->name('products.options.edit');
        Route::post('/products/{id}/options', [ProductOptionController::class, 'update'])->name('products.options.update');

        // Option master
        Route::get('/options', [OptionController::class, 'index'])->name('options.index');
        Route::get('/options/create', [OptionController::class, 'create'])->name('options.create');
        Route::post('/options', [OptionController::class, 'store'])->name('options.store');
        Route::get('/options/{id}/edit', [OptionController::class, 'edit'])->name('options.edit');
        Route::put('/options/{id}', [OptionController::class, 'update'])->name('options.update');
        Route::delete('/options/{id}', [OptionController::class, 'destroy'])->name('options.destroy');
        Route::post('/options/bulk-delete', [OptionController::class, 'bulkDestroy'])->name('options.bulk_delete');
        Route::delete('/options/{optionId}/values/{valueId}', [OptionController::class, 'destroyValue'])->name('options.values.destroy');

        // Manufacturers
        Route::get('/manufacturers', [ManufacturerController::class, 'index'])->name('manufacturers.index');
        Route::get('/manufacturers/create', [ManufacturerController::class, 'create'])->name('manufacturers.create');
        Route::post('/manufacturers', [ManufacturerController::class, 'store'])->name('manufacturers.store');
        Route::get('/manufacturers/{id}/edit', [ManufacturerController::class, 'edit'])->name('manufacturers.edit');
        Route::put('/manufacturers/{id}', [ManufacturerController::class, 'update'])->name('manufacturers.update');
        Route::delete('/manufacturers/{id}', [ManufacturerController::class, 'destroy'])->name('manufacturers.destroy');
        Route::post('/manufacturers/bulk-delete', [ManufacturerController::class, 'bulkDestroy'])->name('manufacturers.bulk_delete');

        // Categories
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::post('/categories/bulk', [CategoryController::class, 'bulkAction'])->name('categories.bulk');
        Route::get('/categories/{id}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('/categories/{id}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');

        // Lookups
        Route::get('/lookup/manufacturers', [ManufacturerController::class, 'lookup'])->name('manufacturers.lookup');
        Route::get('/lookup/categories', [CategoryController::class, 'lookup'])->name('categories.lookup');

        // API endpoints used by product create/edit
        Route::get('/api/catalog/manufacturers', [ApiCatalogController::class, 'manufacturers'])->name('api.catalog.manufacturers');
        Route::get('/api/catalog/categories', [ApiCatalogController::class, 'categories'])->name('api.catalog.categories');
        Route::get('/api/catalog/options', [ApiCatalogController::class, 'options'])->name('api.catalog.options');
        Route::get('/api/catalog/options/{optionId}/values', [ApiCatalogController::class, 'optionValues'])->name('api.catalog.option_values');
    });

    // Marketplace API (parent permission)
    Route::middleware(['perm:manage_marketplace_api'])->group(function () {
        // Shopee (POC)
        Route::get('/shopee', [ShopeeController::class, 'index'])->name('shopee.index');
        Route::get('/shopee/authorize', [ShopeeController::class, 'redirectToShopeeAuth'])->name('shopee.authorize');
        Route::post('/shopee/save', [ShopeeController::class, 'save'])->name('shopee.save');
        Route::post('/shopee/auth-url', [ShopeeController::class, 'buildAuthUrl'])->name('shopee.auth_url');
        Route::post('/shopee/token/get', [ShopeeController::class, 'tokenGet'])->name('shopee.token_get');
        Route::post('/shopee/token/refresh', [ShopeeController::class, 'tokenRefresh'])->name('shopee.token_refresh');
        Route::post('/shopee/call', [ShopeeController::class, 'callApi'])->name('shopee.call_api');

        // Lazada (POC)
        Route::get('/lazada', [LazadaController::class, 'index'])->name('lazada.index');
        Route::get('/lazada/authorize', [LazadaController::class, 'redirectToAuth'])->name('lazada.authorize');
        Route::get('/lazada/callback', [LazadaController::class, 'callback'])->name('lazada.callback');
        Route::post('/lazada/save', [LazadaController::class, 'save'])->name('lazada.save');
        Route::post('/lazada/token/create', [LazadaController::class, 'tokenCreate'])->name('lazada.token_create');
        Route::post('/lazada/token/refresh', [LazadaController::class, 'tokenRefresh'])->name('lazada.token_refresh');
        Route::post('/lazada/call', [LazadaController::class, 'callApi'])->name('lazada.call_api');
    });

    // Settings (parent permission)
    Route::middleware(['perm:manage_settings'])->group(function () {
        // Users
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');

        // User groups
        Route::get('/user-groups', [UserGroupController::class, 'index'])->name('user_groups.index');
        Route::get('/user-groups/create', [UserGroupController::class, 'create'])->name('user_groups.create');
        Route::post('/user-groups', [UserGroupController::class, 'store'])->name('user_groups.store');
        Route::get('/user-groups/{id}/edit', [UserGroupController::class, 'edit'])->name('user_groups.edit');
        Route::put('/user-groups/{id}', [UserGroupController::class, 'update'])->name('user_groups.update');
        Route::delete('/user-groups/{id}', [UserGroupController::class, 'destroy'])->name('user_groups.destroy');

        // Website Setting
        Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');


        // Error Log
        Route::get('/error-log', [ErrorLogController::class, 'index'])->name('error_log.index');
        Route::post('/error-log/clear', [ErrorLogController::class, 'clear'])->name('error_log.clear');
        Route::post('/error-log/test', [ErrorLogController::class, 'test'])->name('error_log.test');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
    });
});

require __DIR__.'/auth.php';
