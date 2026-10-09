<?php

namespace App\Http\Controllers;

use App\Channels\ChannelRegistry;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    public function index(Request $request, ChannelRegistry $registry)
    {
        $response = $registry->resolveRootRouteResponse($request);
        if ($response !== null) {
            return $response;
        }

        return redirect()->route('dashboard');
    }
}
