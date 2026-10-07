<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\Refused;
use App\Support\Api\Input;
use App\Models\Catalog\Product;
use App\Support\VariationRows;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class InventoryController extends Controller
{
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

    private function run(\Closure $rule, int $status = 200): JsonResponse
    {
        try {
            return response()->json($rule(), $status);
        } catch (Refused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }
}
