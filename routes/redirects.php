<?php

// ─────────────────────────────────────────────────────────────────────────
// TEMPORARY SCAFFOLDING — IA migration (docs/superpowers/plans/2026-08-08-
// ia-migration.md). Every entry here 301-redirects an OLD URI (from before
// a page moved under its navigation section's prefix) to its NEW home.
// Route NAMES never changed, only URIs — these are pure path shims for
// stale bookmarks, links in old emails/tickets, and anything external that
// still points at the pre-migration path.
//
// GET/HEAD only, by design: a 301 on a POST/PUT/DELETE/PATCH silently drops
// the request body on redirect-following clients, which would be worse than
// a clean 404 — old write endpoints simply stop existing with the move. The
// two safety-net sweeps (ViewResolutionSweepTest, RouteNameSweepTest) prove
// nothing in this codebase still targets an old write URI.
//
// Deletable after the next major release once external bookmarks/links have
// had a full cycle to update — do not add new permanent behaviour here.
// ─────────────────────────────────────────────────────────────────────────

use Illuminate\Support\Facades\Route;

// Preserve the tail of a catch-all segment plus the original query string,
// so `/vendors/42/edit?highlight=1` redirects to
// `/purchasing/vendors/42/edit?highlight=1`, not a bare parent path.
$withQuery = function (string $path): string {
    $qs = request()->getQueryString();

    return $qs ? "{$path}?{$qs}" : $path;
};

