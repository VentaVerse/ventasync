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
     * A fulfilment button for one order: pack, ship, print the waybill and
     * the rest, each answered by the marketplace that owns the order, the
     * same way its web orders page answers it. A change needs the manage
     * tier; a read, the view tier.
     */
    public function fulfil(Request $request, string $platform, int $id, string $action)
    {
        $provider = app(IntegrationRegistry::class)->mobileProviderFor($platform);
        if (! $provider instanceof \App\Integrations\Contracts\MobileFulfilmentProvider) {
            return response()->json(['error' => 'Invalid platform'], 404);
        }

        $actions = $provider->mobileFulfilmentActions();
        if (! array_key_exists($action, $actions)) {
            return response()->json(['error' => 'Unknown action'], 404);
        }

        $key = ($actions[$action] ? 'manage_' : 'view_') . $platform . '/order';
        if (! $request->user()->hasPermission($key)) {
            return response()->json([
                'error'   => 'permission_denied',
                'message' => "You don't have permission to do this with {$platform} orders.",
            ], 403);
        }

        return $provider->mobileFulfilmentAction($action, $id, $request);
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
