<?php

namespace App\Http\Controllers\Fulfilment;

use App\Http\Controllers\Controller;
use App\Support\Fulfilment\PackingCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The packing check's two questions: what is in this order (without how
 * many), and does this count match. Gated here on the same permission the
 * channel's booking needs, since this namespace is not gated at the door.
 */
class PackingCheckController extends Controller
{
    public function show(Request $request, string $channel, int $order): JsonResponse
    {
        $this->authorizeFor($request, $channel);

        $found = PackingCheck::order($channel, $order);
        abort_if($found === null || $found['lines'] === [], 404);

        return response()->json([
            'reference' => $found['reference'],
            // Never the quantity: the packer counts what is in their hands.
            'lines' => array_map(fn (array $line) => [
                'key' => $line['key'],
                'name' => $line['name'],
                'variation' => $line['variation'],
                'sku' => $line['sku'],
                'image' => $line['image'],
            ], $found['lines']),
        ]);
    }

    public function verify(Request $request, string $channel, int $order): JsonResponse
    {
        $this->authorizeFor($request, $channel);

        $data = $request->validate([
            'counts' => ['required', 'array', 'max:200'],
            'counts.*' => ['nullable', 'string', 'max:6'],
        ]);

        if (! PackingCheck::verify($request->user(), $channel, $order, $data['counts'])) {
            return response()->json(['ok' => false, 'message' => PackingCheck::WRONG], 422);
        }

        return response()->json(['ok' => true]);
    }

    private function authorizeFor(Request $request, string $channel): void
    {
        abort_unless(PackingCheck::enabled() && PackingCheck::supports($channel), 404);
        abort_unless($request->user()?->hasPermission('manage_' . $channel . '/order'), 403);
    }
}
