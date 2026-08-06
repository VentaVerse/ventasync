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
     */
    public function search(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['orders' => [], 'products' => []]);
        }

        $pfx = (string) config('catalog.prefix');
        $like = '%'.$q.'%';

        // Numeric id match and name match are ORed together inside a single
        // group. A bare `when(ctype_digit)->orWhere(...)` followed by a
        // separate `->where(closure)` compiles to an AND between the two
        // (the id match becomes a required condition, not an alternative),
        // which silently drops the very id lookups this endpoint exists
        // for. Nesting both inside one closure keeps the OR intact without
        // ever letting an unparenthesised orWhere leak past the name filter.
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

        $products = DB::table($pfx.'product as p')
            ->join($pfx.'product_description as pd', 'pd.product_id', '=', 'p.product_id')
            ->where('pd.language_id', config('catalog.default_language_id'))
            ->where(fn ($b) => $b->where('pd.name', 'like', $like)->orWhere('p.sku', 'like', $like))
            ->select('p.product_id as id', 'pd.name', 'p.sku')
            ->limit(5)
            ->get();

        // Each record carries its own destination URL, built the same way
        // SearchController already does for its results, so the palette's
        // JS never has to hand-assemble a path from an id. Both target
        // routes take a required numeric {id}, which is always supplied
        // here, so route() cannot throw the way it would for a fixed nav
        // destination with no model context.
        $orders->each(fn ($o) => $o->url = route('orders.show', $o->id));
        $products->each(fn ($p) => $p->url = route('products.edit', $p->id));

        return response()->json(['orders' => $orders, 'products' => $products]);
    }
}
