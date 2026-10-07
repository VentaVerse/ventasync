<?php

namespace App\Http\Controllers\Api\V1;

use App\Actions\Catalog\ProductWrites;
use App\Http\Controllers\Controller;
use App\Support\Api\Input;
use App\Support\Api\Refused;
use App\Models\Catalog\Product;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProductController extends Controller
{
    public const MAX_VARIATIONS = ProductWrites::MAX_VARIATIONS;


    public function update(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => app(ProductWrites::class)->update($request->all(), $id));
    }

    public function updateVariations(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => app(ProductWrites::class)->updateVariations($request->all(), $id));
    }

    public function updatePrice(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => app(ProductWrites::class)->updatePrice($request->all(), $id));
    }

    public function store(Request $request): JsonResponse
    {
        return $this->run(fn () => app(ProductWrites::class)->store($request->all()), 201);
    }

    private function run(\Closure $rule, int $status = 200): JsonResponse
    {
        try {
            return response()->json($rule(), $status);
        } catch (Refused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }
    }

    public function index(Request $request): JsonResponse
    {
        return $this->run(fn () => app(\App\Actions\Catalog\ProductReads::class)->index(Input::from($request)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => app(\App\Actions\Catalog\ProductReads::class)->show(Input::from($request), $id));
    }
}
