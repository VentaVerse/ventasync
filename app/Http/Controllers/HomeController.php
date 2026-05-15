<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationRegistry;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    /**
     * Resolve the bare "/" URL. Integrations may claim it for domain-level
     * OAuth callbacks (e.g. Shopee's redirect URI requires the bare
     * APP_URL with no path). If no integration claims the request, fall
     * back to the canonical authenticated landing page.
     */
    public function index(Request $request, IntegrationRegistry $registry)
    {
        $response = $registry->resolveRootRouteResponse($request);
        if ($response !== null) {
            return $response;
        }

        return redirect()->route('dashboard');
    }
}
