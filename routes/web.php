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

    // Universal admin scheduler — Automations tab on each integration
    // settings page POSTs here. The route is shared across integrations
    // because the underlying table is uniform; permission gating happens
    // on the parent integration's settings page.
    Route::post('/automations/batch', [\App\Http\Controllers\AutomationsController::class, 'batchUpdate'])
        ->name('automations.batch_update');
    Route::post('/automations/{id}', [\App\Http\Controllers\AutomationsController::class, 'update'])
        ->whereNumber('id')
        ->name('automations.update');
    Route::get('/dashboard/chart-data', [DashboardController::class, 'chartData'])->name('dashboard.chart_data');
    Route::get('/dashboard/platform-data', [DashboardController::class, 'platformData'])->name('dashboard.platform_data');

    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->name('search');

    // ─────────────────────────────────────────────────────────────────────
    //  Phase 4.5 — view/manage tier split for ALL core resources.
    //
    //  Read routes (display-only, no DB write):
    //    gated by 'view_X|manage_X' — granting EITHER tier suffices.
    //  Write routes (any mutation: POST/PUT/DELETE that persists state):
    //    gated by 'manage_X' — read-only operators MUST NOT trigger these.
    //
    //  A few inline endpoints look like writes (POST) but are pure read
    //  computations (lookup APIs, search). Those stay read-tier. The
    //  "Manage implies view" cascade lives in User::hasPermission, so a
    //  user with only manage_X still satisfies the view_X|manage_X gate.
    // ─────────────────────────────────────────────────────────────────────

    // Catalog (parent permission OR any child grants access via expansion)
    // ── Read-tier: lookups + read APIs (any catalog tier holder can use) ──
    Route::middleware(['perm:view_products|manage_products|view_categories|manage_categories|view_manufacturers|manage_manufacturers|view_options|manage_options|manage_catalog'])->group(function () {
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

    // ── Products — READ ──
    // {id} is constrained to numeric. /products/create + /products/images/*
    // live in the write-tier group below; without the constraint they would
    // be caught by /products/{id}/sales' router fall-through.
    Route::middleware(['perm:view_products|manage_products'])->group(function () {
        Route::get('/catalog/products', [ProductController::class, 'index'])->name('products.index');
        Route::get('/catalog/products/{id}/sales', [ProductController::class, 'salesHistory'])->whereNumber('id')->name('products.sales');
        Route::get('/catalog/products/{id}/stock-history', [ProductController::class, 'stockHistory'])->whereNumber('id')->name('products.stock_history');
        // Browse the catalog's image library — read-only operation.
        Route::get('/catalog/products/images/browse', [ProductImageController::class, 'browse'])->name('products.images.browse');
    });

    // ── Products — WRITE ──
    Route::middleware(['perm:manage_products'])->group(function () {
        Route::get('/catalog/products/create', [ProductController::class, 'create'])->name('products.create');
        Route::post('/catalog/products', [ProductController::class, 'store'])->name('products.store');
        Route::post('/catalog/products/bulk', [ProductController::class, 'bulkAction'])->name('products.bulk');
        Route::post('/catalog/products/check-skus', [ProductController::class, 'checkSkus'])->name('products.check_skus');
        Route::get('/catalog/products/{id}/edit', [ProductController::class, 'edit'])->whereNumber('id')->name('products.edit');
        Route::put('/catalog/products/{id}', [ProductController::class, 'update'])->whereNumber('id')->name('products.update');
        Route::delete('/catalog/products/{id}', [ProductController::class, 'destroy'])->whereNumber('id')->name('products.destroy');

        // Product images — uploads, imports, deletions all mutate storage.
        Route::post('/catalog/products/images/upload', [ProductImageController::class, 'upload'])->name('products.images.upload');
        Route::post('/catalog/products/images/import-url', [ProductImageController::class, 'importUrl'])->name('products.images.import_url');
        Route::post('/catalog/products/images/upload-to-catalog', [ProductImageController::class, 'uploadToCatalog'])->name('products.images.upload_to_catalog');
        Route::post('/catalog/products/images/import-url-to-catalog', [ProductImageController::class, 'importUrlToCatalog'])->name('products.images.import_url_to_catalog');
        Route::post('/catalog/products/images/delete', [ProductImageController::class, 'deleteFromCatalog'])->name('products.images.delete');

        // Product options
        Route::get('/catalog/products/{id}/options', [ProductOptionController::class, 'edit'])->whereNumber('id')->name('products.options.edit');
        Route::post('/catalog/products/{id}/options', [ProductOptionController::class, 'update'])->whereNumber('id')->name('products.options.update');
    });

    // ── Options — READ ──
    Route::middleware(['perm:view_options|manage_options'])->group(function () {
        Route::get('/catalog/options', [OptionController::class, 'index'])->name('options.index');
    });
    // ── Options — WRITE ──
    Route::middleware(['perm:manage_options'])->group(function () {
        Route::get('/catalog/options/create', [OptionController::class, 'create'])->name('options.create');
        Route::post('/catalog/options', [OptionController::class, 'store'])->name('options.store');
        Route::get('/catalog/options/{id}/edit', [OptionController::class, 'edit'])->name('options.edit');
        Route::put('/catalog/options/{id}', [OptionController::class, 'update'])->name('options.update');
        Route::delete('/catalog/options/{id}', [OptionController::class, 'destroy'])->name('options.destroy');
        Route::post('/catalog/options/bulk-delete', [OptionController::class, 'bulkDestroy'])->name('options.bulk_delete');
        Route::delete('/catalog/options/{optionId}/values/{valueId}', [OptionController::class, 'destroyValue'])->name('options.values.destroy');
    });

    // ── Manufacturers — READ ──
    Route::middleware(['perm:view_manufacturers|manage_manufacturers'])->group(function () {
        Route::get('/catalog/manufacturers', [ManufacturerController::class, 'index'])->name('manufacturers.index');
    });
    // ── Manufacturers — WRITE ──
    Route::middleware(['perm:manage_manufacturers'])->group(function () {
        Route::get('/catalog/manufacturers/create', [ManufacturerController::class, 'create'])->name('manufacturers.create');
        Route::post('/catalog/manufacturers', [ManufacturerController::class, 'store'])->name('manufacturers.store');
        Route::get('/catalog/manufacturers/{id}/edit', [ManufacturerController::class, 'edit'])->name('manufacturers.edit');
        Route::put('/catalog/manufacturers/{id}', [ManufacturerController::class, 'update'])->name('manufacturers.update');
        Route::delete('/catalog/manufacturers/{id}', [ManufacturerController::class, 'destroy'])->name('manufacturers.destroy');
        Route::post('/catalog/manufacturers/bulk-delete', [ManufacturerController::class, 'bulkDestroy'])->name('manufacturers.bulk_delete');
    });

    // ── Categories — READ ──
    Route::middleware(['perm:view_categories|manage_categories'])->group(function () {
        Route::get('/catalog/categories', [CategoryController::class, 'index'])->name('categories.index');
    });
    // ── Categories — WRITE ──
    Route::middleware(['perm:manage_categories'])->group(function () {
        Route::get('/catalog/categories/create', [CategoryController::class, 'create'])->name('categories.create');
        Route::post('/catalog/categories', [CategoryController::class, 'store'])->name('categories.store');
        Route::post('/catalog/categories/bulk', [CategoryController::class, 'bulkAction'])->name('categories.bulk');
        Route::get('/catalog/categories/{id}/edit', [CategoryController::class, 'edit'])->name('categories.edit');
        Route::put('/catalog/categories/{id}', [CategoryController::class, 'update'])->name('categories.update');
        Route::delete('/catalog/categories/{id}', [CategoryController::class, 'destroy'])->name('categories.destroy');
    });



    // ── Users — READ ──
    Route::middleware(['perm:view_users|manage_users'])->group(function () {
        Route::get('/settings/users', [UserController::class, 'index'])->name('users.index');
    });
    // ── Users — WRITE ──
    Route::middleware(['perm:manage_users'])->group(function () {
        Route::get('/settings/users/create', [UserController::class, 'create'])->name('users.create');
        Route::post('/settings/users', [UserController::class, 'store'])->name('users.store');
        Route::get('/settings/users/{id}/edit', [UserController::class, 'edit'])->name('users.edit');
        Route::put('/settings/users/{id}', [UserController::class, 'update'])->name('users.update');
        Route::delete('/settings/users/{id}', [UserController::class, 'destroy'])->name('users.destroy');
    });

    // ── User Groups — READ ──
    Route::middleware(['perm:view_user_groups|manage_user_groups'])->group(function () {
        Route::get('/settings/user-groups', [UserGroupController::class, 'index'])->name('user_groups.index');
    });
    // ── User Groups — WRITE ──
    Route::middleware(['perm:manage_user_groups'])->group(function () {
        Route::get('/settings/user-groups/create', [UserGroupController::class, 'create'])->name('user_groups.create');
        Route::post('/settings/user-groups', [UserGroupController::class, 'store'])->name('user_groups.store');
        Route::get('/settings/user-groups/{id}/edit', [UserGroupController::class, 'edit'])->name('user_groups.edit');
        Route::put('/settings/user-groups/{id}', [UserGroupController::class, 'update'])->name('user_groups.update');
        Route::delete('/settings/user-groups/{id}', [UserGroupController::class, 'destroy'])->name('user_groups.destroy');
    });

    // ── Website Settings + Currencies + Extensions + Error Log ─────────
    // Settings + Currencies — READ
    Route::middleware(['perm:view_website_settings|manage_website_settings'])->group(function () {
        Route::get('/settings', [SettingController::class, 'edit'])->name('settings.edit');
        Route::get('/settings/currencies', [CurrencyController::class, 'index'])->name('currencies.index');
        Route::get('/api/currencies', [CurrencyController::class, 'lookup'])->name('api.currencies');
        // Extensions browse — list of installed extensions (read).
        Route::get('/settings/extensions', [\App\Http\Controllers\ExtensionController::class, 'index'])->name('extensions.index');
    });

    // Settings + Currencies + Extensions — WRITE
    Route::middleware(['perm:manage_website_settings'])->group(function () {
        Route::post('/settings', [SettingController::class, 'update'])->name('settings.update');
        Route::post('/settings/mail/test', [SettingController::class, 'sendTestMail'])->name('settings.mail.test');

        // Currencies — writes (rate updates, CRUD)
        Route::get('/settings/currencies/create', [CurrencyController::class, 'create'])->name('currencies.create');
        Route::post('/settings/currencies', [CurrencyController::class, 'store'])->name('currencies.store');
        Route::get('/settings/currencies/{id}/edit', [CurrencyController::class, 'edit'])->name('currencies.edit');
        Route::put('/settings/currencies/{id}', [CurrencyController::class, 'update'])->name('currencies.update');
        Route::delete('/settings/currencies/{id}', [CurrencyController::class, 'destroy'])->name('currencies.destroy');
        Route::post('/settings/currencies/update-rates', [CurrencyController::class, 'updateRates'])->name('currencies.update_rates');

        // Extensions — install / toggle / reinstall / uninstall are writes.
        Route::post('/settings/extensions/install', [\App\Http\Controllers\ExtensionController::class, 'install'])->name('extensions.install');
        // {extension} (not {id}) because the param is the extension's
        // string slug like "pedallion", not a numeric DB id. The global
        // Route::pattern('id', '[0-9]+') in RouteServiceProvider would
        // otherwise reject every slug and 404 the toggle/reinstall.
        Route::post('/settings/extensions/{extension}/toggle', [\App\Http\Controllers\ExtensionController::class, 'toggle'])->name('extensions.toggle');
        Route::post('/settings/extensions/{extension}/reinstall', [\App\Http\Controllers\ExtensionController::class, 'reinstall'])->name('extensions.reinstall');
        Route::delete('/settings/extensions/{extension}', [\App\Http\Controllers\ExtensionController::class, 'uninstall'])->name('extensions.uninstall');
    });

    // ── Error Log — READ ──
    // Error Log is its own resource (separate `view_error_log` / `manage_error_log`
    // tiers). Viewing the log is read; clearing/test-firing are writes.
    Route::middleware(['perm:view_error_log|manage_error_log'])->group(function () {
        Route::get('/settings/error-log', [\App\Http\Controllers\ErrorLogController::class, 'index'])->name('error_log.index');
    });
    Route::middleware(['perm:manage_error_log'])->group(function () {
        Route::post('/settings/error-log/clear', [\App\Http\Controllers\ErrorLogController::class, 'clear'])->name('error_log.clear');
        Route::post('/settings/error-log/test', [\App\Http\Controllers\ErrorLogController::class, 'test'])->name('error_log.test');
    });


    // ── Orders — READ ──
    // Read-only operators can browse the list, view detail pages, and pull
    // server-rendered docs (payments report is a derived view). No status
    // changes, payments writes, fee CRUD, cost edits, or bulk actions.
    //
    // {id} is constrained to numeric so that literal segments declared in the
    // write-tier group below (/orders/create, /orders/bulk, etc.) win the
    // match order — otherwise Laravel's first-match-wins router would
    // dispatch them to OrderController@show with id="create".
    Route::middleware(['perm:view_orders|manage_orders'])->group(function () {
        Route::get('/sales/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/sales/orders/search-products', [OrderController::class, 'searchProducts'])->name('orders.search_products');
        Route::get('/sales/orders/{id}', [OrderController::class, 'show'])->whereNumber('id')->name('orders.show');
        Route::get('/sales/order-payments', [OrderController::class, 'paymentsReport'])->name('orders.payments_report');
    });

    // ── Orders — WRITE ──
    Route::middleware(['perm:manage_orders'])->group(function () {
        Route::get('/sales/orders/create', [OrderController::class, 'create'])->name('orders.create');
        Route::post('/sales/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::post('/sales/orders/{id}/update-product-cost', [OrderController::class, 'updateProductCost'])->whereNumber('id')->name('orders.update_product_cost');
        Route::post('/sales/orders/{id}/backfill-costs', [OrderController::class, 'backfillCosts'])->whereNumber('id')->name('orders.backfill_costs');
        Route::post('/sales/orders/{id}/update-shipping-cost', [OrderController::class, 'updateShippingCost'])->whereNumber('id')->name('orders.update_shipping_cost');
        Route::post('/sales/orders/{id}/fees', [OrderController::class, 'storeFee'])->whereNumber('id')->name('orders.store_fee');
        Route::put('/sales/orders/{id}/fees/{feeId}', [OrderController::class, 'updateFee'])->whereNumber('id')->whereNumber('feeId')->name('orders.update_fee');
        Route::delete('/sales/orders/{id}/fees/{feeId}', [OrderController::class, 'destroyFee'])->whereNumber('id')->whereNumber('feeId')->name('orders.destroy_fee');
        Route::post('/sales/orders/bulk', [OrderController::class, 'bulkAction'])->name('orders.bulk');
        Route::post('/sales/orders/{id}/status', [OrderController::class, 'updateStatus'])->whereNumber('id')->name('orders.update_status');
        Route::get('/sales/orders/{id}/edit', [OrderController::class, 'edit'])->whereNumber('id')->name('orders.edit');
        Route::put('/sales/orders/{id}', [OrderController::class, 'update'])->whereNumber('id')->name('orders.update');
        Route::delete('/sales/orders/{id}', [OrderController::class, 'destroy'])->whereNumber('id')->name('orders.destroy');
        Route::post('/sales/orders/{id}/payments', [OrderController::class, 'storePayment'])->whereNumber('id')->name('orders.store_payment');
        Route::delete('/sales/orders/{id}/payments/{paymentId}', [OrderController::class, 'destroyPayment'])->whereNumber('id')->whereNumber('paymentId')->name('orders.destroy_payment');
        Route::post('/sales/orders/{id}/toggle-payments', [OrderController::class, 'togglePayments'])->whereNumber('id')->name('orders.toggle_payments');
    });


    // ── Order Statuses — READ ──
    Route::middleware(['perm:view_order_statuses|manage_order_statuses'])->group(function () {
        Route::get('/sales/order-statuses', [OrderStatusController::class, 'index'])->name('order_statuses.index');
    });
    // ── Order Statuses — WRITE ──
    Route::middleware(['perm:manage_order_statuses'])->group(function () {
        Route::get('/sales/order-statuses/create', [OrderStatusController::class, 'create'])->name('order_statuses.create');
        Route::post('/sales/order-statuses', [OrderStatusController::class, 'store'])->name('order_statuses.store');
        Route::post('/sales/order-statuses/bulk', [OrderStatusController::class, 'bulkAction'])->name('order_statuses.bulk');
        Route::get('/sales/order-statuses/{id}/edit', [OrderStatusController::class, 'edit'])->name('order_statuses.edit');
        Route::put('/sales/order-statuses/{id}', [OrderStatusController::class, 'update'])->name('order_statuses.update');
        Route::delete('/sales/order-statuses/{id}', [OrderStatusController::class, 'destroy'])->name('order_statuses.destroy');
    });

});

require __DIR__.'/auth.php';
