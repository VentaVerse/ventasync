<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Integrations\IntegrationRegistry;
use Illuminate\Http\Request;

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
