<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Channels\ChannelRegistry;
use Illuminate\Http\Request;

class MarketplaceOrderController extends Controller
{
    public function index(Request $request, string $channel)
    {
        if ($denied = $this->denyIfNoPermission($request, $channel)) {
            return $denied;
        }

        $provider = app(ChannelRegistry::class)->mobileProviderFor($channel);
        if ($provider === null) {
            return response()->json(['error' => 'Invalid channel'], 404);
        }

        return response()->json($provider->mobileIndexResponse($request));
    }

    public function show(Request $request, string $channel, int $id)
    {
        if ($denied = $this->denyIfNoPermission($request, $channel)) {
            return $denied;
        }

        $provider = app(ChannelRegistry::class)->mobileProviderFor($channel);
        if ($provider === null) {
            return response()->json(['error' => 'Invalid channel'], 404);
        }

        $payload = $provider->mobileShowResponse($id);
        if ($payload === null) {
            return response()->json(['error' => 'Order not found'], 404);
        }

        return response()->json($payload);
    }

    public function fulfil(Request $request, string $channel, int $id, string $action)
    {
        $provider = app(ChannelRegistry::class)->mobileProviderFor($channel);
        if (! $provider instanceof \App\Channels\Contracts\MobileFulfillmentProvider) {
            return response()->json(['error' => 'Invalid channel'], 404);
        }

        $actions = $provider->mobileFulfillmentActions();
        if (! array_key_exists($action, $actions)) {
            return response()->json(['error' => 'Unknown action'], 404);
        }

        $key = ($actions[$action] ? 'manage_' : 'view_') . $channel . '/order';
        if (! $request->user()->hasPermission($key)) {
            return response()->json([
                'error'   => 'permission_denied',
                'message' => "You don't have permission to do this with {$channel} orders.",
            ], 403);
        }

        return $provider->mobileFulfillmentAction($action, $id, $request);
    }

    private function denyIfNoPermission(Request $request, string $channel): ?\Illuminate\Http\JsonResponse
    {
        $key = 'manage_' . $channel . '/order';
        if ($request->user()->hasPermission($key)) {
            return null;
        }

        return response()->json([
            'error'   => 'permission_denied',
            'message' => "You don't have permission to access {$channel} orders.",
        ], 403);
    }
}
