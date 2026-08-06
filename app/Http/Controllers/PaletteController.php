<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaletteController extends Controller
{
    /**
     * Record lookups for the command palette. Route entries are rendered
     * server side into the palette itself and are not searched here.
     *
     * Each result set is gated independently on the same permission its
     * destination route requires, so a user permitted to see orders but
     * not products (or vice versa) still gets the set they can see, empty
     * arrays for the rest. Never auth()->user()->can(): this app registers
     * no Gates or Policies, so can() always returns false, including for a
     * full admin, and a filter built on it would silently hide everything.
     * User::hasPermission() is the only real check, same as the `perm:`
     * route middleware and Navigation::allows() use.
     */
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['orders' => [], 'products' => []]);
        }

        $pfx = (string) config('catalog.prefix');
        $like = '%'.$q.'%';

        // Pipe-delimited any-match, same parsing as EnsurePermission::handle.
        $hasAny = function (string $keys) use ($user): bool {
            if (!$user) {
                return false;
            }
            foreach (explode('|', $keys) as $key) {
                $key = trim($key);
                if ($key !== '' && $user->hasPermission($key)) {
                    return true;
                }
            }
            return false;
        };

        // Matches the orders.show read-tier gate in routes/web.php exactly.
        $canViewOrders = $hasAny('view_orders|manage_orders');

        // Product results link to products.edit, and there is no read-only
        // products.show route to send a view_products-only user to instead
        // (see products.index / products.edit split in routes/web.php), so
        // this must match products.edit's own gate (manage_products) rather
        // than the broader view_products|manage_products tier the catalog
        // list page accepts. A view-only user simply never sees products in
        // the palette, rather than seeing a result that bounces them.
        $canViewProducts = $hasAny('manage_products');

        $orders = collect();
        if ($canViewOrders) {
            // Numeric id match and name match are ORed together inside a
            // single group. A bare `when(ctype_digit)->orWhere(...)`
            // followed by a separate `->where(closure)` compiles to an AND
            // between the two (the id match becomes a required condition,
            // not an alternative), which silently drops the very id
            // lookups this endpoint exists for. Nesting both inside one
            // closure keeps the OR intact without ever letting an
            // unparenthesised orWhere leak past the name filter.
            $orders = DB::table($pfx.'order')
                ->select('order_id as id', 'firstname', 'lastname', 'total', 'currency_code')
                ->where(function ($b) use ($q, $like) {
                    if (ctype_digit($q)) {
                        $b->orWhere('order_id', (int) $q);
                    }
                    $b->orWhere(fn ($b2) => $b2->where('firstname', 'like', $like)->orWhere('lastname', 'like', $like));
                })
                ->orderByDesc('order_id')
                ->limit(5)
                ->get();

            // Each record carries its own destination URL, built the same
            // way SearchController already does for its results, so the
            // palette's JS never has to hand-assemble a path from an id.
            // orders.show takes a required numeric {id}, which is always
            // supplied here, so route() cannot throw the way it would for
            // a fixed nav destination with no model context.
            $orders->each(fn ($o) => $o->url = route('orders.show', $o->id));
        }

        $products = collect();
        if ($canViewProducts) {
            $products = DB::table($pfx.'product as p')
                ->join($pfx.'product_description as pd', 'pd.product_id', '=', 'p.product_id')
                ->where('pd.language_id', config('catalog.default_language_id'))
                ->where(fn ($b) => $b->where('pd.name', 'like', $like)->orWhere('p.sku', 'like', $like))
                ->select('p.product_id as id', 'pd.name', 'p.sku')
                ->limit(5)
                ->get();

            $products->each(fn ($p) => $p->url = route('products.edit', $p->id));
        }

        return response()->json(['orders' => $orders, 'products' => $products]);
    }
}
