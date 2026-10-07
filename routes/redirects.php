<?php

// GET/HEAD only: a 301 on a write request drops the body on redirect-following clients.

use Illuminate\Support\Facades\Route;

$withQuery = function (string $path): string {
    $qs = request()->getQueryString();

    return $qs ? "{$path}?{$qs}" : $path;
};

Route::get('/vendors/{any?}', fn (?string $any = null) => redirect($withQuery('/purchasing/vendors'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');

Route::get('/purchase-orders/{any?}', fn (?string $any = null) => redirect($withQuery('/purchasing/purchase-orders'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');

Route::get('/reorder-suggestions/{any?}', fn (?string $any = null) => redirect($withQuery('/purchasing/reorder-suggestions'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');

Route::get('/api/vendors', fn () => redirect($withQuery('/purchasing/api/vendors'), 301));

Route::get('/api/purchase-orders/products', fn () => redirect($withQuery('/purchasing/api/purchase-orders/products'), 301));

Route::get('/products/{id}/vendors', fn (string $id) => redirect($withQuery('/purchasing/api/products/'.$id.'/vendors'), 301))
    ->whereNumber('id');

Route::get('/api/warehousing/products', fn () => redirect($withQuery('/warehousing/api/products'), 301));

Route::get('/reviews/{any?}', fn (?string $any = null) => redirect($withQuery('/catalog/reviews'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');

// Shims are per family, never a catch-all: OAuth redirect and authorize URIs are compared byte for byte and must not move.

$channelFamily = function (string $channel, string $family) use ($withQuery): void {
    Route::get("/{$channel}/{$family}/{any?}", function (?string $any = null) use ($withQuery, $channel, $family) {
        return redirect($withQuery("/channels/{$channel}/{$family}".($any ? '/'.$any : '')), 301);
    })->where('any', '.*');
};

foreach (['orders', 'products', 'product-groups', 'categories', 'logistics'] as $family) {
    $channelFamily('shopee', $family);
}

Route::get('/lazada', fn () => redirect($withQuery('/channels/lazada/settings'), 301));

foreach (['orders', 'products', 'product-groups', 'categories', 'brands'] as $family) {
    $channelFamily('lazada', $family);
}

Route::get('/tiktok', fn () => redirect($withQuery('/channels/tiktok/settings'), 301));

foreach (['orders', 'product-groups', 'categories'] as $family) {
    $channelFamily('tiktok', $family);
}

Route::get('/pedallion', fn () => redirect($withQuery('/channels/pedallion/settings'), 301));

foreach (['orders', 'products', 'product-groups', 'categories'] as $family) {
    $channelFamily('pedallion', $family);
}

foreach (['ventacart', 'opencart', 'shopify'] as $channel) {
    Route::get("/{$channel}/settings/{store}", fn (string $store) => redirect($withQuery("/channels/{$channel}/{$store}/settings"), 301))
        ->whereNumber('store');

    Route::get("/{$channel}", fn () => redirect($withQuery("/channels/{$channel}/settings"), 301));

    Route::get("/{$channel}/{store}/{any}", fn (string $store, string $any) => redirect($withQuery("/channels/{$channel}/{$store}/{$any}"), 301))
        ->whereNumber('store')
        ->where('any', '.*');
}

Route::get('/integrations/{key}', fn (string $key) => redirect($withQuery('/channels/'.str_replace(':', '/', $key)), 301))
    ->where('key', '[A-Za-z0-9_.-]+:[0-9]+');

Route::get('/channels/{key}', fn (string $key) => redirect($withQuery('/channels/'.str_replace(':', '/', $key)), 301))
    ->where('key', '[A-Za-z0-9_.-]+:[0-9]+');

Route::get('/sales/order-statuses/{any?}', fn (?string $any = null) => redirect($withQuery('/settings/order-statuses'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');

Route::get('integrations/{path?}', function (?string $path = null) use ($withQuery) {
    $path = (string) $path;

    if ($path === 'orders' || str_starts_with($path, 'orders/')) {
        $path = 'fulfilment' . substr($path, strlen('orders'));
    }

    return redirect($withQuery('/channels' . ($path === '' ? '' : '/' . $path)), 301);
})->where('path', '.*')->name('channels.legacy');

Route::get('/channels/venta/{any?}', fn (?string $any = null) => redirect($withQuery('/channels/ventacart'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');
