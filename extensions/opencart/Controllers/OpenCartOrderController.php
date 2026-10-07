<?php

namespace Extensions\opencart\Controllers;

use App\Http\Controllers\Controller;
use Extensions\opencart\Services\OpenCartOrdersPanel;
use Illuminate\Http\Request;

class OpenCartOrderController extends Controller
{
    public function index(Request $request, int $store, OpenCartOrdersPanel $panel)
    {
        return view('ext-opencart::orders.index', $panel->build($request, $store));
    }
}
