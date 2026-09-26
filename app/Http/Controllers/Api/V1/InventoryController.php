<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\Refused;
use App\Support\Api\Input;
use App\Models\Catalog\Product;
use App\Support\VariationRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Inventory for external API clients: reading on inventory:read, and one
 * write on inventory:write (#177).
 *
 * The read surfaces stock levels against each product's reorder level so an
 * external tool can flag low stock and reason about restocking (combine with
 * the reports endpoint for sales velocity and cost/price for margin).
 */
class InventoryController extends Controller
{
    /**
     * POST /api/v1/inventory/adjust - one quantity, with a stated reason.
     *
     * A variation is named by its SKU, the same code every list prints and
     * every channel keys on. The product's own quantity can be set only
     * when it has no variations; where it has them it is their sum, and
     * the answer returns the recomputed total so the caller can report it
     * without asking again.
     */
    public function adjust(Request $request): JsonResponse
    {
        try {
            return response()->json(app(\App\Actions\Stock\AdjustStock::class)->handle($request->all()));
        } catch (\App\Support\Api\Refused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    public function index(Request $request): JsonResponse
    {
        return $this->run(fn () => app(\App\Actions\Stock\StockReads::class)->index(Input::from($request)));
    }

    /** A shared rule's answer as JSON, or its refusal with its status. */
    private function run(\Closure $rule, int $status = 200): JsonResponse
    {
        try {
            return response()->json($rule(), $status);
        } catch (Refused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
