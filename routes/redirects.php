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
