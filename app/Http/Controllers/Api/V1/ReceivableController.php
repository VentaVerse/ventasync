<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Support\Api\Input;
use App\Support\Api\Refused;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Accounts receivable: reading on orders:read, recording a customer's
 * payment on orders:write. The app door runs the same two under the
 * permissions the Accounts receivable page needs.
 */
class ReceivableController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        return $this->run(fn () => app(\App\Actions\Sales\ReceivableReads::class)->index(Input::from($request)));
    }

    public function recordPayment(Request $request): JsonResponse
    {
        try {
            $result = app(\App\Actions\Sales\ReceivableWrites::class)->recordPayment($request->all());
        } catch (Refused $e) {
            return response()->json(['message' => $e->getMessage()], $e->status);
        }

        return response()->json($result, $result['preview'] ? 200 : 201);
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