// ── Purchasing ──────────────────────────────────────────────────────────
Route::get('/vendors/{any?}', fn (?string $any = null) => redirect($withQuery('/purchasing/vendors'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');

Route::get('/purchase-orders/{any?}', fn (?string $any = null) => redirect($withQuery('/purchasing/purchase-orders'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');

Route::get('/reorder-suggestions/{any?}', fn (?string $any = null) => redirect($withQuery('/purchasing/reorder-suggestions'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');

Route::get('/api/vendors', fn () => redirect($withQuery('/purchasing/api/vendors'), 301));

Route::get('/api/purchase-orders/products', fn () => redirect($withQuery('/purchasing/api/purchase-orders/products'), 301));

// The product-vendor AJAX list endpoint — was flat, now nested under
// /purchasing/api. The sibling "manage" page route (/purchasing/products/
// {id}/vendors) never moved, so it needs no redirect.
Route::get('/products/{id}/vendors', fn (string $id) => redirect($withQuery('/purchasing/api/products/'.$id.'/vendors'), 301))
    ->whereNumber('id');

// ── Warehousing ─────────────────────────────────────────────────────────
// Every page URI already carried its /warehousing/ segment before this
// task; only the product-search API endpoint was flat.
Route::get('/api/warehousing/products', fn () => redirect($withQuery('/warehousing/api/products'), 301));

// ── Catalogue: Reviews (OpenCart extension, nav-grouped under Catalogue) ──
Route::get('/reviews/{any?}', fn (?string $any = null) => redirect($withQuery('/catalog/reviews'.($any ? '/'.$any : '')), 301))
    ->where('any', '.*');

// ─────────────────────────────────────────────────────────────────────────
//  Task 2: channel pages under their workspace roots
//
//  Every seller center page moved from /{channel}/… to /integrations/{channel}
//  /…, so the address bar names the workspace the operator is standing in.
//
//  ORDERING NOTE, and the reason these are per-FAMILY rather than one
//  catch-all per channel: this file is required at the end of routes/web.php,
//  which Laravel loads BEFORE any extension provider boots. Every shim here is
//  therefore registered AHEAD of the extension routes it shadows. A blanket
//  `/shopee/{any?}` would sit in front of the live OAuth surfaces below and
//  301 Shopee's own callback into a page it has never heard of. Naming each
//  moved family instead means no shim can ever reach a URI that did not move.
//
//  EXEMPT, and absent from this file on purpose - these URIs did NOT move and
//  must not be redirected. They are registered at the marketplaces' developer
//  consoles and compared byte-for-byte on every consent round trip, so a 301
//  does not rescue them; it simply makes the comparison fail somewhere the
//  operator cannot see:
//    GET /shopee           ext.shopee.index      Shopee redirect URI
//    GET /shopee/authorize ext.shopee.authorize  consent entry point
//    GET /lazada/callback  lazada.callback       Lazada redirect URI
//    GET /lazada/authorize ext.lazada.authorize  consent entry point
//    GET /tiktok/callback  ext.tiktok.callback   TikTok Shop redirect URI
//    GET /tiktok/authorize ext.tiktok.authorize  consent entry point
// ─────────────────────────────────────────────────────────────────────────

// A moved family: /{channel}/{family}/… -> /integrations/{channel}/{family}/…
// with the tail and the query string carried through untouched.
$channelFamily = function (string $channel, string $family) use ($withQuery): void {
    Route::get("/{$channel}/{$family}/{any?}", function (?string $any = null) use ($withQuery, $channel, $family) {
        return redirect($withQuery("/integrations/{$channel}/{$family}".($any ? '/'.$any : '')), 301);
    })->where('any', '.*');
};

// ── Shopee ──────────────────────────────────────────────────────────────
// No shim for the channel root: /shopee is the live OAuth redirect URI.
foreach (['orders', 'products', 'product-groups', 'categories', 'logistics'] as $family) {
    $channelFamily('shopee', $family);
}

// ── Lazada ──────────────────────────────────────────────────────────────
// The settings page gained a segment of its own (/integrations/lazada is the
// workspace Overview), so the bare channel root points at it.
Route::get('/lazada', fn () => redirect($withQuery('/integrations/lazada/settings'), 301));

foreach (['orders', 'products', 'product-groups', 'categories', 'brands'] as $family) {
    $channelFamily('lazada', $family);
}

// ── TikTok ──────────────────────────────────────────────────────────────
Route::get('/tiktok', fn () => redirect($withQuery('/integrations/tiktok/settings'), 301));

foreach (['orders', 'product-groups', 'categories'] as $family) {
    $channelFamily('tiktok', $family);
}

// ── Venta and OpenCart (multi-store, slash grammar) ─────────────────────
// Their pages were already store-scoped as /{channel}/{store}/…, so the store
// segment simply rides along under the new prefix. Only the settings page
// changed shape: the store now precedes what it scopes.
foreach (['venta', 'opencart'] as $channel) {
    Route::get("/{$channel}/settings/{store}", fn (string $store) => redirect($withQuery("/integrations/{$channel}/{$store}/settings"), 301))
        ->whereNumber('store');

    // The storeless fallback that redirects to the first configured store.
    Route::get("/{$channel}", fn () => redirect($withQuery("/integrations/{$channel}/settings"), 301));

    // Everything else under a store: orders, product groups and their tails.
    // whereNumber('store') is what keeps this off /{channel}/settings/{store}
    // above regardless of which is registered first.
    Route::get("/{$channel}/{store}/{any}", fn (string $store, string $any) => redirect($withQuery("/integrations/{$channel}/{$store}/{$any}"), 301))
        ->whereNumber('store')
        ->where('any', '.*');
}

// ── The workspace Overview's own colon form ─────────────────────────────
// The internal key for a store workspace is `venta:2` and stays that way
// (StoreLink ids, OrderTab ids, the hub's channel= parameter). It used to
// reach the URL as well; spec decision 3 replaced that with slash grammar.
// This catches anything still holding the old exposed form.
//
// integrations.workspace's own `channel` pattern excludes ':', so a colon URL
// falls past it to here rather than being answered as an unknown channel.
Route::get('/integrations/{key}', fn (string $key) => redirect($withQuery('/integrations/'.str_replace(':', '/', $key)), 301))
    ->where('key', '[A-Za-z0-9_.-]+:[0-9]+');
