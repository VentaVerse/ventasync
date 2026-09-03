<?php

namespace App\Http\Controllers\Integrations;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationRegistry;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * All Listings (Phase D, #184): one grid answering "where does each product
 * stand, everywhere?" - the overview above the per-channel workbenches.
 *
 * Core knows NO channel here. Columns come from whichever enabled channels
 * implement ListingStateSource; each cell renders that channel's own
 * ListingState verbatim and links to that channel's own workbench page. A
 * channel that joins the contract appears in this grid with no core change,
 * which is the whole point of the contract (#97).
 */
class AllListingsController extends Controller
{
    public function index(Request $request, IntegrationRegistry $registry)
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $user = $request->user();

        $q = trim((string) $request->query('q', ''));
        $manufacturerFilter = (string) $request->query('manufacturer', 'all');
        $erpStatus = (string) $request->query('erp_status', 'all');

        $query = DB::table($pfx . 'product as p')
            ->leftJoin($pfx . 'product_description as pd', function ($j) use ($langId) {
                $j->on('p.product_id', '=', 'pd.product_id')
                    ->where('pd.language_id', '=', $langId);
            })
            ->leftJoin($pfx . 'manufacturer as m', 'p.manufacturer_id', '=', 'm.manufacturer_id')
            ->select('p.product_id', 'pd.name', 'p.image', 'p.sku', 'p.model', 'p.quantity', 'p.status', 'm.name as manufacturer_name');

        if ($q !== '') {
            $query->where(function ($sub) use ($q) {
                $sub->where('pd.name', 'like', '%' . $q . '%')
                    ->orWhere('p.model', 'like', '%' . $q . '%')
                    ->orWhere('p.sku', 'like', '%' . $q . '%');
            });
        }
        if ($manufacturerFilter !== 'all') {
            $query->where('p.manufacturer_id', (int) $manufacturerFilter);
        }
        if ($erpStatus === 'enabled') {
            $query->where('p.status', 1);
        } elseif ($erpStatus === 'disabled') {
            $query->where('p.status', 0);
        } else {
            /* The default view (#197): a disabled product NO visible
             * channel lists is hidden - the pushes skip it and every cell
             * would read Not listed. One still listed somewhere stays: it
             * likely needs unlisting, not hiding. listedProductIds() is
             * the contract's answer, so core never names a channel. */
            $listedAnywhere = [];
            foreach ($registry->listingStateSources() as $source) {
                foreach ($source->listedProductIds() as $pid) {
                    $listedAnywhere[$pid] = true;
                }
            }
            $query->where(function ($sub) use ($listedAnywhere) {
                $sub->where('p.status', 1)
                    ->orWhereIn('p.product_id', array_keys($listedAnywhere) ?: [0]);
            });
        }

        $query->orderByDesc('p.product_id');
        $products = $query->paginate(50)->withQueryString();

        foreach ($products as $row) {
            if (isset($row->name)) {
                $row->name = html_entity_decode($row->name, ENT_QUOTES | ENT_HTML5, 'UTF-8');
            }
        }

        $pageIds = $products->pluck('product_id')->map(fn ($v) => (int) $v)->all();

        /* One column per channel that can answer, in registry order. The
         * card's own permission gates the column exactly as it gates the
         * channel on the hub: an operator who cannot see a channel does
         * not see its column. States and links are batch computations,
         * one per channel per page. */
        $columns = [];
        foreach ($registry->listingStateSources() as $source) {
            $card = $source->integrationCards()[0] ?? null;
            if (!$card) {
                continue;
            }
            if ($card->permission && (!$user || !$user->hasPermission($card->permission))) {
                continue;
            }
            $columns[] = [
                'id' => $card->id,
                'name' => $card->name,
                'accent' => $card->accent,
                'states' => $source->listingStates($pageIds),
                'urls' => $source->listingUrls($pageIds),
            ];
        }

        $allManufacturers = DB::table($pfx . 'manufacturer')
            ->orderBy('name')
            ->pluck('name', 'manufacturer_id');

        return view('channels.all-listings', [
            'products' => $products,
            'columns' => $columns,
            'q' => $q,
            'manufacturerFilter' => $manufacturerFilter,
            'erpStatus' => $erpStatus,
            'allManufacturers' => $allManufacturers,
        ]);
    }
}
