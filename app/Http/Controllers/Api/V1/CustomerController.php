<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Services\Sales\CustomerLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Customers as the orders name them, read-only (scope: orders:read). There
 * is no customer list behind this: a customer is the orders that share an
 * email, else a phone, else a name.
 */
class CustomerController extends Controller
{
    public function __construct(private readonly CustomerLedger $ledger)
    {
    }

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return response()->json([
            'data' => $this->ledger->search($data['search'], (int) ($data['limit'] ?? 25)),
        ]);
    }

    public function history(Request $request): JsonResponse
    {
        $data = $request->validate([
            'key' => ['required', 'array', 'min:1', 'max:' . CustomerLedger::MAX_KEYS],
            'key.*' => ['required', 'string', 'max:255', 'regex:/^(email|phone|name):.+$/'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return response()->json([
            'data' => $this->ledger->history($data['key'], $data['date_from'] ?? null, $data['date_to'] ?? null),
        ]);
    }
}
