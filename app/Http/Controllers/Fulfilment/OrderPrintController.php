<?php

namespace App\Http\Controllers\Fulfilment;

use App\Http\Controllers\Controller;
use App\Support\Fulfilment\OrderPrintLists;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class OrderPrintController extends Controller
{
    private const PERMISSIONS = [
        'shopee' => 'shopee',
        'lazada' => 'lazada',
        'tiktok' => 'tiktok',
        'ventacart' => 'ventacart',
        'woocommerce' => 'woocommerce',
    ];

    public function pick(Request $request, string $channel)
    {
        [$orders, $ids] = $this->resolve($request, $channel);

        return view('fulfilment.pick_list', [
            'channelLabel' => OrderPrintLists::label($channel),
            'lines' => OrderPrintLists::picking($channel, $orders),
            'orderRefs' => $ids,
            'printedAt' => now()->format('Y-m-d H:i'),
        ]);
    }

    public function packing(Request $request, string $channel)
    {
        [$orders] = $this->resolve($request, $channel);

        return view('fulfilment.packing_list', [
            'channelLabel' => OrderPrintLists::label($channel),
            'orders' => OrderPrintLists::packing($channel, $orders),
            'printedAt' => now()->format('Y-m-d H:i'),
        ]);
    }

    private function resolve(Request $request, string $channel): array
    {
        abort_unless(OrderPrintLists::supports($channel), 404);

        $slug = self::PERMISSIONS[$channel];
        $user = $request->user();

        abort_unless(
            $user?->hasPermission('view_'.$slug.'/order'),
            403
        );

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $orders = OrderPrintLists::orders($channel, $data['ids']);

        if ($orders->isEmpty()) {
            throw ValidationException::withMessages([
                'ids' => 'None of those orders are still here. Reload the list and pick again.',
            ]);
        }

        return [$orders, $orders->pluck('id')->map(fn ($id) => (string) $id)->all()];
    }

}
