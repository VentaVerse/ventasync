<?php

use Extensions\shopee\Controllers\ShopeeDescriptionTemplateController;
use Extensions\shopee\Controllers\ShopeeWatermarkTemplateController;
use Extensions\shopee\Controllers\ShopeeSettingsController;
use Extensions\shopee\Controllers\ShopeeApiExplorerController;
use Extensions\shopee\Controllers\ShopeeOrderController;
use Extensions\shopee\Controllers\ShopeeCategoryController;
use Extensions\shopee\Controllers\ShopeeCategoryAttributeController;
use Extensions\shopee\Controllers\ShopeeProductGroupController;
use Extensions\shopee\Controllers\ShopeeProductController;
use Extensions\shopee\Controllers\ShopeeLogisticsController;
use Illuminate\Support\Facades\Route;

// /shopee and /shopee/authorize must keep their URIs: the callback is registered byte for byte at Shopee.
// Moving them breaks OAuth for every install.
Route::middleware(['auth'])->group(function () {
    Route::get('/shopee', [ShopeeSettingsController::class, 'index'])->name('ext.shopee.index');
});

Route::middleware(['auth'])->group(function () {
    Route::get('/shopee/authorize', [ShopeeSettingsController::class, 'redirectToShopeeAuth'])->name('ext.shopee.authorize')
        // A form page is a manage surface even though it is a GET; do not demote it to the view tier.
        ->defaults('permission_tier', 'manage');
});

