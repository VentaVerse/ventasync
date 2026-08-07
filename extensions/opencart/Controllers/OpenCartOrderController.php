<?php

namespace Extensions\opencart\Controllers;

use App\Http\Controllers\Controller;
use Extensions\opencart\Services\OpenCartOrdersPanel;
use Illuminate\Http\Request;

/**
 * Per-store order list for an OpenCart-connected storefront.
 *
 * This used to live in core App\Http\Controllers\OrderController as
 * "opencartIndex()", moved here as part of the decoupling work so core has
 * no awareness of OpenCart-specific routes or views.
 */
class OpenCartOrderController extends Controller
{
    /**
     * The storefront's own orders page. Everything the list is made of now
     * lives in OpenCartOrdersPanel, which the fulfilment hub calls with the
     * same request to render the same partial.
     *
     * No 404 for an unknown store id here, matching what this page has
     * always done: an id with no orders tagged to it simply lists nothing.
     */
    public function index(Request $request, int $store, OpenCartOrdersPanel $panel)
    {
        return view('ext-opencart::orders.index', $panel->build($request, $store));
    }
}
