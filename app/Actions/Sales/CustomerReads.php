<?php

namespace App\Actions\Sales;

use App\Services\Sales\CustomerLedger;
use App\Support\Api\Input;
use App\Support\Api\Refused;

final class CustomerReads
{
    public function __construct(private readonly CustomerLedger $ledger)
    {
    }

    public function index(Input $request): array
    {
        $data = $request->validate([
            'search' => ['required', 'string', 'min:2', 'max:255'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        return $this->json([
            'data' => $this->ledger->search($data['search'], (int) ($data['limit'] ?? 25)),
        ]);
    }

    public function history(Input $request): array
    {
        $data = $request->validate([
            'key' => ['required', 'array', 'min:1', 'max:' . CustomerLedger::MAX_KEYS],
            'key.*' => ['required', 'string', 'max:255', 'regex:/^(email|phone|name):.+$/'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ]);

        return $this->json([
            'data' => $this->ledger->history($data['key'], $data['date_from'] ?? null, $data['date_to'] ?? null),
        ]);
    }

    private function json(mixed $data, int $status = 200): array
    {
        if ($status >= 400) {
            $data = (array) json_decode((string) json_encode($data), true);

            throw new Refused((string) ($data['message'] ?? 'The request was refused.'), $status);
        }

        return is_array($data) ? $data : (array) json_decode((string) json_encode($data), true);
    }
}
