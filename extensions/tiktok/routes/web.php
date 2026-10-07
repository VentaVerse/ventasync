<?php

use Extensions\tiktok\Controllers\TikTokDescriptionTemplateController;
use Extensions\tiktok\Controllers\TikTokWatermarkTemplateController;
use Extensions\tiktok\Controllers\TiktokSettingsController;
use Extensions\tiktok\Controllers\TiktokApiExplorerController;
use Extensions\tiktok\Controllers\TikTokOrderController;
use Extensions\tiktok\Controllers\TikTokCouponController;
use Extensions\tiktok\Controllers\TikTokListingController;
use Extensions\tiktok\Controllers\TikTokProductController;
use Extensions\tiktok\Controllers\TikTokProductGroupController;
use Illuminate\Support\Facades\Route;

// The OAuth callback is one fixed URL registered byte for byte in TikTok; no {store} segment.
// The store rides the HMAC-signed state.
Route::get('/tiktok/callback', [TiktokSettingsController::class, 'callback'])->name('ext.tiktok.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('/tiktok/authorize', [TiktokSettingsController::class, 'redirectToAuth'])->name('ext.tiktok.authorize')
        ->defaults('permission_tier', 'manage');
});

Route::middleware(['auth', \Extensions\tiktok\Http\ResolveTikTokStore::class])
    ->prefix('channels')->where(['store' => '[0-9]+'])->group(function () {

    Route::group([], function () {
        Route::post('/tiktok/stores', [TiktokSettingsController::class, 'createStore'])->name('ext.tiktok.stores.store');

        Route::get('/tiktok/{store}/settings', [TiktokSettingsController::class, 'index'])->name('ext.tiktok.index');

        Route::get('/tiktok/{store}/categories', [TikTokProductGroupController::class, 'indexCategories'])->name('ext.tiktok.categories.index');

        Route::get('/tiktok/{store}/product-groups', [TikTokProductGroupController::class, 'index'])->name('ext.tiktok.product-groups.index');
        
        Route::get('/tiktok/{store}/description-templates', [TikTokDescriptionTemplateController::class, 'index'])->name('ext.tiktok.description-templates.index');
        Route::post('/tiktok/{store}/description-templates', [TikTokDescriptionTemplateController::class, 'store'])->name('ext.tiktok.description-templates.store')->defaults('permission_tier', 'manage');
        Route::put('/tiktok/{store}/description-templates/{template}', [TikTokDescriptionTemplateController::class, 'update'])->whereNumber('template')->name('ext.tiktok.description-templates.update')->defaults('permission_tier', 'manage');
        Route::delete('/tiktok/{store}/description-templates/{template}', [TikTokDescriptionTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.tiktok.description-templates.destroy')->defaults('permission_tier', 'manage');

        Route::get('/tiktok/{store}/products/categories/children', [TikTokProductController::class, 'categoryChildren'])->name('ext.tiktok.products.category_children');
        Route::get('/tiktok/{store}/products/categories/path', [TikTokProductController::class, 'categoryPath'])->name('ext.tiktok.products.category_path');
        Route::get('/tiktok/{store}/watermarks', [TikTokWatermarkTemplateController::class, 'index'])->name('ext.tiktok.watermarks.index');
        Route::get('/tiktok/{store}/watermarks/preview', [TikTokWatermarkTemplateController::class, 'preview'])->name('ext.tiktok.watermarks.preview');
        Route::get('/tiktok/{store}/watermarks/create', [TikTokWatermarkTemplateController::class, 'create'])->name('ext.tiktok.watermarks.create')->defaults('permission_tier', 'manage');
        Route::post('/tiktok/{store}/watermarks', [TikTokWatermarkTemplateController::class, 'store'])->name('ext.tiktok.watermarks.store')->defaults('permission_tier', 'manage');
        Route::get('/tiktok/{store}/watermarks/{template}/edit', [TikTokWatermarkTemplateController::class, 'edit'])->whereNumber('template')->name('ext.tiktok.watermarks.edit')->defaults('permission_tier', 'manage');
        Route::put('/tiktok/{store}/watermarks/{template}', [TikTokWatermarkTemplateController::class, 'update'])->whereNumber('template')->name('ext.tiktok.watermarks.update')->defaults('permission_tier', 'manage');
        Route::delete('/tiktok/{store}/watermarks/{template}', [TikTokWatermarkTemplateController::class, 'destroy'])->whereNumber('template')->name('ext.tiktok.watermarks.destroy')->defaults('permission_tier', 'manage');
        Route::get('/tiktok/{store}/product-groups/{id}/edit', [TikTokProductGroupController::class, 'edit'])->name('ext.tiktok.product-groups.edit');
        Route::get('/tiktok/{store}/product-groups/{id}/products', [TikTokProductGroupController::class, 'products'])->name('ext.tiktok.product-groups.products');
        Route::get('/tiktok/{store}/product-groups/{id}/products/search', [TikTokProductGroupController::class, 'productSearch'])->whereNumber('id')->name('ext.tiktok.product-groups.productSearch');
        Route::get('/tiktok/{store}/product-groups/{id}/orphans', [TikTokProductGroupController::class, 'orphans'])->whereNumber('id')->name('ext.tiktok.product-groups.orphans');

        Route::get('/tiktok/{store}/coupons', [TikTokCouponController::class, 'index'])->name('ext.tiktok.coupons.index');

        Route::get('/tiktok/{store}/products', [TikTokProductController::class, 'index'])->name('ext.tiktok.products.index');
        Route::get('/tiktok/{store}/products/catalogue-search', [TikTokProductController::class, 'catalogueSearch'])->name('ext.tiktok.products.catalogue_search');
        Route::get('/tiktok/{store}/products/import', [TikTokProductController::class, 'importPage'])->name('ext.tiktok.products.import');
        Route::get('/tiktok/{store}/products/categories/search', [TikTokProductController::class, 'searchCategories'])->name('ext.tiktok.products.searchCategories');
        Route::get('/tiktok/{store}/products/categories/lookup', [TikTokProductController::class, 'categoryLookup'])->name('ext.tiktok.products.categoryLookup');
        Route::get('/tiktok/{store}/products/brands', [TikTokProductController::class, 'brandsSearch'])->name('ext.tiktok.products.brandsSearch');

        Route::get('/tiktok/{store}/listings/{productId}', [TikTokListingController::class, 'edit'])
            ->whereNumber('productId')->name('ext.tiktok.listings.edit');
    });

    Route::group([], function () {
        Route::post('/tiktok/{store}/save', [TiktokSettingsController::class, 'save'])->name('ext.tiktok.save');
        Route::post('/tiktok/{store}/toggle-mode', [TiktokSettingsController::class, 'toggleMode'])->name('ext.tiktok.toggle_mode');
        Route::post('/tiktok/{store}/token/get', [TiktokSettingsController::class, 'tokenGet'])->name('ext.tiktok.token_get');
        Route::post('/tiktok/{store}/token/refresh', [TiktokSettingsController::class, 'tokenRefresh'])->name('ext.tiktok.token_refresh');
        Route::post('/tiktok/{store}/shops', [TiktokSettingsController::class, 'getShops'])->name('ext.tiktok.shops');

        Route::post('/tiktok/{store}/explorer/run', [TiktokApiExplorerController::class, 'explorerRun'])->name('ext.tiktok.explorer_run');
        Route::post('/tiktok/{store}/packs/run', [TiktokApiExplorerController::class, 'packsRun'])->name('ext.tiktok.packs_run');

        Route::post('/tiktok/{store}/api-log-mode', [TiktokSettingsController::class, 'setApiLogMode'])->name('ext.tiktok.api_log_mode');
        Route::delete('/tiktok/{store}/api-logs', [TiktokSettingsController::class, 'clearApiLogs'])->name('ext.tiktok.clear_api_logs');
        Route::post('/tiktok/{store}/purge-raw', [TiktokSettingsController::class, 'purgeRaw'])->name('ext.tiktok.purge_raw');
        Route::post('/tiktok/{store}/delete', [TiktokSettingsController::class, 'destroyStore'])->name('ext.tiktok.stores.destroy');
        Route::post('/tiktok/{store}/setup/{step}', [TiktokSettingsController::class, 'setupStep'])->whereIn('step', ['shop', 'categories'])->name('ext.tiktok.setup_step');

        Route::post('/tiktok/{store}/order-status-map', [TiktokSettingsController::class, 'saveOrderStatusMap'])->name('ext.tiktok.order_status_map');
        Route::post('/tiktok/{store}/return-status-map', [TiktokSettingsController::class, 'saveReturnStatusMap'])->name('ext.tiktok.return_status_map');

        Route::post('/tiktok/{store}/categories/sync', [TikTokProductGroupController::class, 'syncCategories'])->name('ext.tiktok.categories.sync');

        Route::get('/tiktok/{store}/product-groups/create', [TikTokProductGroupController::class, 'create'])->name('ext.tiktok.product-groups.create')
        ->defaults('permission_tier', 'manage');
        Route::post('/tiktok/{store}/product-groups/fetch-attributes', [TikTokProductGroupController::class, 'fetchAttributesAjax'])->name('ext.tiktok.product-groups.fetchAttributes');
        Route::post('/tiktok/{store}/product-groups', [TikTokProductGroupController::class, 'store'])->name('ext.tiktok.product-groups.store');
        Route::put('/tiktok/{store}/product-groups/{id}', [TikTokProductGroupController::class, 'update'])->name('ext.tiktok.product-groups.update');
        Route::delete('/tiktok/{store}/product-groups/{id}', [TikTokProductGroupController::class, 'destroy'])->name('ext.tiktok.product-groups.destroy');
        Route::post('/tiktok/{store}/product-groups/{id}/products/{productId}/add', [TikTokProductGroupController::class, 'addProduct'])->whereNumber('id')->whereNumber('productId')->name('ext.tiktok.product-groups.addProduct');
        Route::post('/tiktok/{store}/product-groups/{id}/products/{product}/sync-id', [TikTokProductGroupController::class, 'syncId'])->name('ext.tiktok.product-groups.syncId');
        Route::post('/tiktok/{store}/product-groups/{id}/products/{product}/unlink', [TikTokProductGroupController::class, 'unlinkProduct'])->name('ext.tiktok.product-groups.unlinkProduct');
        Route::post('/tiktok/{store}/product-groups/{id}/products/{product}/link', [TikTokProductGroupController::class, 'linkProduct'])->name('ext.tiktok.product-groups.linkProduct');
        Route::delete('/tiktok/{store}/product-groups/{id}/products/{product}', [TikTokProductGroupController::class, 'removeProduct'])->name('ext.tiktok.product-groups.removeProduct');
        Route::post('/tiktok/{store}/product-groups/{id}/mass-remove', [TikTokProductGroupController::class, 'massRemove'])->name('ext.tiktok.product-groups.massRemove');
        Route::post('/tiktok/{store}/product-groups/move', [TikTokProductGroupController::class, 'moveProducts'])->name('ext.tiktok.product-groups.move');
        Route::post('/tiktok/{store}/product-groups/{id}/delete-from-tiktok', [TikTokProductGroupController::class, 'deleteFromTikTok'])->name('ext.tiktok.product-groups.deleteFromTikTok');
        Route::post('/tiktok/{store}/product-groups/{id}/products/check', [TikTokProductGroupController::class, 'checkAgainstTikTok'])->whereNumber('id')->name('ext.tiktok.product-groups.check');
        Route::post('/tiktok/{store}/product-groups/{id}/push', [TikTokProductGroupController::class, 'push'])->name('ext.tiktok.product-groups.push');
        Route::post('/tiktok/{store}/product-groups/{id}/send-runs', [TikTokProductGroupController::class, 'sendRunBegin'])->whereNumber('id')->name('ext.tiktok.product-groups.send_run_begin');
        Route::post('/tiktok/{store}/product-groups/{id}/send-runs/{run}/step', [TikTokProductGroupController::class, 'sendRunStep'])->whereNumber('id')->whereNumber('run')->name('ext.tiktok.product-groups.send_run_step');
        Route::post('/tiktok/{store}/product-groups/{id}/send-runs/{run}/stop', [TikTokProductGroupController::class, 'sendRunStop'])->whereNumber('id')->whereNumber('run')->name('ext.tiktok.product-groups.send_run_stop');
        Route::post('/tiktok/{store}/product-groups/{id}/update-product', [TikTokProductGroupController::class, 'updateProduct'])->name('ext.tiktok.product-groups.updateProduct');
        Route::post('/tiktok/{store}/product-groups/{id}/push-prices', [TikTokProductGroupController::class, 'pushPrices'])->name('ext.tiktok.product-groups.pushPrices');
        Route::post('/tiktok/{store}/product-groups/{id}/push-stock', [TikTokProductGroupController::class, 'pushStock'])->name('ext.tiktok.product-groups.pushStock');

        Route::post('/tiktok/{store}/products/fetch-attributes', [TikTokProductController::class, 'fetchAttributes'])->name('ext.tiktok.products.fetchAttributes');
        Route::get('/tiktok/{store}/products/search-catalog', [TikTokProductController::class, 'searchCatalogProducts'])->name('ext.tiktok.products.search_catalog');
        Route::post('/tiktok/{store}/products/import-one', [TikTokProductController::class, 'importOne'])->name('ext.tiktok.products.import_one');
        Route::get('/tiktok/{store}/products/thumb/{ref}', [TikTokProductController::class, 'thumb'])->name('ext.tiktok.products.thumb');
        Route::post('/tiktok/{store}/products/import-selected', [TikTokProductController::class, 'importSelected'])->name('ext.tiktok.products.import_selected');
        Route::post('/tiktok/{store}/products/link-item', [TikTokProductController::class, 'linkItem'])->name('ext.tiktok.products.link_item');
        Route::post('/tiktok/{store}/products/add-to-store', [TikTokProductController::class, 'addToStoreBulk'])->name('ext.tiktok.products.add_to_store_bulk');
        Route::post('/tiktok/{store}/products/{productId}/add-to-store', [TikTokProductController::class, 'addToStore'])->whereNumber('productId')->name('ext.tiktok.products.add_to_store');
        Route::post('/tiktok/{store}/products/{productId}/remove-from-store', [TikTokProductController::class, 'removeFromStore'])->whereNumber('productId')->name('ext.tiktok.products.remove_from_store');
        Route::post('/tiktok/{store}/products/check', [TikTokProductController::class, 'checkAgainstTikTok'])->name('ext.tiktok.products.check');
        Route::post('/tiktok/{store}/products/bulk/push', [TikTokProductController::class, 'bulkPush'])->name('ext.tiktok.products.bulk_push');
        Route::post('/tiktok/{store}/products/bulk/push-stock', [TikTokProductController::class, 'bulkPushStock'])->name('ext.tiktok.products.bulk_push_stock');
        Route::post('/tiktok/{store}/products/bulk/push-price', [TikTokProductController::class, 'bulkPushPrice'])->name('ext.tiktok.products.bulk_push_price');
        Route::post('/tiktok/{store}/products/bulk/delete', [TikTokProductController::class, 'bulkDeleteFromTikTok'])->name('ext.tiktok.products.bulk_delete');
        Route::post('/tiktok/{store}/products/bulk/remove-from-store', [TikTokProductController::class, 'bulkRemoveFromStore'])->name('ext.tiktok.products.bulk_remove_from_store');
        Route::post('/tiktok/{store}/products/bulk/toggle', [TikTokListingController::class, 'bulkToggle'])->name('ext.tiktok.products.bulk_toggle');
        Route::post('/tiktok/{store}/products/refresh-status', [TikTokProductController::class, 'refreshStatus'])->name('ext.tiktok.products.refresh_status');
        Route::post('/tiktok/{store}/products/{productId}/push', [TikTokProductController::class, 'pushDirect'])->whereNumber('productId')->name('ext.tiktok.products.push_direct');
        Route::post('/tiktok/{store}/products/{productId}/push-stock', [TikTokProductController::class, 'pushStock'])->whereNumber('productId')->name('ext.tiktok.products.push_stock');
        Route::post('/tiktok/{store}/products/{productId}/push-price', [TikTokProductController::class, 'pushPrice'])->whereNumber('productId')->name('ext.tiktok.products.push_price');
        Route::post('/tiktok/{store}/products/{productId}/unlink', [TikTokProductController::class, 'unlink'])->whereNumber('productId')->name('ext.tiktok.products.unlink');
        Route::post('/tiktok/{store}/products/{productId}/delete', [TikTokProductController::class, 'deleteFromTikTok'])->whereNumber('productId')->name('ext.tiktok.products.delete');
        Route::post('/tiktok/{store}/listings/{productId}/push-update', [TikTokListingController::class, 'pushListingUpdate'])->whereNumber('productId')->name('ext.tiktok.listings.push_update');
        Route::post('/tiktok/{store}/listings/{productId}/toggle', [TikTokListingController::class, 'toggleListing'])->whereNumber('productId')->name('ext.tiktok.listings.toggle');

        Route::put('/tiktok/{store}/listings/{productId}', [TikTokListingController::class, 'update'])->whereNumber('productId')->name('ext.tiktok.listings.update');
        Route::post('/tiktok/{store}/listings/{productId}/catalog-change', [TikTokListingController::class, 'catalogChangeSave'])->whereNumber('productId')->name('ext.tiktok.listings.catalog_change_save');
        Route::post('/tiktok/{store}/listings/{productId}/catalog-change/ignore', [TikTokListingController::class, 'catalogChangeIgnore'])->whereNumber('productId')->name('ext.tiktok.listings.catalog_change_ignore');

        Route::post('/tiktok/{store}/coupons', [TikTokCouponController::class, 'store'])->name('ext.tiktok.coupons.store');
        Route::post('/tiktok/{store}/coupons/{couponId}/deactivate', [TikTokCouponController::class, 'deactivate'])->where('couponId', '[A-Za-z0-9_-]+')->name('ext.tiktok.coupons.deactivate');
    });

    Route::group([], function () {
        Route::get('/tiktok/{store}/orders', [TikTokOrderController::class, 'index'])->name('ext.tiktok.orders.index');
        Route::get('/tiktok/{store}/orders/returns', [TikTokOrderController::class, 'returns'])->name('ext.tiktok.orders.returns');
        Route::get('/tiktok/{store}/orders/{id}/show', [TikTokOrderController::class, 'show'])->name('ext.tiktok.orders.show');
        Route::get('/tiktok/{store}/orders/{id}/awb', [TikTokOrderController::class, 'awbPdf'])->name('ext.tiktok.orders.awb');
        Route::get('/tiktok/{store}/orders/{id}/tracking', [TikTokOrderController::class, 'tracking'])->name('ext.tiktok.orders.tracking');
    });

    Route::group([], function () {
        Route::post('/tiktok/{store}/orders/fetch', [TikTokOrderController::class, 'fetch'])->name('ext.tiktok.orders.fetch');
        Route::post('/tiktok/{store}/orders/fetch-runs', [TikTokOrderController::class, 'fetchRunBegin'])->name('ext.tiktok.orders.fetch_run_begin');
        Route::post('/tiktok/{store}/orders/fetch-runs/{run}/step', [TikTokOrderController::class, 'fetchRunStep'])->whereNumber('run')->name('ext.tiktok.orders.fetch_run_step');
        Route::post('/tiktok/{store}/orders/fetch-runs/{run}/stop', [TikTokOrderController::class, 'fetchRunStop'])->whereNumber('run')->name('ext.tiktok.orders.fetch_run_stop');
        Route::get('/tiktok/{store}/orders/fetch-runs/{run}', [TikTokOrderController::class, 'fetchRunState'])->whereNumber('run')->name('ext.tiktok.orders.fetch_run_state');
        Route::post('/tiktok/{store}/orders/returns/fetch', [TikTokOrderController::class, 'fetchReturns'])->name('ext.tiktok.orders.fetch_returns');
        Route::post('/tiktok/{store}/orders/update-statuses', [TikTokOrderController::class, 'updateStatuses'])->name('ext.tiktok.orders.updateStatuses');
        Route::post('/tiktok/{store}/orders/{id}/ship', [TikTokOrderController::class, 'shipOrder'])->middleware(['packing.check:tiktok', 'booking.lock:tiktok'])->name('ext.tiktok.orders.ship');
    });
});

Route::middleware(['auth', \Extensions\tiktok\Http\ResolveTikTokStore::class])->prefix('channels')->group(function () {
    Route::get('/tiktok/{store?}', [\Extensions\tiktok\Controllers\TiktokDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        ->defaults('permission_denial', '404')
        ->name('ext.tiktok.dashboard');
});

Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/tiktok/{legacyPath}', function (\Illuminate\Http\Request $request, string $legacyPath) {
        $store = \Extensions\tiktok\Models\TikTokSetting::query()->where('enabled', true)->orderBy('id')->value('id');
        abort_unless($store !== null, 404);
        $qs = $request->getQueryString();

        return redirect('/channels/tiktok/' . $store . '/' . $legacyPath . ($qs ? ('?' . $qs) : ''), 301);
    })->where('legacyPath', '.*')->name('ext.tiktok.legacy_storeless');
});
