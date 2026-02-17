<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
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
use App\Http\Controllers\LazadaProductController;
use App\Http\Controllers\LazadaCategoryController;
use App\Http\Controllers\LazadaCategoryAttributeController;
use App\Http\Controllers\LazadaOrderController;
use App\Http\Controllers\LazadaProfileController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\OrderController;

Route::get('/', [ShopeeController::class, 'root'])->name('root');

// Lazada OAuth callback must be publicly accessible so Lazada can redirect back with `code`.
// Keeping it outside auth/perm middleware prevents losing the code behind login redirects.
Route::get('/lazada/callback', [LazadaController::class, 'callback'])->name('lazada.callback');

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

        // Product images (Image Manager)
        Route::post('/products/images/upload', [ProductImageController::class, 'upload'])->name('products.images.upload');
        Route::post('/products/images/import-url', [ProductImageController::class, 'importUrl'])->name('products.images.import_url');
        Route::get('/products/images/browse', [ProductImageController::class, 'browse'])->name('products.images.browse');
        Route::post('/products/images/upload-to-catalog', [ProductImageController::class, 'uploadToCatalog'])->name('products.images.upload_to_catalog');


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
        // NOTE: /lazada/callback is declared above (public route)
        Route::post('/lazada/save', [LazadaController::class, 'save'])->name('lazada.save');
        Route::post('/lazada/token/create', [LazadaController::class, 'tokenCreate'])->name('lazada.token_create');
        Route::post('/lazada/token/refresh', [LazadaController::class, 'tokenRefresh'])->name('lazada.token_refresh');
        Route::post('/lazada/call', [LazadaController::class, 'callApi'])->name('lazada.call_api');
        Route::post('/lazada/explorer/run', [LazadaController::class, 'explorerRun'])->name('lazada.explorer_run');
        Route::post('/lazada/packs/run', [LazadaController::class, 'packsRun'])->name('lazada.packs_run');

        // Lazada Catalog POC
        Route::post('/lazada/category/tree', [LazadaController::class, 'categoryTree'])->name('lazada.category_tree');
        Route::post('/lazada/category/attributes', [LazadaController::class, 'categoryAttributes'])->name('lazada.category_attributes');
        Route::post('/lazada/category/brands', [LazadaController::class, 'brandsQuery'])->name('lazada.brands_query');
        Route::post('/lazada/products/get', [LazadaController::class, 'productsGet'])->name('lazada.products_get');

        // POC: Build payload + create product (variants/options)
        Route::post('/lazada/product/payload-preview', [LazadaController::class, 'productPayloadPreview'])->name('lazada.product_payload_preview');
        Route::post('/lazada/product/create', [LazadaController::class, 'productCreate'])->name('lazada.product_create');
        Route::post('/lazada/orders/get', [LazadaController::class, 'ordersGet'])->name('lazada.orders_get');
        Route::post('/lazada/order/items/get', [LazadaController::class, 'orderItemsGet'])->name('lazada.order_items_get');
        Route::post('/lazada/order/awb/pdf', [LazadaController::class, 'awbPdfGet'])->name('lazada.awb_pdf_get');
        Route::get('/lazada/orders', [LazadaOrderController::class, 'index'])->name('lazada.orders.index');
        Route::get('/lazada/orders/{orderId}', [LazadaOrderController::class, 'show'])->name('lazada.orders.show');
        Route::post('/lazada/orders/fetch', [LazadaOrderController::class, 'fetch'])->name('lazada.orders.fetch');
        Route::post('/lazada/orders/update-statuses', [LazadaOrderController::class, 'updateStatuses'])->name('lazada.orders.update_statuses');
        Route::post('/lazada/orders/reset', [LazadaOrderController::class, 'reset'])->name('lazada.orders.reset');
        Route::post('/lazada/orders/{orderId}/pack', [LazadaOrderController::class, 'pack'])->name('lazada.orders.pack');
        Route::post('/lazada/orders/{orderId}/pack-print', [LazadaOrderController::class, 'packAndPrint'])->name('lazada.orders.pack_print');
        Route::post('/lazada/orders/{orderId}/recreate-package', [LazadaOrderController::class, 'recreatePackage'])->name('lazada.orders.recreate_package');
        Route::post('/lazada/orders/{orderId}/rts', [LazadaOrderController::class, 'rts'])->name('lazada.orders.rts');
        Route::get('/lazada/orders/{orderId}/ship-print', [LazadaOrderController::class, 'shipAndPrint'])->name('lazada.orders.ship_print');
        Route::post('/lazada/orders/{orderId}/ship-print', [LazadaOrderController::class, 'shipAndPrintPost'])->name('lazada.orders.ship_print_post');
        Route::get('/lazada/orders/{orderId}/awb', [LazadaOrderController::class, 'awbPdf'])->name('lazada.orders.awb');
        Route::get('/lazada/orders/{orderId}/packing-list', [LazadaOrderController::class, 'packingList'])->name('lazada.orders.packing_list');
        Route::get('/lazada/orders/{orderId}/pick-list', [LazadaOrderController::class, 'pickList'])->name('lazada.orders.pick_list');
        Route::get('/lazada/orders/{orderId}/logistics-trace', [LazadaOrderController::class, 'logisticsTrace'])->name('lazada.orders.logistics_trace');
        Route::get('/lazada/orders/{orderId}/cancel-reasons', [LazadaOrderController::class, 'cancelReasons'])->name('lazada.orders.cancel_reasons');
        Route::post('/lazada/orders/{orderId}/cancel', [LazadaOrderController::class, 'cancel'])->name('lazada.orders.cancel');

        // Lazada Profiles (mass-map category + brand + attributes by catalog category)
        Route::get('/lazada/profiles', [LazadaProfileController::class, 'index'])->name('lazada.profiles.index');
        Route::get('/lazada/profiles/create', [LazadaProfileController::class, 'create'])->name('lazada.profiles.create');
        Route::post('/lazada/profiles', [LazadaProfileController::class, 'store'])->name('lazada.profiles.store');
        Route::get('/lazada/profiles/{id}/edit', [LazadaProfileController::class, 'edit'])->whereNumber('id')->name('lazada.profiles.edit');
        Route::put('/lazada/profiles/{id}', [LazadaProfileController::class, 'update'])->whereNumber('id')->name('lazada.profiles.update');
        Route::delete('/lazada/profiles/{id}', [LazadaProfileController::class, 'destroy'])->whereNumber('id')->name('lazada.profiles.destroy');
        Route::post('/lazada/profiles/{id}/sync-products', [LazadaProfileController::class, 'syncProducts'])->whereNumber('id')->name('lazada.profiles.sync_products');
        Route::post('/lazada/profiles/{id}/template/sync', [LazadaProfileController::class, 'syncTemplate'])->whereNumber('id')->name('lazada.profiles.template_sync');

        // Lazada Phase 2: Listings + Category attribute mapping
        // Lazada Products (new naming)
        Route::get('/lazada/products', [LazadaProductController::class, 'index'])->name('lazada.products.index');
        Route::get('/lazada/products/create', [LazadaProductController::class, 'create'])->name('lazada.products.create');
        Route::post('/lazada/products', [LazadaProductController::class, 'store'])->name('lazada.products.store');
        // IMPORTANT: constrain {id} to numeric so routes like /lazada/products/bulk/* won't match these.
        Route::get('/lazada/products/{id}/edit', [LazadaProductController::class, 'edit'])->whereNumber('id')->name('lazada.products.edit');
        Route::put('/lazada/products/{id}', [LazadaProductController::class, 'update'])->whereNumber('id')->name('lazada.products.update');
        Route::post('/lazada/products/{id}/template/sync', [LazadaProductController::class, 'syncTemplate'])->whereNumber('id')->name('lazada.products.template_sync');
        Route::post('/lazada/products/{id}/attributes', [LazadaProductController::class, 'saveAttributes'])->whereNumber('id')->name('lazada.products.attributes_save');
        Route::post('/lazada/products/{id}/variants', [LazadaProductController::class, 'saveVariants'])->whereNumber('id')->name('lazada.products.variants_save');
        Route::post('/lazada/products/{id}/brands/sync', [LazadaProductController::class, 'syncBrands'])->whereNumber('id')->name('lazada.products.brands_sync');
        Route::post('/lazada/products/{id}/sync/quantity', [LazadaProductController::class, 'syncQuantity'])->whereNumber('id')->name('lazada.products.sync_quantity');
        Route::post('/lazada/products/{id}/sync/price', [LazadaProductController::class, 'syncPrice'])->whereNumber('id')->name('lazada.products.sync_price');
        Route::post('/lazada/products/{id}/upload', [LazadaProductController::class, 'uploadToLazada'])->whereNumber('id')->name('lazada.products.upload');
        Route::post('/lazada/products/{id}/delete/lazada', [LazadaProductController::class, 'deleteFromLazada'])->whereNumber('id')->name('lazada.products.delete_lazada');
        Route::post('/lazada/products/{id}/sync/lazada-id', [LazadaProductController::class, 'syncLazadaId'])->whereNumber('id')->name('lazada.products.sync_lazada_id');

        Route::post('/lazada/products/bulk/upload', [LazadaProductController::class, 'bulkUploadToLazada'])->name('lazada.products.bulk_upload');
        Route::post('/lazada/products/bulk/sync/quantity', [LazadaProductController::class, 'bulkSyncQuantity'])->name('lazada.products.bulk_sync_quantity');
        Route::post('/lazada/products/bulk/sync/price', [LazadaProductController::class, 'bulkSyncPrice'])->name('lazada.products.bulk_sync_price');
        Route::post('/lazada/products/bulk/delete', [LazadaProductController::class, 'bulkDeleteFromLazada'])->name('lazada.products.bulk_delete');

        Route::post('/lazada/products/{id}/payload/build', [LazadaProductController::class, 'buildPayload'])->whereNumber('id')->name('lazada.products.payload_build');
        Route::post('/lazada/products/{id}/push/sample', [LazadaProductController::class, 'pushSample'])->whereNumber('id')->name('lazada.products.push_sample');

        // Allows syncing brands even before a listing exists (e.g., on Create Listing screen)
        Route::post('/lazada/brands/sync', [LazadaProductController::class, 'syncBrandsGlobal'])->name('lazada.brands_sync');

        // Brands cache (similar to Categories list)
        Route::get('/lazada/brands', [\App\Http\Controllers\LazadaBrandController::class, 'index'])->name('lazada.brands.index');
        Route::post('/lazada/brands/fetch', [\App\Http\Controllers\LazadaBrandController::class, 'fetch'])->name('lazada.brands.fetch');
        Route::get('/lazada/brands/autocomplete', [\App\Http\Controllers\LazadaBrandController::class, 'autocomplete'])->name('lazada.brands.autocomplete');

        // Lazada Phase 2: Category list (cached locally for search + mapping)
        Route::get('/lazada/categories', [LazadaCategoryController::class, 'index'])->name('lazada.categories.index');
        Route::post('/lazada/categories/fetch', [LazadaCategoryController::class, 'fetch'])->name('lazada.categories.fetch');

        Route::get('/lazada/categories/{category_id}/attributes', [LazadaCategoryAttributeController::class, 'show'])
            ->whereNumber('category_id')
            ->name('lazada.categories.attributes.show');

        Route::post('/lazada/categories/{category_id}/attributes/fetch', [LazadaCategoryAttributeController::class, 'fetch'])
            ->whereNumber('category_id')
            ->name('lazada.categories.attributes.fetch');
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

    // Sales (parent permission)
    Route::middleware(['perm:manage_sales'])->group(function () {
        // Orders
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::post('/orders/bulk', [OrderController::class, 'bulkAction'])->name('orders.bulk');
        Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{id}/status', [OrderController::class, 'updateStatus'])->name('orders.update_status');
        Route::get('/orders/{id}/edit', [OrderController::class, 'edit'])->name('orders.edit');
        Route::put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update');
        Route::delete('/orders/{id}', [OrderController::class, 'destroy'])->name('orders.destroy');

        // Order Statuses
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
