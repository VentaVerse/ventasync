<?php

namespace App\Actions\Sales;

use App\Services\Sales\CustomerLedger;
use App\Services\Sales\Receivables;
use App\Support\Api\Input;

final class ReceivableReads
{
    public function __construct(private readonly Receivables $receivables)
    {
    }

    public function index(Input $request): array
    {
        $data = $request->validate([
            'state' => ['nullable', 'in:unpaid,paid,all'],
            'customer' => ['nullable', 'array', 'max:' . CustomerLedger::MAX_KEYS],
            'customer.*' => ['string', 'max:255', 'regex:/^(email|phone|name):.+$/'],
            'search' => ['nullable', 'string', 'max:255'],
            'date_from' => ['nullable', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'rows_limit' => ['nullable', 'integer', 'min:0', 'max:500'],
        ]);

        $rows = $this->receivables->rows($data['customer'] ?? [], [], $data['date_from'] ?? null, $data['date_to'] ?? null, $data['search'] ?? null);
        $state = $data['state'] ?? 'unpaid';
        $shown = match ($state) {
            'paid' => $rows->filter(fn ($r) => $r['is_paid']),
            'unpaid' => $rows->reject(fn ($r) => $r['is_paid']),
            default => $rows,
        };
        $limit = array_key_exists('rows_limit', $data) && $data['rows_limit'] !== null ? (int) $data['rows_limit'] : 50;

        return [
            'state' => $state,
            'totals' => $this->receivables->totals($rows),
            'rows' => $shown->take($limit)->values()->all(),
            'rows_total' => $shown->count(),
            'methods' => Receivables::METHODS,
        ];
    }
}
