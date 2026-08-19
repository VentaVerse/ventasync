<?php

namespace App\Http\Controllers\Fulfilment;

use App\Http\Controllers\Controller;
use App\Support\Fulfilment\OrderPrintLists;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * Pick and packing lists for a selection of orders.
 *
 * One controller for every channel, because the documents are the same
 * documents - see App\Support\Fulfilment\OrderPrintLists for why the
 * per-channel part is a map rather than a class each.
 *
 * POST rather than GET despite being a read. A selection is a list of ids
 * that routinely runs past what belongs in a URL, and the operator arrives
 * here by submitting the checkboxes they ticked. Nothing is written.
 *
 * Gated on the VIEW tier, not manage. These sheets are rendered from order
 * products the sync already stored, so printing one reveals nothing a
 * read-only operator cannot see on the list itself - the same reasoning the
 * Shopee routes file records for tracking and waybill downloads. Being
 * read-only is also why no marketplace is contacted: forty orders cost forty
 * rows of SQL rather than forty calls against a rate limit.
 */
class OrderPrintController extends Controller
{
    /**
     * The permission pair each channel's lists sit behind, view tier first.
     *
     * @var array<string, string>
     */
    private const PERMISSIONS = [
        'shopee' => 'shopee',
        'lazada' => 'lazada',
        'tiktok' => 'tiktok',
        'venta' => 'venta',
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

    /**
     * @return array{0: \Illuminate\Database\Eloquent\Collection, 1: array<int, string>}
     */
    private function resolve(Request $request, string $channel): array
    {
        abort_unless(OrderPrintLists::supports($channel), 404);

        $slug = self::PERMISSIONS[$channel];
        $user = $request->user();

        abort_unless(
            $user?->hasPermission('view_'.$slug.'_orders') || $user?->hasPermission('manage_'.$slug.'_orders'),
            403
        );

        $data = $request->validate([
            'ids' => ['required', 'array', 'min:1'],
            'ids.*' => ['required', 'integer'],
        ]);

        $orders = OrderPrintLists::orders($channel, $data['ids']);

        // A selection that resolves to nothing is a stale page - the rows
        // were deleted or belong to another store - and printing an empty
        // sheet would say the orders had no items rather than that they are
        // gone.
        if ($orders->isEmpty()) {
            throw ValidationException::withMessages([
                'ids' => 'None of those orders are still here. Reload the list and pick again.',
            ]);
        }

        return [$orders, $orders->pluck('id')->map(fn ($id) => (string) $id)->all()];
    }

}
