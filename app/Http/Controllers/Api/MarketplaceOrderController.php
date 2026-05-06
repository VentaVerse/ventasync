<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationRegistry;
use Illuminate\Http\Request;

/**
 * Thin router for the operator mobile app's marketplace-orders endpoints.
 * Dispatches to whichever extension is registered for the requested
 * platform slug — each marketplace integration owns its own status maps,
 * search columns, and JSON shape inside its IntegrationProvider class.
 *
 * Routes
 *   GET /api/marketplace/{platform}/orders        → mobileIndexResponse
 *   GET /api/marketplace/{platform}/orders/{id}   → mobileShowResponse
 */
class MarketplaceOrderController extends Controller
{
    public function index(Request $request, string $platform)
    {
        $provider = app(IntegrationRegistry::class)->mobileProviderFor($platform);
        if ($provider === null) {
            return response()->json(['error' => 'Invalid platform'], 404);
        }

        return response()->json($provider->mobileIndexResponse($request));
    }

    public function show(Request $request, string $platform, int $id)
    {
        $provider = app(IntegrationRegistry::class)->mobileProviderFor($platform);
        if ($provider === null) {
            return response()->json(['error' => 'Invalid platform'], 404);
        }

        $payload = $provider->mobileShowResponse($id);
        if ($payload === null) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json($payload);
    }
}
