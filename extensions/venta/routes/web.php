<?php

use Extensions\venta\Controllers\VentaListingController;
use Extensions\venta\Controllers\VentaSettingsController;
use Extensions\venta\Controllers\VentaProductGroupController;
use Extensions\venta\Controllers\VentaOrderController;
use Extensions\venta\Controllers\VentaReviewController;
use Illuminate\Support\Facades\Route;

// ── Tier-aware permission map (Phase 4 of view/manage split) ────────────
//
// Read routes — gated by 'view_venta|manage_venta' (pipe-OR, either tier
// grants access). Display GETs only.
// Write routes — gated by 'manage_venta' (or 'manage_venta_orders').

// IA migration (2026-08-08, Task 2), spec decision 3. Venta is multi-store, so
// its URIs use SLASH grammar under the workspace root: the store is a path
// segment directly beneath the channel, and everything for that store hangs
// off it. /integrations/venta/2/orders reads as "Venta, store 2, orders".
//
// Two shapes changed beyond gaining the prefix:
//   /venta/settings/{store}  ->  /integrations/venta/{store}/settings
//        the store scopes the page, so it belongs ahead of what it scopes;
//        the old order put the noun before the thing it belonged to.
//   /venta                   ->  /integrations/venta/settings
//        a bare /integrations/venta is the workspace Overview, registered in
//        routes/web.php ahead of every extension route, so this redirect-to-
//        first-store fallback needs a segment of its own to stay reachable.
//
// Route NAMES are unchanged throughout. Venta has no OAuth surface: it
// authenticates with a stored API token, so nothing here is exempt.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    // Settings (renders the screen; per-store config is loaded into a form)
    Route::get('/venta/{store}/settings', [VentaSettingsController::class, 'showSettings'])->name('ext.venta.settings.show');

    // Legacy / fallback (redirect to first enabled store) — pure navigation
    Route::get('/venta/settings', [VentaSettingsController::class, 'index'])->name('ext.venta.index');

    // Product Groups — read-only list + edit-form RENDER (GETs)
    Route::get('/venta/{store}/product-groups', [VentaProductGroupController::class, 'index'])->name('ext.venta.product-groups.index');
    Route::get('/venta/{store}/product-groups/{group}/edit', [VentaProductGroupController::class, 'edit'])->name('ext.venta.product-groups.edit');
    Route::get('/venta/{store}/product-groups/{group}/products', [VentaProductGroupController::class, 'products'])->name('ext.venta.product-groups.products');
    // Read tier, beside the products list it mirrors, NOT with the writes.
    // It only asks Venta what it holds and prints the answer; it adopts
    // nothing and deletes nothing. Seeing that the two sides have drifted is
    // exactly what a view-only operator is for - they are the ones who notice
    // and escalate - and putting it behind manage_venta would have hidden the
    // report from the people most likely to read it.
    Route::get('/venta/{store}/product-groups/{group}/orphans', [VentaProductGroupController::class, 'orphans'])->name('ext.venta.product-groups.orphans');

    // Product search (JSON API) — read-only lookup
    Route::get('/venta/{store}/product-groups/search-products', [VentaProductGroupController::class, 'searchProducts'])->name('ext.venta.product-groups.searchProducts');

    // The per-product page (#171): one product on one store, the same
    // anatomy as Shopee's and TikTok's listing pages. Readable by the view
    // tier (it renders read-only with no verbs); the writes are manage
    // routes below. Derives its own venta/listing permission pair, as
    // TikTok's does, so a listings-only operator can be given exactly this.
    Route::get('/venta/{store}/listings', [VentaListingController::class, 'index'])->name('ext.venta.listings.index');
    Route::post('/venta/{store}/listings/check', [VentaListingController::class, 'checkAgainstVenta'])->name('ext.venta.listings.check');
    // The Listings page's missing verbs (parity, 2026-09-07): stock, price, unlink, delete at the store, and their bulk forms.
    Route::post('/venta/{store}/listings/{productId}/push-stock', [VentaListingController::class, 'pushStock'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.listings.push_stock');
    Route::post('/venta/{store}/listings/{productId}/push-price', [VentaListingController::class, 'pushPrice'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.listings.push_price');
    Route::post('/venta/{store}/listings/bulk/push-stock', [VentaListingController::class, 'bulkPushStock'])->whereNumber('store')->name('ext.venta.listings.bulk_push_stock');
    Route::post('/venta/{store}/listings/bulk/push-prices', [VentaListingController::class, 'bulkPushPrices'])->whereNumber('store')->name('ext.venta.listings.bulk_push_prices');
    Route::post('/venta/{store}/products/{productId}/unlink', [VentaListingController::class, 'unlink'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.products.unlink');
    Route::post('/venta/{store}/products/{productId}/delete', [VentaListingController::class, 'deleteFromStore'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.products.delete');
    Route::post('/venta/{store}/products/bulk/delete', [VentaListingController::class, 'bulkDeleteFromStore'])->whereNumber('store')->name('ext.venta.products.bulk_delete');
    Route::post('/venta/{store}/products/bulk/remove-from-store', [VentaListingController::class, 'bulkRemoveFromStore'])->whereNumber('store')->name('ext.venta.products.bulk_remove_from_store');
    // Stateless import (the owner, 2026-09-04): GET ?fetch=1 reads the store
    // live and renders; import/link act on the values the page carries.
    // Nothing is saved, so there is no queue and nothing to dismiss.
    Route::get('/venta/{store}/products/import', [VentaListingController::class, 'importPage'])->name('ext.venta.products.import');
    Route::get('/venta/{store}/products/search-catalog', [VentaListingController::class, 'searchCatalog'])->name('ext.venta.products.search_catalog');
    Route::post('/venta/{store}/products/import-one', [VentaListingController::class, 'importOne'])->whereNumber('store')->name('ext.venta.products.import_one');
    Route::post('/venta/{store}/products/import-selected', [VentaListingController::class, 'importSelected'])->whereNumber('store')->name('ext.venta.products.import_selected');
    Route::post('/venta/{store}/products/link-item', [VentaListingController::class, 'linkItem'])->whereNumber('store')->name('ext.venta.products.link_item');
    // Add products slide-over (#201): the catalog search, add one or several,
    // remove from this store's list. Nothing here talks to the store.
    Route::get('/venta/{store}/products/catalogue-search', [VentaListingController::class, 'catalogueSearch'])->whereNumber('store')->name('ext.venta.products.catalogue_search');
    Route::post('/venta/{store}/products/add-to-store/{productId}', [VentaListingController::class, 'addToStore'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.products.add_to_store');
    Route::post('/venta/{store}/products/add-to-store', [VentaListingController::class, 'addToStoreBulk'])->whereNumber('store')->name('ext.venta.products.add_to_store_bulk');
    Route::post('/venta/{store}/products/remove-from-store/{productId}', [VentaListingController::class, 'removeFromStore'])->whereNumber('store')->whereNumber('productId')->name('ext.venta.products.remove_from_store');
    Route::get('/venta/{store}/listings/{productId}', [VentaListingController::class, 'edit'])
        ->whereNumber('productId')->name('ext.venta.listings.edit');
});

// ── Venta Settings + Catalog — WRITES ───────────────────────────
Route::middleware(['auth'])->prefix('channels')->group(function () {
    // "Add New Store" modal target — creates a row with name + base_url only.
    Route::post('/venta/stores', [VentaSettingsController::class, 'createStore'])->name('ext.venta.stores.store');

    // Per-store settings writes
    Route::post('/venta/save', [VentaSettingsController::class, 'save'])->name('ext.venta.save');
    Route::delete('/venta/{id}', [VentaSettingsController::class, 'destroy'])->name('ext.venta.destroy');
    // The typed-name hard delete behind Delete this store (the Shopee shape, ported 2026-09-06).
    Route::post('/venta/{store}/delete', [VentaSettingsController::class, 'destroyStore'])->whereNumber('store')->name('ext.venta.stores.destroy');
    // Set up the store: categories, brands, statuses, one step per call, behind the setup popup.
    Route::post('/venta/{store}/setup/{step}', [VentaSettingsController::class, 'setupStep'])->whereNumber('store')->whereIn('step', ['categories', 'brands', 'statuses'])->name('ext.venta.setup_step');
    Route::post('/venta/test', [VentaSettingsController::class, 'testConnection'])->name('ext.venta.test');
    Route::post('/venta/save-status-map', [VentaSettingsController::class, 'saveOrderStatusMap'])->name('ext.venta.save_status_map');
    Route::post('/venta/{id}/api-log-mode', [VentaSettingsController::class, 'setApiLogMode'])->name('ext.venta.api_log_mode');
    Route::delete('/venta/{id}/api-logs', [VentaSettingsController::class, 'clearApiLogs'])->name('ext.venta.clear_api_logs');
    // The sync log's own pair, beside the API log's: the controls belong on
    // the tab that shows the log, not in a global setting (the owner, 2026-09-01).
    Route::post('/venta/{id}/sync-log-level', [VentaSettingsController::class, 'setSyncLogLevel'])->name('ext.venta.set_sync_log_level');
    Route::delete('/venta/{id}/sync-logs', [VentaSettingsController::class, 'clearSyncLogs'])->name('ext.venta.clear_sync_logs');
    Route::post('/venta/fetch-statuses', [VentaSettingsController::class, 'fetchVentaStatuses'])->name('ext.venta.fetch_statuses');

    // Fetch categories/brands from Venta API (AJAX) — calls the marketplace API
    Route::post('/venta/fetch-categories', [VentaSettingsController::class, 'fetchCategories'])->name('ext.venta.fetch_categories');
    Route::post('/venta/fetch-brands', [VentaSettingsController::class, 'fetchBrands'])->name('ext.venta.fetch_brands');

    // Product Groups — writes (create, update, destroy, push)
    Route::get('/venta/{store}/product-groups/create', [VentaProductGroupController::class, 'create'])->name('ext.venta.product-groups.create')
        // A form page is a MANAGE surface even though it is a GET: the old
        // perm: group said so, and removing the group must not demote it.
        ->defaults('permission_tier', 'manage');
    Route::post('/venta/{store}/product-groups', [VentaProductGroupController::class, 'store'])->name('ext.venta.product-groups.store');
    Route::put('/venta/{store}/product-groups/{group}', [VentaProductGroupController::class, 'update'])->name('ext.venta.product-groups.update');
    Route::delete('/venta/{store}/product-groups/{group}', [VentaProductGroupController::class, 'destroy'])->name('ext.venta.product-groups.destroy');

    Route::post('/venta/{store}/product-groups/{group}/push', [VentaProductGroupController::class, 'pushProducts'])->name('ext.venta.product-groups.push');
    Route::post('/venta/{store}/product-groups/{group}/push-stock', [VentaProductGroupController::class, 'pushStock'])->name('ext.venta.product-groups.push-stock');
    Route::post('/venta/{store}/product-groups/{group}/push-prices', [VentaProductGroupController::class, 'pushPrices'])->name('ext.venta.product-groups.push-prices');
    Route::post('/venta/{store}/product-groups/{group}/add-products', [VentaProductGroupController::class, 'addProducts'])->name('ext.venta.product-groups.addProducts');
    Route::post('/venta/{store}/product-groups/{group}/mass-remove', [VentaProductGroupController::class, 'massRemove'])->name('ext.venta.product-groups.mass-remove');
    // Renamed from sync-ids/syncIds. The old name described the mechanism
    // (write ids into rows); this one describes what the operator is asking
    // for, and the behaviour changed with it: it now examines products that
    // are already linked, which is the only way a stale link can be found.
    Route::post('/venta/{store}/product-groups/{group}/check', [VentaProductGroupController::class, 'checkAgainstVenta'])->name('ext.venta.product-groups.check');
    Route::delete('/venta/{store}/product-groups/{group}/products/{product}', [VentaProductGroupController::class, 'removeProduct'])->name('ext.venta.product-groups.removeProduct');

    // Per-product actions
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/sync-id', [VentaProductGroupController::class, 'syncId'])->name('ext.venta.product-groups.sync-id');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/unlink', [VentaProductGroupController::class, 'unlinkProduct'])->name('ext.venta.product-groups.unlink');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/delete-from-venta', [VentaProductGroupController::class, 'deleteFromVenta'])->name('ext.venta.product-groups.deleteFromVenta');
    // The bulk form of the line above, posting ids[] instead of naming one
    // product in the path. Same controller method, same permission; Venta was
    // the only channel of the four that could not do this.
    Route::post('/venta/{store}/product-groups/{group}/delete-from-venta', [VentaProductGroupController::class, 'deleteFromVenta'])->name('ext.venta.product-groups.bulk-delete-from-venta');
    Route::post('/venta/{store}/product-groups/{group}/products/{product}/link', [VentaProductGroupController::class, 'linkProduct'])->name('ext.venta.product-groups.link');

    // Listing page writes (#171): the record, the push from it, unlist and relist.
    // Static segments first, so 'refresh-status' and 'bulk-toggle' can never be read as a product id.
    Route::post('/venta/{store}/listings/refresh-status', [VentaListingController::class, 'refreshStatus'])->name('ext.venta.listings.refresh_status');
    Route::post('/venta/{store}/listings/bulk-toggle', [VentaListingController::class, 'bulkToggle'])->name('ext.venta.listings.bulk_toggle');
    Route::put('/venta/{store}/listings/{productId}', [VentaListingController::class, 'update'])->whereNumber('productId')->name('ext.venta.listings.update');
    Route::post('/venta/{store}/listings/{productId}/push', [VentaListingController::class, 'push'])->whereNumber('productId')->name('ext.venta.listings.push');
    Route::post('/venta/{store}/listings/{productId}/toggle', [VentaListingController::class, 'toggle'])->whereNumber('productId')->name('ext.venta.listings.toggle');
});

// ── Venta Orders — READ ─────────────────────────────────────────
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/venta/{store}/orders', [VentaOrderController::class, 'index'])->name('ext.venta.orders.index');

    // Courier fulfilment reads (#168). The waybill sits in the read tier for
    // the same reason Shopee's does: opening a stored PDF is read-only, and
    // the person at the handover shelf reprinting a label is often not the
    // person allowed to book. Serviceability, slots and tracking only ask
    // the storefront questions and change nothing.
    Route::get('/venta/{store}/orders/{order}/awb', [VentaOrderController::class, 'awb'])->whereNumber('order')->name('ext.venta.orders.awb');
    Route::get('/venta/{store}/orders/{order}/tracking', [VentaOrderController::class, 'tracking'])->whereNumber('order')->name('ext.venta.orders.tracking');
    Route::get('/venta/{store}/orders/{order}/serviceability', [VentaOrderController::class, 'serviceability'])->whereNumber('order')->name('ext.venta.orders.serviceability');
    Route::get('/venta/{store}/orders/{order}/pickup-slots', [VentaOrderController::class, 'pickupSlots'])->whereNumber('order')->name('ext.venta.orders.pickup_slots');
    // The store's manual couriers, for "another courier, by hand". A list,
    // so it reads; static segment ahead of the {order} routes above by
    // being a different depth.
    Route::get('/venta/{store}/orders/shipping-couriers', [VentaOrderController::class, 'shippingCouriers'])->name('ext.venta.orders.shipping_couriers');
    Route::get('/venta/{store}/orders/pickup-addresses', [VentaOrderController::class, 'pickupAddresses'])->name('ext.venta.orders.pickup_addresses');
});

// ── Venta Orders — WRITES ───────────────────────────────────────
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/venta/{store}/orders/fetch', [VentaOrderController::class, 'fetch'])->name('ext.venta.orders.fetch');

    // Courier fulfilment writes (#168). Estimate creates nothing but is a
    // POST carrying the booking options, and quoting a fee is the packer's
    // job, so it rides with the writes rather than the reads.
    Route::post('/venta/{store}/orders/{order}/estimate', [VentaOrderController::class, 'estimate'])->whereNumber('order')->name('ext.venta.orders.estimate');
    Route::post('/venta/{store}/orders/{order}/book', [VentaOrderController::class, 'book'])->whereNumber('order')->name('ext.venta.orders.book');
    Route::post('/venta/{store}/orders/{order}/cancel-booking', [VentaOrderController::class, 'cancelBooking'])->whereNumber('order')->name('ext.venta.orders.cancel_booking');
    Route::post('/venta/{store}/orders/{order}/book-manual', [VentaOrderController::class, 'bookManual'])->whereNumber('order')->name('ext.venta.orders.book_manual');
    Route::post('/venta/{store}/orders/{order}/clear-manual', [VentaOrderController::class, 'clearManual'])->whereNumber('order')->name('ext.venta.orders.clear_manual');
    Route::delete('/venta/{store}/orders/{order}', [VentaOrderController::class, 'destroy'])->name('ext.venta.orders.destroy');
    Route::post('/venta/{store}/orders/bulk-delete', [VentaOrderController::class, 'bulkDelete'])->name('ext.venta.orders.bulk_delete');
});

// Venta Reviews (push marketplace reviews to Venta stores) — write tier
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::post('/venta/reviews/{id}/push', [VentaReviewController::class, 'push'])->name('ext.venta.reviews.push')->whereNumber('id');
    Route::post('/venta/reviews/push-all', [VentaReviewController::class, 'pushAll'])->name('ext.venta.reviews.push_all');
});

// ── THE CHANNEL'S OWN LANDING PAGE ──────────────────────────────────────
//
// /channels/venta was core's /integrations/{channel} wildcard until the
// permissions rebuild exposed what that cost: a page rendered by a core
// controller derives a core key, so the one page every Venta operator lands
// on had no entry in the permission editor and stayed gated by a legacy
// key nobody could grant. The route lives here now, so the page derives
// venta/dashboard and is enforced at the door like everything else.
//
// The digits-only {store} tail is what keeps this from swallowing
// /channels/venta/orders - same rule the old wildcard relied on. On a
// single-store channel a store suffix resolves to no card, which is a 404.
Route::middleware(['auth'])->prefix('channels')->group(function () {
    Route::get('/venta/{store?}', [\Extensions\venta\Controllers\VentaDashboardController::class, 'show'])
        ->where('store', '[0-9]+')
        // Refused BY ABSENCE: a channel you may not see must answer exactly
        // like one that does not exist. See EnsureControllerPermission.
        ->defaults('permission_denial', '404')
        ->name('ext.venta.dashboard');
});
