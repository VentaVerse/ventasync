<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationRegistry;
use Illuminate\Http\Request;

class IntegrationController extends Controller
{
    public function index(Request $request, IntegrationRegistry $registry)
    {
        $cards = $registry->visibleCards($request->user());

        return view('integrations.index', compact('cards'));
    }

    public function orders(Request $request, IntegrationRegistry $registry)
    {
        $tabs = $registry->visibleOrderTabs($request->user());

        // No tabs available -> render empty-state page
        if (empty($tabs)) {
            return view('integrations.orders', ['tabs' => [], 'activeTab' => null]);
        }

        // Auto-redirect to the first available marketplace's existing orders
        // page so a click on the sidebar "Orders" link lands somewhere useful.
        // The shared tab strip on each marketplace's orders index handles
        // switching between tabs from there.
        $first = $tabs[0];

        return redirect()->route($first->routeName, $first->routeParams);
    }
}
