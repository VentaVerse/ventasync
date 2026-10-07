<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\Refused;
use App\Support\Api\Input;
use App\Services\Sales\CustomerLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(fn () => app(\App\Actions\Sales\CustomerReads::class)->index(Input::from($request)));
    }

    public function history(Request $request): JsonResponse
    {
        return $this->run(fn () => app(\App\Actions\Sales\CustomerReads::class)->history(Input::from($request)));
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