Route::middleware(['auth', \Extensions\shopee\Http\ResolveShopeeStore::class])
    ->prefix('channels')->where(['store' => '[0-9]+'])->group(function () {

    Route::group([], function () {
        Route::post('/shopee/stores', [ShopeeSettingsController::class, 'createStore'])->name('ext.shopee.stores.store');

        Route::get('/shopee/{store}/settings', [ShopeeSettingsController::class, 'showSettings'])->name('ext.shopee.settings.show');
        Route::get('/shopee/{store}/categories', [ShopeeCategoryController::class, 'index'])->name('ext.shopee.categories.index');
        Route::get('/shopee/{store}/categories/{id}/attributes', [ShopeeCategoryAttributeController::class, 'show'])->name('ext.shopee.categories.attributes.show');
        Route::get('/shopee/{store}/logistics', [ShopeeLogisticsController::class, 'index'])->name('ext.shopee.logistics.index');

        Route::get('/shopee/{store}/product-groups', [ShopeeProductGroupController::class, 'index'])->name('ext.shopee.product-groups.index');
        
        Route::get('/shopee/{store}/description-templates', [ShopeeDescriptionTemplateController::class, 'index'])->name('ext.shopee.description-templates.index');
        Route::post('/shopee/{store}/description-templates', [ShopeeDescriptionTemplateController::class, 'store'])->name('ext.shopee.description-templates.store')->defaults('permission_tier', 'manage');
        Route::put('/shopee/{store}/description-templates/{template}', [ShopeeDescriptionTemplateController::class, 'update'])->whereNumber('template')->name('ext.shopee.description-templates.update')->defaults('permission_tier', 'manage');
        Route::delete('/shopee/{store}/description-templates/{template}', [ShopeeDescriptionTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.shopee.description-templates.destroy')->defaults('permission_tier', 'manage');

        Route::get('/shopee/{store}/products/categories/children', [ShopeeProductController::class, 'categoryChildren'])->name('ext.shopee.products.category_children');
        Route::get('/shopee/{store}/products/categories/path', [ShopeeProductController::class, 'categoryPath'])->name('ext.shopee.products.category_path');
        Route::get('/shopee/{store}/watermarks', [ShopeeWatermarkTemplateController::class, 'index'])->name('ext.shopee.watermarks.index');
        Route::get('/shopee/{store}/watermarks/preview', [ShopeeWatermarkTemplateController::class, 'preview'])->name('ext.shopee.watermarks.preview');
        Route::get('/shopee/{store}/watermarks/create', [ShopeeWatermarkTemplateController::class, 'create'])->name('ext.shopee.watermarks.create')->defaults('permission_tier', 'manage');
        Route::post('/shopee/{store}/watermarks', [ShopeeWatermarkTemplateController::class, 'store'])->name('ext.shopee.watermarks.store')->defaults('permission_tier', 'manage');
        Route::get('/shopee/{store}/watermarks/{template}/edit', [ShopeeWatermarkTemplateController::class, 'edit'])->whereNumber('template')->name('ext.shopee.watermarks.edit')->defaults('permission_tier', 'manage');
        Route::put('/shopee/{store}/watermarks/{template}', [ShopeeWatermarkTemplateController::class, 'update'])->whereNumber('template')->name('ext.shopee.watermarks.update')->defaults('permission_tier', 'manage');
        Route::delete('/shopee/{store}/watermarks/{template}', [ShopeeWatermarkTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.shopee.watermarks.destroy')->defaults('permission_tier', 'manage');
        Route::get('/shopee/{store}/product-groups/brands', [ShopeeProductGroupController::class, 'brandsForCategory'])->name('ext.shopee.product-groups.brandsForCategory');
        Route::get('/shopee/{store}/product-groups/categories/search', [ShopeeProductGroupController::class, 'searchCategories'])->name('ext.shopee.product-groups.searchCategories');
        Route::get('/shopee/{store}/product-groups/categories/lookup', [ShopeeProductGroupController::class, 'categoryLookup'])->name('ext.shopee.product-groups.categoryLookup');
        Route::get('/shopee/{store}/product-groups/{id}/edit', [ShopeeProductGroupController::class, 'edit'])->name('ext.shopee.product-groups.edit');
        Route::get('/shopee/{store}/product-groups/{id}/products', [ShopeeProductGroupController::class, 'products'])->name('ext.shopee.product-groups.products');
        Route::get('/shopee/{store}/product-groups/{id}/products/search', [ShopeeProductGroupController::class, 'productSearch'])->whereNumber('id')->name('ext.shopee.product-groups.productSearch');
        Route::get('/shopee/{store}/product-groups/{id}/orphans', [ShopeeProductGroupController::class, 'orphans'])->name('ext.shopee.product-groups.orphans');

        Route::get('/shopee/{store}/products', [ShopeeProductController::class, 'index'])->name('ext.shopee.products.index');
        Route::get('/shopee/{store}/products/search-catalog', [ShopeeProductController::class, 'searchCatalogProducts'])->name('ext.shopee.products.search_catalog');
        Route::get('/shopee/{store}/products/catalogue-search', [ShopeeProductController::class, 'catalogueSearch'])->name('ext.shopee.products.catalogue_search');
        Route::get('/shopee/{store}/products/categories/search', [ShopeeProductController::class, 'searchCategories'])->name('ext.shopee.products.searchCategories');
        Route::get('/shopee/{store}/products/categories/lookup', [ShopeeProductController::class, 'categoryLookup'])->name('ext.shopee.products.categoryLookup');
        Route::get('/shopee/{store}/products/brands', [ShopeeProductController::class, 'brandsForCategory'])->name('ext.shopee.products.brandsForCategory');
        Route::get('/shopee/{store}/products/import', [ShopeeProductController::class, 'importPage'])->name('ext.shopee.products.import');
        Route::get('/shopee/{store}/products/{productId}/push-review', [ShopeeProductController::class, 'pushReview'])->whereNumber('productId')->name('ext.shopee.products.push_review');

        Route::get('/shopee/{store}/vouchers', [\Extensions\shopee\Controllers\ShopeeVoucherController::class, 'index'])->name('ext.shopee.vouchers.index');


        Route::get('/shopee/{store}/listings/{productId}', [ShopeeProductController::class, 'listing'])
            ->whereNumber('productId')->name('ext.shopee.listings.edit');
    });

    Route::group([], function () {
        Route::post('/shopee/{store}/save', [ShopeeSettingsController::class, 'save'])->name('ext.shopee.save');
        Route::post('/shopee/{store}/setup/{step}', [ShopeeSettingsController::class, 'setupStep'])->whereIn('step', ['categories', 'couriers'])->name('ext.shopee.setup_step');
        Route::post('/shopee/{store}/delete', [ShopeeSettingsController::class, 'destroyStore'])->name('ext.shopee.stores.destroy');
        Route::post('/shopee/{store}/toggle-mode', [ShopeeSettingsController::class, 'toggleMode'])->name('ext.shopee.toggle_mode');
        Route::post('/shopee/{store}/auth-url', [ShopeeSettingsController::class, 'buildAuthUrl'])->name('ext.shopee.auth_url');
        Route::post('/shopee/{store}/token/get', [ShopeeSettingsController::class, 'tokenGet'])->name('ext.shopee.token_get');
        Route::post('/shopee/{store}/token/refresh', [ShopeeSettingsController::class, 'tokenRefresh'])->name('ext.shopee.token_refresh');

        Route::post('/shopee/{store}/call', [ShopeeApiExplorerController::class, 'callApi'])->name('ext.shopee.call_api');
        Route::post('/shopee/{store}/explorer/run', [ShopeeApiExplorerController::class, 'explorerRun'])->name('ext.shopee.explorer_run');
        Route::post('/shopee/{store}/packs/run', [ShopeeApiExplorerController::class, 'packsRun'])->name('ext.shopee.packs_run');

        Route::post('/shopee/{store}/order-status-map', [ShopeeSettingsController::class, 'saveOrderStatusMap'])->name('ext.shopee.order_status_map');
        Route::post('/shopee/{store}/return-status-map', [ShopeeSettingsController::class, 'saveReturnStatusMap'])->name('ext.shopee.return_status_map');

        Route::post('/shopee/{store}/api-log-mode', [ShopeeSettingsController::class, 'setApiLogMode'])->name('ext.shopee.api_log_mode');
        Route::delete('/shopee/{store}/api-logs', [ShopeeSettingsController::class, 'clearApiLogs'])->name('ext.shopee.clear_api_logs');
        Route::post('/shopee/{store}/purge-raw', [ShopeeSettingsController::class, 'purgeRaw'])->name('ext.shopee.purge_raw');

        Route::post('/shopee/{store}/categories/fetch', [ShopeeCategoryController::class, 'fetch'])->name('ext.shopee.categories.fetch');
        Route::post('/shopee/{store}/categories/{id}/attributes/fetch', [ShopeeCategoryAttributeController::class, 'fetch'])->name('ext.shopee.categories.attributes.fetch');

        Route::post('/shopee/{store}/logistics/fetch', [ShopeeLogisticsController::class, 'fetch'])->name('ext.shopee.logistics.fetch');

        Route::get('/shopee/{store}/product-groups/create', [ShopeeProductGroupController::class, 'create'])->name('ext.shopee.product-groups.create')
        // A form page is a manage surface even though it is a GET; do not demote it to the view tier.
        ->defaults('permission_tier', 'manage');
        Route::post('/shopee/{store}/product-groups', [ShopeeProductGroupController::class, 'store'])->name('ext.shopee.product-groups.store');
        Route::put('/shopee/{store}/product-groups/{id}', [ShopeeProductGroupController::class, 'update'])->name('ext.shopee.product-groups.update');
        Route::delete('/shopee/{store}/product-groups/{id}', [ShopeeProductGroupController::class, 'destroy'])->name('ext.shopee.product-groups.destroy');
        Route::post('/shopee/{store}/product-groups/{id}/products/{productId}/add', [ShopeeProductGroupController::class, 'addProduct'])->whereNumber('id')->whereNumber('productId')->name('ext.shopee.product-groups.addProduct');
        Route::post('/shopee/{store}/product-groups/{id}/products/send', [ShopeeProductGroupController::class, 'send'])->name('ext.shopee.product-groups.send');
        Route::post('/shopee/{store}/product-groups/{id}/send-runs', [ShopeeProductGroupController::class, 'sendRunBegin'])->whereNumber('id')->name('ext.shopee.product-groups.send_run_begin');
        Route::post('/shopee/{store}/product-groups/{id}/send-runs/{run}/step', [ShopeeProductGroupController::class, 'sendRunStep'])->whereNumber('id')->whereNumber('run')->name('ext.shopee.product-groups.send_run_step');
        Route::post('/shopee/{store}/product-groups/{id}/send-runs/{run}/stop', [ShopeeProductGroupController::class, 'sendRunStop'])->whereNumber('id')->whereNumber('run')->name('ext.shopee.product-groups.send_run_stop');
        Route::post('/shopee/{store}/product-groups/{id}/products/check', [ShopeeProductGroupController::class, 'checkAgainstShopee'])->name('ext.shopee.product-groups.check');
        Route::post('/shopee/{store}/product-groups/{id}/products/push', [ShopeeProductGroupController::class, 'push'])->name('ext.shopee.product-groups.push');
        Route::post('/shopee/{store}/product-groups/{id}/products/update-product', [ShopeeProductGroupController::class, 'updateProduct'])->name('ext.shopee.product-groups.updateProduct');
        Route::post('/shopee/{store}/product-groups/{id}/products/push-prices', [ShopeeProductGroupController::class, 'pushPrices'])->name('ext.shopee.product-groups.pushPrices');
        Route::post('/shopee/{store}/product-groups/{id}/products/push-stock', [ShopeeProductGroupController::class, 'pushStock'])->name('ext.shopee.product-groups.pushStock');
        Route::post('/shopee/{store}/product-groups/{id}/products/mass-remove', [ShopeeProductGroupController::class, 'massRemove'])->name('ext.shopee.product-groups.massRemove');
        Route::post('/shopee/{store}/product-groups/move', [ShopeeProductGroupController::class, 'moveProducts'])->name('ext.shopee.product-groups.move');
        Route::post('/shopee/{store}/product-groups/{id}/products/delete-from-shopee', [ShopeeProductGroupController::class, 'deleteFromShopee'])->name('ext.shopee.product-groups.deleteFromShopee');
        Route::post('/shopee/{store}/product-groups/{id}/products/{productId}/sync-id', [ShopeeProductGroupController::class, 'syncId'])->name('ext.shopee.product-groups.syncId');
        Route::post('/shopee/{store}/product-groups/{id}/products/{productId}/unlink', [ShopeeProductGroupController::class, 'unlinkProduct'])->name('ext.shopee.product-groups.unlinkProduct');
        Route::post('/shopee/{store}/product-groups/{id}/products/{productId}/link', [ShopeeProductGroupController::class, 'linkProduct'])->name('ext.shopee.product-groups.linkProduct');
        Route::delete('/shopee/{store}/product-groups/{id}/products/{productId}', [ShopeeProductGroupController::class, 'removeProduct'])->name('ext.shopee.product-groups.removeProduct');
        Route::post('/shopee/{store}/product-groups/{id}/template/sync', [ShopeeProductGroupController::class, 'syncTemplate'])->name('ext.shopee.product-groups.template_sync');
        Route::post('/shopee/{store}/product-groups/refresh-categories', [ShopeeProductGroupController::class, 'refreshCategories'])->name('ext.shopee.product-groups.refreshCategories');
        Route::post('/shopee/{store}/product-groups/fetch-attributes', [ShopeeProductGroupController::class, 'fetchAttributesAjax'])->name('ext.shopee.product-groups.fetchAttributes');

        Route::post('/shopee/{store}/products/check', [ShopeeProductController::class, 'checkAgainstShopee'])->name('ext.shopee.products.check');
        Route::post('/shopee/{store}/products/bulk/sync/quantity', [ShopeeProductController::class, 'bulkSyncQuantity'])->name('ext.shopee.products.bulk_sync_quantity');
        Route::post('/shopee/{store}/products/bulk/toggle', [ShopeeProductController::class, 'bulkToggle'])->name('ext.shopee.products.bulk_toggle');
        Route::post('/shopee/{store}/products/bulk/push', [ShopeeProductController::class, 'bulkPush'])->name('ext.shopee.products.bulk_push');
        Route::post('/shopee/{store}/products/refresh-status', [ShopeeProductController::class, 'refreshStatus'])->name('ext.shopee.products.refresh_status');
        Route::post('/shopee/{store}/products/bulk/sync/price', [ShopeeProductController::class, 'bulkSyncPrice'])->name('ext.shopee.products.bulk_sync_price');
        Route::post('/shopee/{store}/products/bulk/delete', [ShopeeProductController::class, 'bulkDeleteFromShopee'])->name('ext.shopee.products.bulk_delete');
        Route::post('/shopee/{store}/products/bulk/remove-from-store', [ShopeeProductController::class, 'bulkRemoveFromStore'])->name('ext.shopee.products.bulk_remove_from_store');
        Route::post('/shopee/{store}/products/import-one', [ShopeeProductController::class, 'importOne'])->name('ext.shopee.products.import_one');
        Route::post('/shopee/{store}/products/import-selected', [ShopeeProductController::class, 'importSelected'])->name('ext.shopee.products.import_selected');
        Route::post('/shopee/{store}/products/link-item', [ShopeeProductController::class, 'linkItem'])->name('ext.shopee.products.link_item');
        Route::post('/shopee/{store}/products/add-to-store', [ShopeeProductController::class, 'addToStoreBulk'])->name('ext.shopee.products.add_to_store_bulk');
        Route::post('/shopee/{store}/products/{productId}/add-to-store', [ShopeeProductController::class, 'addToStore'])->whereNumber('productId')->name('ext.shopee.products.add_to_store');
        Route::post('/shopee/{store}/products/{productId}/remove-from-store', [ShopeeProductController::class, 'removeFromStore'])->whereNumber('productId')->name('ext.shopee.products.remove_from_store');
        Route::post('/shopee/{store}/products/rebuild-cache', [ShopeeProductController::class, 'rebuildCache'])->name('ext.shopee.products.rebuild_cache');
        Route::post('/shopee/{store}/products/{productId}/push-direct', [ShopeeProductController::class, 'pushDirect'])->name('ext.shopee.products.push_direct');
        Route::post('/shopee/{store}/products/{productId}/sync/shopee-id', [ShopeeProductController::class, 'syncSingleProductId'])->name('ext.shopee.products.sync_shopee_id');
        Route::post('/shopee/{store}/products/{productId}/sync/quantity', [ShopeeProductController::class, 'syncQuantity'])->name('ext.shopee.products.sync_quantity');
        Route::post('/shopee/{store}/products/{productId}/sync/price', [ShopeeProductController::class, 'syncPrice'])->name('ext.shopee.products.sync_price');
        Route::post('/shopee/{store}/listings/{productId}', [ShopeeProductController::class, 'saveListing'])->whereNumber('productId')->name('ext.shopee.listings.update');
        Route::post('/shopee/{store}/listings/{productId}/catalog-change', [ShopeeProductController::class, 'catalogChangeSave'])->whereNumber('productId')->name('ext.shopee.listings.catalog_change_save');
        Route::post('/shopee/{store}/listings/{productId}/catalog-change/ignore', [ShopeeProductController::class, 'catalogChangeIgnore'])->whereNumber('productId')->name('ext.shopee.listings.catalog_change_ignore');
        Route::post('/shopee/{store}/listings/{productId}/toggle', [ShopeeProductController::class, 'toggleListing'])->whereNumber('productId')->name('ext.shopee.listings.toggle');
        Route::post('/shopee/{store}/listings/{productId}/reread-variations', [ShopeeProductController::class, 'rereadVariations'])->whereNumber('productId')->name('ext.shopee.listings.reread_variations');
        Route::post('/shopee/{store}/listings/{productId}/push-missing-variations', [ShopeeProductController::class, 'pushMissingVariations'])->whereNumber('productId')->name('ext.shopee.listings.push_missing_variations');
        Route::post('/shopee/{store}/listings/{productId}/push-update', [ShopeeProductController::class, 'pushListingUpdate'])->whereNumber('productId')->name('ext.shopee.listings.push_update');
        Route::post('/shopee/{store}/products/fetch-attributes', [ShopeeProductController::class, 'fetchAttributes'])->name('ext.shopee.products.fetchAttributes');

        Route::post('/shopee/{store}/vouchers', [\Extensions\shopee\Controllers\ShopeeVoucherController::class, 'store'])->name('ext.shopee.vouchers.store');
        Route::post('/shopee/{store}/vouchers/{voucherId}/end', [\Extensions\shopee\Controllers\ShopeeVoucherController::class, 'end'])->whereNumber('voucherId')->name('ext.shopee.vouchers.end');
        Route::post('/shopee/{store}/vouchers/{voucherId}/delete', [\Extensions\shopee\Controllers\ShopeeVoucherController::class, 'destroy'])->whereNumber('voucherId')->name('ext.shopee.vouchers.delete');
        Route::post('/shopee/{store}/products/{productId}/unlink', [ShopeeProductController::class, 'unlink'])->name('ext.shopee.products.unlink');
        Route::post('/shopee/{store}/products/{productId}/delete', [ShopeeProductController::class, 'deleteFromShopee'])->name('ext.shopee.products.delete');
    });

    Route::group([], function () {
        Route::get('/shopee/{store}/orders', [ShopeeOrderController::class, 'index'])->name('ext.shopee.orders.index');
        Route::get('/shopee/{store}/orders/returns', [ShopeeOrderController::class, 'returns'])->name('ext.shopee.orders.returns');
        Route::get('/shopee/{store}/orders/returns/{returnSn}', [ShopeeOrderController::class, 'returnDetail'])->where('returnSn', '[A-Za-z0-9]+')->name('ext.shopee.orders.return_detail');
        Route::get('/shopee/{store}/orders/returns/{returnSn}/solutions', [ShopeeOrderController::class, 'returnSolutions'])
            ->where('returnSn', '[A-Za-z0-9]+')
            ->name('ext.shopee.orders.return_solutions');
        Route::get('/shopee/{store}/orders/{orderSn}', [ShopeeOrderController::class, 'show'])->name('ext.shopee.orders.show');
        Route::get('/shopee/{store}/orders/{orderSn}/tracking', [ShopeeOrderController::class, 'getTrackingNumber'])->name('ext.shopee.orders.tracking');
        Route::get('/shopee/{store}/orders/{orderSn}/shipping-addresses', [ShopeeOrderController::class, 'getShippingAddresses'])->name('ext.shopee.orders.shipping_addresses');
        Route::get('/shopee/{store}/orders/{orderSn}/tracking-info', [ShopeeOrderController::class, 'getTrackingInfo'])->name('ext.shopee.orders.tracking_info');
        Route::get('/shopee/{store}/orders/{orderSn}/awb', [ShopeeOrderController::class, 'awbPdf'])->name('ext.shopee.orders.awb');
    });

    Route::group([], function () {
        Route::post('/shopee/{store}/orders/fetch', [ShopeeOrderController::class, 'fetch'])->name('ext.shopee.orders.fetch');
        Route::post('/shopee/{store}/orders/fetch-runs', [ShopeeOrderController::class, 'fetchRunBegin'])->name('ext.shopee.orders.fetch_run_begin');
        Route::post('/shopee/{store}/orders/fetch-runs/{run}/step', [ShopeeOrderController::class, 'fetchRunStep'])->whereNumber('run')->name('ext.shopee.orders.fetch_run_step');
        Route::post('/shopee/{store}/orders/fetch-runs/{run}/stop', [ShopeeOrderController::class, 'fetchRunStop'])->whereNumber('run')->name('ext.shopee.orders.fetch_run_stop');
        Route::get('/shopee/{store}/orders/fetch-runs/{run}', [ShopeeOrderController::class, 'fetchRunState'])->whereNumber('run')->name('ext.shopee.orders.fetch_run_state');
        Route::post('/shopee/{store}/orders/update-statuses', [ShopeeOrderController::class, 'updateStatuses'])->name('ext.shopee.orders.update_statuses');
        Route::post('/shopee/{store}/orders/reset', [ShopeeOrderController::class, 'reset'])->name('ext.shopee.orders.reset');
        Route::post('/shopee/{store}/orders/{orderSn}/ship', [ShopeeOrderController::class, 'shipOrder'])->middleware(['packing.check:shopee', 'booking.lock:shopee'])->name('ext.shopee.orders.ship');
        Route::post('/shopee/{store}/orders/fetch-returns', [ShopeeOrderController::class, 'fetchReturns'])->name('ext.shopee.orders.fetch_returns');
    });

});

Route::middleware(['auth', \Extensions\shopee\Http\ResolveShopeeStore::class])->prefix('channels')->group(function () {
    Route::get('/shopee/{store?}', [\Extensions\shopee\Controllers\ShopeeDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        ->defaults('permission_denial', '404')
        ->name('ext.shopee.dashboard');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/shopee/{legacyPath}', function (\Illuminate\Http\Request $request, string $legacyPath) {
        $store = \Extensions\shopee\Models\ShopeeSetting::query()->where('enabled', true)->orderBy('id')->value('id');
        abort_unless($store !== null, 404);
        $qs = $request->getQueryString();

        return redirect('/channels/shopee/' . $store . '/' . $legacyPath . ($qs ? ('?' . $qs) : ''), 301);
    })->where('legacyPath', '.*')->name('ext.shopee.legacy_storeless');
});
