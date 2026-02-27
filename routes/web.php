<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\DashboardController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\ProductImageController;
use App\Http\Controllers\ManufacturerController;
use App\Http\Controllers\CategoryController;
use App\Http\Controllers\Api\CatalogController as ApiCatalogController;
use App\Http\Controllers\ShopeeController;
use App\Http\Controllers\ShopeeOrderController;
use App\Http\Controllers\ShopeeCategoryController;
use App\Http\Controllers\ShopeeCategoryAttributeController;
use App\Http\Controllers\ShopeeProfileController;
use App\Http\Controllers\ShopeeProductController;
use App\Http\Controllers\ShopeeLogisticsController;
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
use App\Http\Controllers\TikTokController;
use App\Http\Controllers\ErrorLogController;
use App\Http\Controllers\ActivityLogController;
use App\Http\Controllers\OrderStatusController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\OpenCartProfileController;
use App\Http\Controllers\ReportController;

Route::get('/', [ShopeeController::class, 'root'])->name('root');

// Lazada OAuth callback must be publicly accessible so Lazada can redirect back with `code`.
// Keeping it outside auth/perm middleware prevents losing the code behind login redirects.
Route::get('/lazada/callback', [LazadaController::class, 'callback'])->name('lazada.callback');

// TikTok Shop OAuth callback must be publicly accessible.
Route::get('/tiktok/callback', [TikTokController::class, 'callback'])->name('tiktok.callback');


