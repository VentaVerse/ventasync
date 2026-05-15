<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ManufacturerController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\Api\CatalogController as ApiCatalogController;
use App\Http\Controllers\ProductOptionController;
use App\Http\Controllers\OptionController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserGroupController;
use App\Http\Controllers\SettingController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\IntegrationController;

use App\Http\Controllers\CurrencyController;


// Root URL — core dispatcher. Delegates to any registered RootRouteHandler
// integration (Shopee claims it when ?code/?shop_id is present for OAuth),
// otherwise falls through to a redirect to /dashboard.
Route::get('/', [\App\Http\Controllers\HomeController::class, 'index'])->name('root');

Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');

    // Integrations hub (cards view) and orders entry-point (redirects to first
    // available marketplace tab; otherwise shows empty state)
    Route::get('/integrations', [IntegrationController::class, 'index'])->name('integrations.index');
    Route::get('/integrations/orders', [IntegrationController::class, 'orders'])->name('integrations.orders');
    Route::get('/integrations/module/{module}', [IntegrationController::class, 'module'])
        ->where('module', '[A-Za-z0-9_:-]+')
        ->name('integrations.module');
    Route::get('/dashboard/chart-data', [DashboardController::class, 'chartData'])->name('dashboard.chart_data');
    Route::get('/dashboard/platform-data', [DashboardController::class, 'platformData'])->name('dashboard.platform_data');

    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->name('search');

    // Catalog (parent permission or any child)
    Route::middleware(['perm:manage_catalog'])->group(function () {
        // Lookups & API (needed by multiple sub-sections)
        Route::get('/lookup/manufacturers', [ManufacturerController::class, 'lookup'])->name('manufacturers.lookup');
        Route::get('/lookup/categories', [CategoryController::class, 'lookup'])->name('categories.lookup');
        Route::get('/api/catalog/manufacturers', [ApiCatalogController::class, 'manufacturers'])->name('api.catalog.manufacturers');
        Route::post('/api/catalog/manufacturers', [ManufacturerController::class, 'storeInline'])
            ->middleware('perm:manage_manufacturers')
            ->name('api.catalog.manufacturers.store');
        Route::get('/api/catalog/categories', [ApiCatalogController::class, 'categories'])->name('api.catalog.categories');
        Route::get('/api/catalog/options', [ApiCatalogController::class, 'options'])->name('api.catalog.options');
        Route::get('/api/catalog/options/{optionId}/values', [ApiCatalogController::class, 'optionValues'])->name('api.catalog.option_values');
    });

    // Products
    Route::middleware(['perm:manage_products'])->group(function () {
        Route::get('/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/products', [ProductController::class, 'store'])->name('products.store');
        Route::post('/products/bulk', [ProductController::class, 'bulkAction'])->name('products.bulk');
        Route::post('/products/check-skus', [ProductController::class, 'checkSkus'])->name('products.check_skus');
        Route::get('/products/{id}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');

        // Product images (Image Manager)
        Route::post('/products/images/upload', [ProductImageController::class, 'upload'])->name('products.images.upload');
        Route::post('/products/images/import-url', [ProductImageController::class, 'importUrl'])->name('products.images.import_url');
        Route::get('/products/images/browse', [ProductImageController::class, 'browse'])->name('products.images.browse');
        Route::post('/products/images/upload-to-catalog', [ProductImageController::class, 'uploadToCatalog'])->name('products.images.upload_to_catalog');
        Route::post('/products/images/import-url-to-catalog', [ProductImageController::class, 'importUrlToCatalog'])->name('products.images.import_url_to_catalog');
        Route::post('/products/images/delete', [ProductImageController::class, 'deleteFromCatalog'])->name('products.images.delete');

        // Product sales history
        Route::get('/products/{id}/sales', [ProductController::class, 'salesHistory'])->name('products.sales');

        // Product stock history
        Route::get('/products/{id}/stock-history', [ProductController::class, 'stockHistory'])->name('products.stock_history');

        // Product options
        Route::get('/products/{id}/options', [ProductOptionController::class, 'edit'])->name('products.options.edit');
        Route::post('/products/{id}/options', [ProductOptionController::class, 'update'])->name('products.options.update');
    });

    // Options
    Route::middleware(['perm:manage_options'])->group(function () {
        Route::get('/options', [OptionController::class, 'index'])->name('options.index');
        Route::get('/options/create', [OptionController::class, 'create'])->name('options.create');
        Route::post('/options', [OptionController::class, 'store'])->name('options.store');
        Route::get('/options/{id}/edit', [OptionController::class, 'edit'])->name('options.edit');
        Route::put('/options/{id}', [OptionController::class, 'update'])->name('options.update');
        Route::delete('/options/{id}', [OptionController::class, 'destroy'])->name('options.destroy');
        Route::post('/options/bulk-delete', [OptionController::class, 'bulkDestroy'])->name('options.bulk_delete');
        Route::delete('/options/{optionId}/values/{valueId}', [OptionController::class, 'destroyValue'])->name('options.values.destroy');
    });

    // Manufacturers
    Route::middleware(['perm:manage_manufacturers'])->group(function () {
        Route::get('/manufacturers', [ManufacturerController::class, 'index'])->name('manufacturers.index');
        Route::get('/manufacturers/create', [ManufacturerController::class, 'create'])->name('manufacturers.create');
        Route::post('/manufacturers', [ManufacturerController::class, 'store'])->name('manufacturers.store');
        Route::get('/manufacturers/{id}/edit', [ManufacturerController::class, 'edit'])->name('manufacturers.edit');
        Route::put('/manufacturers/{id}', [ManufacturerController::class, 'update'])->name('manufacturers.update');
        Route::delete('/manufacturers/{id}', [ManufacturerController::class, 'destroy'])->name('manufacturers.destroy');
        Route::post('/manufacturers/bulk-delete', [ManufacturerController::class, 'bulkDestroy'])->name('manufacturers.bulk_delete');
    });

    // Categories
    Route::middleware(['perm:manage_categories'])->group(function () {
        Route::get('/categories', [CategoryController::class, 'index'])->name('categories.index');
        Route::get('/categories/create', [CategoryController::class, 'create'])->name('categories.create');
        Route::post('/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::post('/categories/bulk', [CategoryController::class, 'bulkAction'])->name('categories.bulk');
        Route::get('/categories/{id}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('/categories/{id}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/categories/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });



    // Users
    Route::middleware(['perm:manage_users'])->group(function () {
        Route::get('/users', [UserController::class, 'index'])->name('users.index');
        Route::get('/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/users/{id}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // User Groups
    Route::middleware(['perm:manage_user_groups'])->group(function () {
        Route::get('/user-groups', [UserGroupController::class, 'index'])->name('user_groups.index');
        Route::get('/user-groups/create', [UserGroupController::class, 'create'])->name('user_groups.create');
        Route::post('/user-groups', [UserGroupController::class, 'store'])->name('user_groups.store');
        Route::get('/user-groups/{id}/edit', [UserGroupController::class, 'edit'])->name('user_groups.edit');
        Route::put('/user-groups/{id}', [UserGroupController::class, 'update'])->name('user_groups.update');
        Route::delete('/user-groups/{id}', [UserGroupController::class, 'destroy'])->name('user_groups.destroy');
    });

    // Website Setting
    Route::middleware(['perm:manage_website_settings'])->group(function () {
        Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('/settings/purge-raw', [SettingController::class, 'purgeRaw'])->name('settings.purge_raw');
        Route::post('/settings/mail/test', [SettingController::class, 'sendTestMail'])->name('settings.mail.test');

        // Currencies
        Route::get('/currencies', [CurrencyController::class, 'index'])->name('currencies.index');
        Route::get('/currencies/create', [CurrencyController::class, 'create'])->name('currencies.create');
        Route::post('/currencies', [CurrencyController::class, 'store'])->name('currencies.store');
        Route::get('/currencies/{id}/edit', [CurrencyController::class, 'edit'])->name('currencies.edit');
        Route::put('/currencies/{id}', [CurrencyController::class, 'update'])->name('currencies.update');
        Route::delete('/currencies/{id}', [CurrencyController::class, 'destroy'])->name('currencies.destroy');
        Route::post('/currencies/update-rates', [CurrencyController::class, 'updateRates'])->name('currencies.update_rates');
        Route::get('/api/currencies', [CurrencyController::class, 'lookup'])->name('api.currencies');

        // Error Log
        Route::get('/error-log', [\App\Http\Controllers\ErrorLogController::class, 'index'])->name('error_log.index');
        Route::post('/error-log/clear', [\App\Http\Controllers\ErrorLogController::class, 'clear'])->name('error_log.clear');
        Route::post('/error-log/test', [\App\Http\Controllers\ErrorLogController::class, 'test'])->name('error_log.test');

        // Extensions
        Route::get('/extensions', [\App\Http\Controllers\ExtensionController::class, 'index'])->name('extensions.index');
        Route::post('/extensions/install', [\App\Http\Controllers\ExtensionController::class, 'install'])->name('extensions.install');
        Route::post('/extensions/{id}/toggle', [\App\Http\Controllers\ExtensionController::class, 'toggle'])->name('extensions.toggle');
        Route::post('/extensions/{id}/reinstall', [\App\Http\Controllers\ExtensionController::class, 'reinstall'])->name('extensions.reinstall');
        Route::delete('/extensions/{id}', [\App\Http\Controllers\ExtensionController::class, 'uninstall'])->name('extensions.uninstall');
    });


    // Orders
    Route::middleware(['perm:manage_orders'])->group(function () {
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::get('/orders/search-products', [OrderController::class, 'searchProducts'])->name('orders.search_products');
        Route::post('/orders/{id}/update-product-cost', [OrderController::class, 'updateProductCost'])->name('orders.update_product_cost');
        Route::post('/orders/{id}/backfill-costs', [OrderController::class, 'backfillCosts'])->name('orders.backfill_costs');
        Route::post('/orders/{id}/update-shipping-cost', [OrderController::class, 'updateShippingCost'])->name('orders.update_shipping_cost');
        Route::post('/orders/{id}/fees', [OrderController::class, 'storeFee'])->name('orders.store_fee');
        Route::put('/orders/{id}/fees/{feeId}', [OrderController::class, 'updateFee'])->name('orders.update_fee');
        Route::delete('/orders/{id}/fees/{feeId}', [OrderController::class, 'destroyFee'])->name('orders.destroy_fee');
        Route::post('/orders/bulk', [OrderController::class, 'bulkAction'])->name('orders.bulk');
        Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{id}/status', [OrderController::class, 'updateStatus'])->name('orders.update_status');
        Route::get('/orders/{id}/edit', [OrderController::class, 'edit'])->name('orders.edit');
        Route::put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update');
        Route::delete('/orders/{id}', [OrderController::class, 'destroy'])->name('orders.destroy');
        Route::post('/orders/{id}/payments', [OrderController::class, 'storePayment'])->name('orders.store_payment');
        Route::delete('/orders/{id}/payments/{paymentId}', [OrderController::class, 'destroyPayment'])->name('orders.destroy_payment');
        Route::post('/orders/{id}/toggle-payments', [OrderController::class, 'togglePayments'])->name('orders.toggle_payments');
        Route::get('/order-payments', [OrderController::class, 'paymentsReport'])->name('orders.payments_report');
    });


    // Order Statuses
    Route::middleware(['perm:manage_order_statuses'])->group(function () {
        Route::get('/order-statuses', [OrderStatusController::class, 'index'])->name('order_statuses.index');
        Route::get('/order-statuses/create', [OrderStatusController::class, 'create'])->name('order_statuses.create');
        Route::post('/order-statuses', [OrderStatusController::class, 'store'])->name('order_statuses.store');
        Route::post('/order-statuses/bulk', [OrderStatusController::class, 'bulkAction'])->name('order_statuses.bulk');
        Route::get('/order-statuses/{id}/edit', [OrderStatusController::class, 'edit'])->name('order_statuses.edit');
        Route::put('/order-statuses/{id}', [OrderStatusController::class, 'update'])->name('order_statuses.update');
        Route::delete('/order-statuses/{id}', [OrderStatusController::class, 'destroy'])->name('order_statuses.destroy');
    });

});

require __DIR__.'/auth.php';
