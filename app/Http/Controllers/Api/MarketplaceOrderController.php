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
        if ($denied = $this->denyIfNoPermission($request, $platform)) {
            return $denied;
        }

        $provider = app(IntegrationRegistry::class)->mobileProviderFor($platform);
        if ($provider === null) {
            return response()->json(['error' => 'Invalid platform'], 404);
        }

        return response()->json($provider->mobileIndexResponse($request));
    }

    public function show(Request $request, string $platform, int $id)
    {
        if ($denied = $this->denyIfNoPermission($request, $platform)) {
            return $denied;
        }

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

    /**
     * Per-marketplace permission gate: a user with manage_{platform}/order -
     * the same derived key that opens that platform's orders screen in the
     * web interface - can access its orders here. Returns null on pass, a
     * 403 JsonResponse on fail.
     */
    private function denyIfNoPermission(Request $request, string $platform): ?\Illuminate\Http\JsonResponse
    {
        $key = 'manage_' . $platform . '/order';
        if ($request->user()->hasPermission($key)) {
            return null;
        }

        return response()->json([
            'error'   => 'permission_denied',
            'message' => "You don't have permission to access {$platform} orders.",
        ], 403);
    }
}
