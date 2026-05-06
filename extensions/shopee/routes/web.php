<?php

use Extensions\shopee\Controllers\ShopeeController;
use Extensions\shopee\Controllers\ShopeeOrderController;
use Extensions\shopee\Controllers\ShopeeCategoryController;
use Extensions\shopee\Controllers\ShopeeCategoryAttributeController;
use Extensions\shopee\Controllers\ShopeeProductGroupController;
use Extensions\shopee\Controllers\ShopeeProductController;
use Extensions\shopee\Controllers\ShopeeLogisticsController;
use Illuminate\Support\Facades\Route;

Route::middleware(['auth'])->group(function () {

    // Shopee — settings, categories, logistics, product groups, products
    Route::middleware(['perm:manage_shopee'])->group(function () {
        Route::get('/shopee', [ShopeeController::class, 'index'])->name('ext.shopee.index');
        Route::get('/shopee/authorize', [ShopeeController::class, 'redirectToShopeeAuth'])->name('ext.shopee.authorize');
        Route::post('/shopee/save', [ShopeeController::class, 'save'])->name('ext.shopee.save');
        Route::post('/shopee/toggle-mode', [ShopeeController::class, 'toggleMode'])->name('ext.shopee.toggle_mode');
        Route::post('/shopee/auth-url', [ShopeeController::class, 'buildAuthUrl'])->name('ext.shopee.auth_url');
        Route::post('/shopee/token/get', [ShopeeController::class, 'tokenGet'])->name('ext.shopee.token_get');
        Route::post('/shopee/token/refresh', [ShopeeController::class, 'tokenRefresh'])->name('ext.shopee.token_refresh');
        Route::post('/shopee/call', [ShopeeController::class, 'callApi'])->name('ext.shopee.call_api');
        Route::post('/shopee/order-status-map', [ShopeeController::class, 'saveOrderStatusMap'])->name('ext.shopee.order_status_map');
        Route::post('/shopee/return-status-map', [ShopeeController::class, 'saveReturnStatusMap'])->name('ext.shopee.return_status_map');
        Route::post('/shopee/sync-days', [ShopeeController::class, 'saveSyncDays'])->name('ext.shopee.sync_days');
        Route::post('/shopee/sync-days-returns', [ShopeeController::class, 'saveSyncDaysReturns'])->name('ext.shopee.sync_days_returns');
        Route::post('/shopee/toggle-logging', [ShopeeController::class, 'toggleLogging'])->name('ext.shopee.toggle_logging');
        Route::delete('/shopee/api-logs', [ShopeeController::class, 'clearApiLogs'])->name('ext.shopee.clear_api_logs');

        // Shopee Explorer & Packs
        Route::post('/shopee/explorer/run', [ShopeeController::class, 'explorerRun'])->name('ext.shopee.explorer_run');
        Route::post('/shopee/packs/run', [ShopeeController::class, 'packsRun'])->name('ext.shopee.packs_run');
        Route::post('/shopee/logistics/channels', [ShopeeController::class, 'logisticsChannels'])->name('ext.shopee.logistics_channels');

        // Shopee Categories
        Route::get('/shopee/categories', [ShopeeCategoryController::class, 'index'])->name('ext.shopee.categories.index');
        Route::post('/shopee/categories/fetch', [ShopeeCategoryController::class, 'fetch'])->name('ext.shopee.categories.fetch');
        Route::get('/shopee/categories/{id}/attributes', [ShopeeCategoryAttributeController::class, 'show'])->name('ext.shopee.categories.attributes.show');
        Route::post('/shopee/categories/{id}/attributes/fetch', [ShopeeCategoryAttributeController::class, 'fetch'])->name('ext.shopee.categories.attributes.fetch');

        // Shopee Logistics
        Route::get('/shopee/logistics', [ShopeeLogisticsController::class, 'index'])->name('ext.shopee.logistics.index');
        Route::post('/shopee/logistics/fetch', [ShopeeLogisticsController::class, 'fetch'])->name('ext.shopee.logistics.fetch');

        // Shopee Product Groups
        Route::get('/shopee/product-groups', [ShopeeProductGroupController::class, 'index'])->name('ext.shopee.product-groups.index');
        Route::get('/shopee/product-groups/create', [ShopeeProductGroupController::class, 'create'])->name('ext.shopee.product-groups.create');
        Route::post('/shopee/product-groups', [ShopeeProductGroupController::class, 'store'])->name('ext.shopee.product-groups.store');
        Route::get('/shopee/product-groups/search-products', [ShopeeProductGroupController::class, 'searchProducts'])->name('ext.shopee.product-groups.searchProducts');
        Route::get('/shopee/product-groups/{id}/edit', [ShopeeProductGroupController::class, 'edit'])->name('ext.shopee.product-groups.edit');
        Route::put('/shopee/product-groups/{id}', [ShopeeProductGroupController::class, 'update'])->name('ext.shopee.product-groups.update');
        Route::delete('/shopee/product-groups/{id}', [ShopeeProductGroupController::class, 'destroy'])->name('ext.shopee.product-groups.destroy');
        Route::get('/shopee/product-groups/{id}/products', [ShopeeProductGroupController::class, 'products'])->name('ext.shopee.product-groups.products');
        Route::post('/shopee/product-groups/{id}/products/add', [ShopeeProductGroupController::class, 'addProducts'])->name('ext.shopee.product-groups.addProducts');
        Route::post('/shopee/product-groups/{id}/products/push', [ShopeeProductGroupController::class, 'push'])->name('ext.shopee.product-groups.push');
        Route::post('/shopee/product-groups/{id}/products/update-product', [ShopeeProductGroupController::class, 'updateProduct'])->name('ext.shopee.product-groups.updateProduct');
        Route::post('/shopee/product-groups/{id}/products/push-prices', [ShopeeProductGroupController::class, 'pushPrices'])->name('ext.shopee.product-groups.pushPrices');
        Route::post('/shopee/product-groups/{id}/products/push-stock', [ShopeeProductGroupController::class, 'pushStock'])->name('ext.shopee.product-groups.pushStock');
        Route::post('/shopee/product-groups/{id}/products/mass-remove', [ShopeeProductGroupController::class, 'massRemove'])->name('ext.shopee.product-groups.massRemove');
        Route::post('/shopee/product-groups/{id}/products/delete-from-shopee', [ShopeeProductGroupController::class, 'deleteFromShopee'])->name('ext.shopee.product-groups.deleteFromShopee');
        Route::post('/shopee/product-groups/{id}/products/{productId}/sync-id', [ShopeeProductGroupController::class, 'syncId'])->name('ext.shopee.product-groups.syncId');
        Route::post('/shopee/product-groups/{id}/products/{productId}/unlink', [ShopeeProductGroupController::class, 'unlinkProduct'])->name('ext.shopee.product-groups.unlinkProduct');
        Route::post('/shopee/product-groups/{id}/products/{productId}/link', [ShopeeProductGroupController::class, 'linkProduct'])->name('ext.shopee.product-groups.linkProduct');
        Route::delete('/shopee/product-groups/{id}/products/{productId}', [ShopeeProductGroupController::class, 'removeProduct'])->name('ext.shopee.product-groups.removeProduct');
        Route::post('/shopee/product-groups/{id}/template/sync', [ShopeeProductGroupController::class, 'syncTemplate'])->name('ext.shopee.product-groups.template_sync');
        Route::post('/shopee/product-groups/refresh-categories', [ShopeeProductGroupController::class, 'refreshCategories'])->name('ext.shopee.product-groups.refreshCategories');
        Route::post('/shopee/product-groups/fetch-attributes', [ShopeeProductGroupController::class, 'fetchAttributesAjax'])->name('ext.shopee.product-groups.fetchAttributes');

        // Shopee Products
        Route::get('/shopee/products', [ShopeeProductController::class, 'index'])->name('ext.shopee.products.index');
        Route::get('/shopee/products/add', [ShopeeProductController::class, 'addProduct'])->name('ext.shopee.products.add');
        Route::post('/shopee/products/sync-ids', [ShopeeProductController::class, 'syncProductIds'])->name('ext.shopee.products.sync_ids');
        Route::post('/shopee/products/bulk/push', [ShopeeProductController::class, 'bulkPushToShopee'])->name('ext.shopee.products.bulk_push');
        Route::post('/shopee/products/bulk/sync/quantity', [ShopeeProductController::class, 'bulkSyncQuantity'])->name('ext.shopee.products.bulk_sync_quantity');
        Route::post('/shopee/products/bulk/sync/price', [ShopeeProductController::class, 'bulkSyncPrice'])->name('ext.shopee.products.bulk_sync_price');
        Route::post('/shopee/products/bulk/delete', [ShopeeProductController::class, 'bulkDeleteFromShopee'])->name('ext.shopee.products.bulk_delete');
        Route::get('/shopee/products/search-catalog', [ShopeeProductController::class, 'searchCatalogProducts'])->name('ext.shopee.products.search_catalog');
        Route::post('/shopee/products/unmatched/sync', [ShopeeProductController::class, 'syncUnmatchedItems'])->name('ext.shopee.products.sync_unmatched');
        Route::post('/shopee/products/rebuild-cache', [ShopeeProductController::class, 'rebuildCache'])->name('ext.shopee.products.rebuild_cache');
        Route::post('/shopee/products/unmatched/{unmatchedId}/link', [ShopeeProductController::class, 'linkUnmatched'])->name('ext.shopee.products.link_unmatched');
        Route::post('/shopee/products/unmatched/{unmatchedId}/dismiss', [ShopeeProductController::class, 'dismissUnmatched'])->name('ext.shopee.products.dismiss_unmatched');
        Route::get('/shopee/products/{productId}/push', [ShopeeProductController::class, 'pushForm'])->name('ext.shopee.products.push');
        Route::post('/shopee/products/{productId}/push', [ShopeeProductController::class, 'push'])->name('ext.shopee.products.push.post');
        Route::post('/shopee/products/{productId}/push-direct', [ShopeeProductController::class, 'pushDirect'])->name('ext.shopee.products.push_direct');
        Route::post('/shopee/products/{productId}/sync/shopee-id', [ShopeeProductController::class, 'syncSingleProductId'])->name('ext.shopee.products.sync_shopee_id');
        Route::post('/shopee/products/{productId}/sync/quantity', [ShopeeProductController::class, 'syncQuantity'])->name('ext.shopee.products.sync_quantity');
        Route::post('/shopee/products/{productId}/sync/price', [ShopeeProductController::class, 'syncPrice'])->name('ext.shopee.products.sync_price');
        Route::post('/shopee/products/{productId}/unlink', [ShopeeProductController::class, 'unlink'])->name('ext.shopee.products.unlink');
        Route::post('/shopee/products/{productId}/delete', [ShopeeProductController::class, 'deleteFromShopee'])->name('ext.shopee.products.delete');
    });

    // Shopee Orders (separate permission)
    Route::middleware(['perm:manage_shopee_orders'])->group(function () {
        Route::get('/shopee/orders', [ShopeeOrderController::class, 'index'])->name('ext.shopee.orders.index');
        Route::get('/shopee/orders/returns', [ShopeeOrderController::class, 'returns'])->name('ext.shopee.orders.returns');
        Route::get('/shopee/orders/{orderSn}', [ShopeeOrderController::class, 'show'])->name('ext.shopee.orders.show');
        Route::post('/shopee/orders/fetch', [ShopeeOrderController::class, 'fetch'])->name('ext.shopee.orders.fetch');
        Route::post('/shopee/orders/update-statuses', [ShopeeOrderController::class, 'updateStatuses'])->name('ext.shopee.orders.update_statuses');
        Route::post('/shopee/orders/reset', [ShopeeOrderController::class, 'reset'])->name('ext.shopee.orders.reset');
        Route::post('/shopee/orders/{orderSn}/ship', [ShopeeOrderController::class, 'shipOrder'])->name('ext.shopee.orders.ship');
        Route::get('/shopee/orders/{orderSn}/tracking', [ShopeeOrderController::class, 'getTrackingNumber'])->name('ext.shopee.orders.tracking');
        Route::get('/shopee/orders/{orderSn}/shipping-addresses', [ShopeeOrderController::class, 'getShippingAddresses'])->name('ext.shopee.orders.shipping_addresses');
        Route::get('/shopee/orders/{orderSn}/tracking-info', [ShopeeOrderController::class, 'getTrackingInfo'])->name('ext.shopee.orders.tracking_info');
        Route::get('/shopee/orders/{orderSn}/awb', [ShopeeOrderController::class, 'awbPdf'])->name('ext.shopee.orders.awb');
        Route::post('/shopee/orders/fetch-returns', [ShopeeOrderController::class, 'fetchReturns'])->name('ext.shopee.orders.fetch_returns');
    });

});