Route::middleware(['auth'])->group(function () {
    Route::get('/dashboard', [DashboardController::class, 'index'])->name('dashboard');
    Route::get('/dashboard/chart-data', [DashboardController::class, 'chartData'])->name('dashboard.chart_data');
    Route::get('/dashboard/platform-data', [DashboardController::class, 'platformData'])->name('dashboard.platform_data');

    Route::get('/search', [\App\Http\Controllers\SearchController::class, 'index'])->name('search');

    // Catalog (parent permission or any child)
    Route::middleware(['perm:manage_catalog'])->group(function () {
        // Lookups & API (needed by multiple sub-sections)
        Route::get('/lookup/manufacturers', [ManufacturerController::class, 'lookup'])->name('manufacturers.lookup');
        Route::get('/lookup/categories', [CategoryController::class, 'lookup'])->name('categories.lookup');
        Route::get('/api/catalog/manufacturers', [ApiCatalogController::class, 'manufacturers'])->name('api.catalog.manufacturers');
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
        Route::get('/products/{id}/edit', [ProductController::class, 'edit'])->name('products.edit');
        Route::put('/products/{id}', [ProductController::class, 'update'])->name('products.update');
        Route::delete('/products/{id}', [ProductController::class, 'destroy'])->name('products.destroy');

        // Product images (Image Manager)
        Route::post('/products/images/upload', [ProductImageController::class, 'upload'])->name('products.images.upload');
        Route::post('/products/images/import-url', [ProductImageController::class, 'importUrl'])->name('products.images.import_url');
        Route::get('/products/images/browse', [ProductImageController::class, 'browse'])->name('products.images.browse');
        Route::post('/products/images/upload-to-catalog', [ProductImageController::class, 'uploadToCatalog'])->name('products.images.upload_to_catalog');

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

    // Shopee
    Route::middleware(['perm:manage_shopee'])->group(function () {
        Route::get('/shopee', [ShopeeController::class, 'index'])->name('shopee.index');
        Route::get('/shopee/authorize', [ShopeeController::class, 'redirectToShopeeAuth'])->name('shopee.authorize');
        Route::post('/shopee/save', [ShopeeController::class, 'save'])->name('shopee.save');
        Route::post('/shopee/toggle-mode', [ShopeeController::class, 'toggleMode'])->name('shopee.toggle_mode');
        Route::post('/shopee/auth-url', [ShopeeController::class, 'buildAuthUrl'])->name('shopee.auth_url');
        Route::post('/shopee/token/get', [ShopeeController::class, 'tokenGet'])->name('shopee.token_get');
        Route::post('/shopee/token/refresh', [ShopeeController::class, 'tokenRefresh'])->name('shopee.token_refresh');
        Route::post('/shopee/call', [ShopeeController::class, 'callApi'])->name('shopee.call_api');
        Route::post('/shopee/order-status-map', [ShopeeController::class, 'saveOrderStatusMap'])->name('shopee.order_status_map');
        Route::post('/shopee/return-status-map', [ShopeeController::class, 'saveReturnStatusMap'])->name('shopee.return_status_map');
        Route::post('/shopee/sync-days', [ShopeeController::class, 'saveSyncDays'])->name('shopee.sync_days');
        Route::post('/shopee/sync-days-returns', [ShopeeController::class, 'saveSyncDaysReturns'])->name('shopee.sync_days_returns');
        Route::post('/shopee/toggle-logging', [ShopeeController::class, 'toggleLogging'])->name('shopee.toggle_logging');
        Route::delete('/shopee/api-logs', [ShopeeController::class, 'clearApiLogs'])->name('shopee.clear_api_logs');

        // Shopee Explorer & Packs
        Route::post('/shopee/explorer/run', [ShopeeController::class, 'explorerRun'])->name('shopee.explorer_run');
        Route::post('/shopee/packs/run', [ShopeeController::class, 'packsRun'])->name('shopee.packs_run');
        Route::post('/shopee/logistics/channels', [ShopeeController::class, 'logisticsChannels'])->name('shopee.logistics_channels');

        // Shopee Categories
        Route::get('/shopee/categories', [ShopeeCategoryController::class, 'index'])->name('shopee.categories.index');
        Route::post('/shopee/categories/fetch', [ShopeeCategoryController::class, 'fetch'])->name('shopee.categories.fetch');
        Route::get('/shopee/categories/{id}/attributes', [ShopeeCategoryAttributeController::class, 'show'])->name('shopee.categories.attributes.show');
        Route::post('/shopee/categories/{id}/attributes/fetch', [ShopeeCategoryAttributeController::class, 'fetch'])->name('shopee.categories.attributes.fetch');

        // Shopee Logistics
        Route::get('/shopee/logistics', [ShopeeLogisticsController::class, 'index'])->name('shopee.logistics.index');
        Route::post('/shopee/logistics/fetch', [ShopeeLogisticsController::class, 'fetch'])->name('shopee.logistics.fetch');

        // Shopee Profiles
        Route::get('/shopee/profiles', [ShopeeProfileController::class, 'index'])->name('shopee.profiles.index');
        Route::get('/shopee/profiles/create', [ShopeeProfileController::class, 'create'])->name('shopee.profiles.create');
        Route::post('/shopee/profiles', [ShopeeProfileController::class, 'store'])->name('shopee.profiles.store');
        Route::get('/shopee/profiles/{id}/edit', [ShopeeProfileController::class, 'edit'])->name('shopee.profiles.edit');
        Route::put('/shopee/profiles/{id}', [ShopeeProfileController::class, 'update'])->name('shopee.profiles.update');
        Route::delete('/shopee/profiles/{id}', [ShopeeProfileController::class, 'destroy'])->name('shopee.profiles.destroy');
        Route::post('/shopee/profiles/{id}/sync-products', [ShopeeProfileController::class, 'syncProducts'])->name('shopee.profiles.sync_products');
        Route::post('/shopee/profiles/{id}/template/sync', [ShopeeProfileController::class, 'syncTemplate'])->name('shopee.profiles.template_sync');

        // Shopee Products
        Route::get('/shopee/products', [ShopeeProductController::class, 'index'])->name('shopee.products.index');
        Route::get('/shopee/products/add', [ShopeeProductController::class, 'addProduct'])->name('shopee.products.add');
        Route::post('/shopee/products/sync-ids', [ShopeeProductController::class, 'syncProductIds'])->name('shopee.products.sync_ids');
        Route::get('/shopee/products/{productId}/push', [ShopeeProductController::class, 'pushForm'])->name('shopee.products.push');
        Route::post('/shopee/products/{productId}/push', [ShopeeProductController::class, 'push'])->name('shopee.products.push.post');
        Route::post('/shopee/products/{productId}/push-direct', [ShopeeProductController::class, 'pushDirect'])->name('shopee.products.push_direct');
        Route::post('/shopee/products/{productId}/sync/shopee-id', [ShopeeProductController::class, 'syncSingleProductId'])->name('shopee.products.sync_shopee_id');
        Route::post('/shopee/products/{productId}/sync/quantity', [ShopeeProductController::class, 'syncQuantity'])->name('shopee.products.sync_quantity');
        Route::post('/shopee/products/{productId}/sync/price', [ShopeeProductController::class, 'syncPrice'])->name('shopee.products.sync_price');
        Route::post('/shopee/products/{productId}/unlink', [ShopeeProductController::class, 'unlink'])->name('shopee.products.unlink');
        Route::post('/shopee/products/bulk/sync/quantity', [ShopeeProductController::class, 'bulkSyncQuantity'])->name('shopee.products.bulk_sync_quantity');
        Route::post('/shopee/products/bulk/sync/price', [ShopeeProductController::class, 'bulkSyncPrice'])->name('shopee.products.bulk_sync_price');
        Route::post('/shopee/products/unmatched/{unmatchedId}/link', [ShopeeProductController::class, 'linkUnmatched'])->name('shopee.products.link_unmatched');
        Route::post('/shopee/products/unmatched/{unmatchedId}/dismiss', [ShopeeProductController::class, 'dismissUnmatched'])->name('shopee.products.dismiss_unmatched');
        Route::get('/shopee/products/search-catalog', [ShopeeProductController::class, 'searchCatalogProducts'])->name('shopee.products.search_catalog');
        Route::post('/shopee/products/unmatched/sync', [ShopeeProductController::class, 'syncUnmatchedItems'])->name('shopee.products.sync_unmatched');
        Route::post('/shopee/products/rebuild-cache', [ShopeeProductController::class, 'rebuildCache'])->name('shopee.products.rebuild_cache');

    });

    // Shopee Orders (separate permission from Shopee settings/products)
    Route::middleware(['perm:manage_shopee_orders'])->group(function () {
        Route::get('/shopee/orders', [ShopeeOrderController::class, 'index'])->name('shopee.orders.index');
        Route::get('/shopee/orders/returns', [ShopeeOrderController::class, 'returns'])->name('shopee.orders.returns');
        Route::get('/shopee/orders/{orderSn}', [ShopeeOrderController::class, 'show'])->name('shopee.orders.show');
        Route::post('/shopee/orders/fetch', [ShopeeOrderController::class, 'fetch'])->name('shopee.orders.fetch');
        Route::post('/shopee/orders/update-statuses', [ShopeeOrderController::class, 'updateStatuses'])->name('shopee.orders.update_statuses');
        Route::post('/shopee/orders/reset', [ShopeeOrderController::class, 'reset'])->name('shopee.orders.reset');
        Route::post('/shopee/orders/{orderSn}/ship', [ShopeeOrderController::class, 'shipOrder'])->name('shopee.orders.ship');
        Route::get('/shopee/orders/{orderSn}/tracking', [ShopeeOrderController::class, 'getTrackingNumber'])->name('shopee.orders.tracking');
        Route::get('/shopee/orders/{orderSn}/shipping-addresses', [ShopeeOrderController::class, 'getShippingAddresses'])->name('shopee.orders.shipping_addresses');
        Route::get('/shopee/orders/{orderSn}/tracking-info', [ShopeeOrderController::class, 'getTrackingInfo'])->name('shopee.orders.tracking_info');
        Route::get('/shopee/orders/{orderSn}/awb', [ShopeeOrderController::class, 'awbPdf'])->name('shopee.orders.awb');
        Route::post('/shopee/orders/fetch-returns', [ShopeeOrderController::class, 'fetchReturns'])->name('shopee.orders.fetch_returns');
    });

    // Lazada
    Route::middleware(['perm:manage_lazada'])->group(function () {
        Route::get('/lazada', [LazadaController::class, 'index'])->name('lazada.index');
        Route::get('/lazada/authorize', [LazadaController::class, 'redirectToAuth'])->name('lazada.authorize');
        // NOTE: /lazada/callback is declared above (public route)
        Route::post('/lazada/save', [LazadaController::class, 'save'])->name('lazada.save');
        Route::post('/lazada/toggle-mode', [LazadaController::class, 'toggleMode'])->name('lazada.toggle_mode');
        Route::post('/lazada/order-status-map', [LazadaController::class, 'saveOrderStatusMap'])->name('lazada.order_status_map');
        Route::post('/lazada/sync-days', [LazadaController::class, 'saveSyncDays'])->name('lazada.sync_days');
        Route::post('/lazada/sync-days-returns', [LazadaController::class, 'saveSyncDaysReturns'])->name('lazada.sync_days_returns');
        Route::post('/lazada/reverse-status-map', [LazadaController::class, 'saveReverseStatusMap'])->name('lazada.reverse_status_map');
        Route::post('/lazada/toggle-logging', [LazadaController::class, 'toggleLogging'])->name('lazada.toggle_logging');
        Route::delete('/lazada/api-logs', [LazadaController::class, 'clearApiLogs'])->name('lazada.clear_api_logs');
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

        // Lazada Profiles
        Route::get('/lazada/profiles', [LazadaProfileController::class, 'index'])->name('lazada.profiles.index');
        Route::get('/lazada/profiles/create', [LazadaProfileController::class, 'create'])->name('lazada.profiles.create');
        Route::post('/lazada/profiles', [LazadaProfileController::class, 'store'])->name('lazada.profiles.store');
        Route::get('/lazada/profiles/{id}/edit', [LazadaProfileController::class, 'edit'])->whereNumber('id')->name('lazada.profiles.edit');
        Route::put('/lazada/profiles/{id}', [LazadaProfileController::class, 'update'])->whereNumber('id')->name('lazada.profiles.update');
        Route::delete('/lazada/profiles/{id}', [LazadaProfileController::class, 'destroy'])->whereNumber('id')->name('lazada.profiles.destroy');
        Route::post('/lazada/profiles/{id}/sync-products', [LazadaProfileController::class, 'syncProducts'])->whereNumber('id')->name('lazada.profiles.sync_products');
        Route::post('/lazada/profiles/{id}/template/sync', [LazadaProfileController::class, 'syncTemplate'])->whereNumber('id')->name('lazada.profiles.template_sync');

        // Lazada Products
        Route::get('/lazada/products', [LazadaProductController::class, 'index'])->name('lazada.products.index');
        Route::get('/lazada/products/create', [LazadaProductController::class, 'create'])->name('lazada.products.create');
        Route::post('/lazada/products', [LazadaProductController::class, 'store'])->name('lazada.products.store');
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
        Route::post('/lazada/products/{id}/unlink', [LazadaProductController::class, 'unlink'])->whereNumber('id')->name('lazada.products.unlink');
        Route::post('/lazada/products/{id}/sync/lazada-id', [LazadaProductController::class, 'syncLazadaId'])->whereNumber('id')->name('lazada.products.sync_lazada_id');

        Route::post('/lazada/products/bulk/upload', [LazadaProductController::class, 'bulkUploadToLazada'])->name('lazada.products.bulk_upload');
        Route::post('/lazada/products/bulk/sync/quantity', [LazadaProductController::class, 'bulkSyncQuantity'])->name('lazada.products.bulk_sync_quantity');
        Route::post('/lazada/products/bulk/sync/price', [LazadaProductController::class, 'bulkSyncPrice'])->name('lazada.products.bulk_sync_price');
        Route::post('/lazada/products/bulk/sync/lazada-id', [LazadaProductController::class, 'bulkSyncLazadaId'])->name('lazada.products.bulk_sync_lazada_id');
        Route::post('/lazada/products/bulk/delete', [LazadaProductController::class, 'bulkDeleteFromLazada'])->name('lazada.products.bulk_delete');

        Route::post('/lazada/products/{id}/payload/build', [LazadaProductController::class, 'buildPayload'])->whereNumber('id')->name('lazada.products.payload_build');
        Route::post('/lazada/products/{id}/push/sample', [LazadaProductController::class, 'pushSample'])->whereNumber('id')->name('lazada.products.push_sample');

        Route::post('/lazada/products/unmatched/sync', [LazadaProductController::class, 'syncUnmatchedItems'])->name('lazada.products.sync_unmatched');
        Route::post('/lazada/products/unmatched/{unmatchedId}/link', [LazadaProductController::class, 'linkUnmatchedItem'])->name('lazada.products.link_unmatched');
        Route::post('/lazada/products/unmatched/{unmatchedId}/dismiss', [LazadaProductController::class, 'dismissUnmatchedItem'])->name('lazada.products.dismiss_unmatched');
        Route::get('/lazada/products/search-catalog', [LazadaProductController::class, 'searchCatalogProducts'])->name('lazada.products.search_catalog');

        Route::post('/lazada/brands/sync', [LazadaProductController::class, 'syncBrandsGlobal'])->name('lazada.brands_sync');

        Route::get('/lazada/brands', [\App\Http\Controllers\LazadaBrandController::class, 'index'])->name('lazada.brands.index');
        Route::post('/lazada/brands/fetch', [\App\Http\Controllers\LazadaBrandController::class, 'fetch'])->name('lazada.brands.fetch');
        Route::get('/lazada/brands/autocomplete', [\App\Http\Controllers\LazadaBrandController::class, 'autocomplete'])->name('lazada.brands.autocomplete');

        Route::get('/lazada/categories', [LazadaCategoryController::class, 'index'])->name('lazada.categories.index');
        Route::post('/lazada/categories/fetch', [LazadaCategoryController::class, 'fetch'])->name('lazada.categories.fetch');

        Route::get('/lazada/categories/{category_id}/attributes', [LazadaCategoryAttributeController::class, 'show'])
            ->whereNumber('category_id')
            ->name('lazada.categories.attributes.show');

        Route::post('/lazada/categories/{category_id}/attributes/fetch', [LazadaCategoryAttributeController::class, 'fetch'])
            ->whereNumber('category_id')
            ->name('lazada.categories.attributes.fetch');
    });

    // Lazada Orders (separate permission from Lazada settings/products)
    Route::middleware(['perm:manage_lazada_orders'])->group(function () {
        Route::get('/lazada/orders', [LazadaOrderController::class, 'index'])->name('lazada.orders.index');
        Route::get('/lazada/orders/returns', [LazadaOrderController::class, 'returns'])->name('lazada.orders.returns');
        Route::post('/lazada/orders/returns/fetch', [LazadaOrderController::class, 'fetchReturns'])->name('lazada.orders.fetch_returns');
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
    });

    // TikTok Shop
    Route::middleware(['perm:manage_tiktok'])->group(function () {
        Route::get('/tiktok', [TikTokController::class, 'index'])->name('tiktok.index');
        Route::get('/tiktok/authorize', [TikTokController::class, 'redirectToAuth'])->name('tiktok.authorize');
        Route::post('/tiktok/save', [TikTokController::class, 'save'])->name('tiktok.save');
        Route::post('/tiktok/toggle-mode', [TikTokController::class, 'toggleMode'])->name('tiktok.toggle_mode');
        Route::post('/tiktok/token/get', [TikTokController::class, 'tokenGet'])->name('tiktok.token_get');
        Route::post('/tiktok/token/refresh', [TikTokController::class, 'tokenRefresh'])->name('tiktok.token_refresh');
        Route::post('/tiktok/shops', [TikTokController::class, 'getShops'])->name('tiktok.shops');
        Route::post('/tiktok/explorer/run', [TikTokController::class, 'explorerRun'])->name('tiktok.explorer_run');
        Route::post('/tiktok/packs/run', [TikTokController::class, 'packsRun'])->name('tiktok.packs_run');
        Route::post('/tiktok/toggle-logging', [TikTokController::class, 'toggleLogging'])->name('tiktok.toggle_logging');
        Route::delete('/tiktok/api-logs', [TikTokController::class, 'clearApiLogs'])->name('tiktok.clear_api_logs');
        Route::post('/tiktok/sync-days', [TikTokController::class, 'saveSyncDays'])->name('tiktok.sync_days');
    });

    // OpenCart
    Route::middleware(['perm:manage_opencart'])->group(function () {
        Route::get('/opencart', [\App\Http\Controllers\OpenCartController::class, 'index'])->name('opencart.index');
        Route::post('/opencart/save', [\App\Http\Controllers\OpenCartController::class, 'save'])->name('opencart.save');
        Route::delete('/opencart/{id}', [\App\Http\Controllers\OpenCartController::class, 'destroy'])->name('opencart.destroy');
        Route::post('/opencart/test', [\App\Http\Controllers\OpenCartController::class, 'testConnection'])->name('opencart.test');
        Route::post('/opencart/sync', [\App\Http\Controllers\OpenCartController::class, 'syncNow'])->name('opencart.sync');
        Route::post('/opencart/sync-date', [\App\Http\Controllers\OpenCartController::class, 'saveSyncDate'])->name('opencart.sync_date');
        Route::post('/opencart/push', [\App\Http\Controllers\OpenCartController::class, 'pushNow'])->name('opencart.push');
        Route::post('/opencart/push-qty', [\App\Http\Controllers\OpenCartController::class, 'pushQtyNow'])->name('opencart.push_qty');
        Route::post('/opencart/verify-password', [\App\Http\Controllers\OpenCartController::class, 'verifyPassword'])->name('opencart.verify_password');
        Route::post('/opencart/fetch-oc-statuses', [\App\Http\Controllers\OpenCartController::class, 'fetchOcStatuses'])->name('opencart.fetch_oc_statuses');
        Route::post('/opencart/save-status-map', [\App\Http\Controllers\OpenCartController::class, 'saveOrderStatusMap'])->name('opencart.save_status_map');

        // OpenCart per-store profiles
        Route::get('/opencart/{store}/profiles', [OpenCartProfileController::class, 'index'])->name('opencart.profiles.index');
        Route::get('/opencart/{store}/profiles/create', [OpenCartProfileController::class, 'create'])->name('opencart.profiles.create');
        Route::post('/opencart/{store}/profiles', [OpenCartProfileController::class, 'store'])->name('opencart.profiles.store');
        Route::get('/opencart/{store}/profiles/{profile}/edit', [OpenCartProfileController::class, 'edit'])->name('opencart.profiles.edit');
        Route::put('/opencart/{store}/profiles/{profile}', [OpenCartProfileController::class, 'update'])->name('opencart.profiles.update');
        Route::delete('/opencart/{store}/profiles/{profile}', [OpenCartProfileController::class, 'destroy'])->name('opencart.profiles.destroy');
        Route::get('/opencart/{store}/profiles/{profile}/products', [OpenCartProfileController::class, 'products'])->name('opencart.profiles.products');
        Route::post('/opencart/{store}/profiles/{profile}/push', [OpenCartProfileController::class, 'push'])->name('opencart.profiles.push');
        Route::post('/opencart/{store}/profiles/{profile}/add-products', [OpenCartProfileController::class, 'addProducts'])->name('opencart.profiles.addProducts');
        Route::delete('/opencart/{store}/profiles/{profile}/products/{product}', [OpenCartProfileController::class, 'removeProduct'])->name('opencart.profiles.removeProduct');
        Route::get('/opencart/{store}/profiles/search-products', [OpenCartProfileController::class, 'searchProducts'])->name('opencart.profiles.searchProducts');
    });

    // OpenCart Orders (separate permission from OpenCart settings/profiles)
    Route::middleware(['perm:manage_opencart_orders'])->group(function () {
        Route::get('/opencart/{store}/orders', [OrderController::class, 'opencartIndex'])->name('opencart.orders.index');
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
    });

    // Error Log
    Route::middleware(['perm:manage_error_log'])->group(function () {
        Route::get('/error-log', [ErrorLogController::class, 'index'])->name('error_log.index');
        Route::post('/error-log/clear', [ErrorLogController::class, 'clear'])->name('error_log.clear');
        Route::post('/error-log/test', [ErrorLogController::class, 'test'])->name('error_log.test');
    });

    // Activity Log
    Route::middleware(['perm:manage_activity_log'])->group(function () {
        Route::get('/activity-log', [ActivityLogController::class, 'index'])->name('activity_log.index');
        Route::post('/activity-log/clear', [ActivityLogController::class, 'clear'])->name('activity_log.clear');
    });

    // Orders
    Route::middleware(['perm:manage_orders'])->group(function () {
        Route::get('/orders', [OrderController::class, 'index'])->name('orders.index');
        Route::get('/orders/create', [OrderController::class, 'create'])->name('orders.create');
        Route::post('/orders', [OrderController::class, 'store'])->name('orders.store');
        Route::post('/orders/bulk', [OrderController::class, 'bulkAction'])->name('orders.bulk');
        Route::get('/orders/{id}', [OrderController::class, 'show'])->name('orders.show');
        Route::post('/orders/{id}/status', [OrderController::class, 'updateStatus'])->name('orders.update_status');
        Route::get('/orders/{id}/edit', [OrderController::class, 'edit'])->name('orders.edit');
        Route::put('/orders/{id}', [OrderController::class, 'update'])->name('orders.update');
        Route::delete('/orders/{id}', [OrderController::class, 'destroy'])->name('orders.destroy');
    });

    // Reports
    Route::middleware(['perm:manage_reports'])->group(function () {
        Route::get('/reports/orders', [ReportController::class, 'orders'])->name('reports.orders');
        Route::get('/reports/orders/export', [ReportController::class, 'exportOrders'])->name('reports.orders.export');
        Route::get('/reports/products', [ReportController::class, 'products'])->name('reports.products');
        Route::get('/reports/products/export', [ReportController::class, 'exportProducts'])->name('reports.products.export');
        Route::get('/reports/payouts', [ReportController::class, 'payouts'])->name('reports.payouts');
        Route::get('/reports/payouts/export', [ReportController::class, 'exportPayouts'])->name('reports.payouts.export');
        Route::get('/reports/categories', [ReportController::class, 'categories'])->name('reports.categories');
        Route::get('/reports/fees', [ReportController::class, 'fees'])->name('reports.fees');
        Route::get('/reports/inventory', [ReportController::class, 'inventory'])->name('reports.inventory');
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
