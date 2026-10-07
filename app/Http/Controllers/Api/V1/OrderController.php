<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\Refused;
use App\Support\Api\Input;
use App\Support\Money;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(fn () => app(\App\Actions\Sales\OrderReads::class)->index(Input::from($request)));
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return $this->run(fn () => app(\App\Actions\Sales\OrderReads::class)->show(Input::from($request), $id));
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
