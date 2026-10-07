<?php

namespace App\Http\Controllers;

use App\Integrations\IntegrationRegistry;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request, IntegrationRegistry $registry)
    {
        $response = $registry->resolveRootRouteResponse($request);
        if ($response !== null) {
            return $response;
        }

        return redirect()->route('dashboard');
    }
}
