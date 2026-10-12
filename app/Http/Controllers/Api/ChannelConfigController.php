<?php

namespace App\Http\Controllers\Api;

use App\Channels\ChannelRegistry;
use App\Http\Controllers\Controller;
use App\Support\StoreKey;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ChannelConfigController extends Controller
{
    public function index(Request $request, ChannelRegistry $registry): JsonResponse
    {
        return response()->json(array_values($this->channels($request, $registry)));
    }

    public function stores(Request $request, ChannelRegistry $registry, string $channel): JsonResponse
    {
        $found = $this->channels($request, $registry)[$channel] ?? null;

        if ($found === null) {
            return response()->json(['error' => 'Invalid channel'], 404);
        }

        return response()->json($found['stores']);
    }

    private function channels(Request $request, ChannelRegistry $registry): array
    {
        $out = [];

        foreach ($registry->visibleStoreOptions($request->user()) as $option) {
            [$channel, $storeId] = StoreKey::split((string) $option['key']);
            if ($storeId === null) {
                continue;
            }
            $name = (string) $option['channel'];
            $label = (string) $option['label'];

            $out[$channel] ??= [
                'channel'     => $channel,
                'name'        => $name,
                'marketplace' => $registry->mobileProviderFor($channel) !== null,
                'stores'      => [],
            ];
            $out[$channel]['stores'][] = [
                'id'   => $storeId,
                'name' => str_starts_with($label, $name . ': ') ? substr($label, strlen($name) + 2) : $label,
            ];
        }

        return $out;
    }
}
