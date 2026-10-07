<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class PaletteController extends Controller
{
    // Use hasPermission(), never can(): no Gates are registered so can() is always false.
    public function search(Request $request): JsonResponse
    {
        $user = $request->user();

        $q = trim((string) $request->query('q', ''));

        if (mb_strlen($q) < 2) {
            return response()->json(['orders' => [], 'products' => []]);
        }

        $pfx = (string) config('catalog.prefix');
        $like = '%'.$q.'%';

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

        $canViewOrders = $hasAny('view_sales/order');

        $canViewProducts = $hasAny('manage_catalog/product');

        $orders = collect();
        if ($canViewOrders) {
            // Keep id and name matches inside one OR group, or the id lookup becomes a required condition.
            $orders = DB::table($pfx.'order')
                ->select('order_id as id', 'firstname', 'lastname', 'total', 'foreign_total', 'currency_code')
                ->where(function ($b) use ($q, $like) {
                    if (ctype_digit($q)) {
                        $b->orWhere('order_id', (int) $q);
                    }
                    $b->orWhere(fn ($b2) => $b2->where('firstname', 'like', $like)->orWhere('lastname', 'like', $like));
                })
                ->orderByDesc('order_id')
                ->limit(5)
                ->get();

            $orders->each(function ($o) {
                $o->url = route('orders.show', $o->id);
                $o->total_display = \App\Support\Money::dual(
                    (float) $o->total,
                    $o->foreign_total !== null ? (float) $o->foreign_total : null,
                    $o->currency_code
                );
            });
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
