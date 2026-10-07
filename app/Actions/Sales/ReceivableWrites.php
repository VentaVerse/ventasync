<?php

namespace App\Actions\Sales;

use App\Services\Sales\CustomerLedger;
use App\Services\Sales\Receivables;
use App\Support\Api\Refused;
use Illuminate\Support\Facades\Validator;

final class ReceivableWrites
{
    public function __construct(private readonly Receivables $receivables)
    {
    }

    public function recordPayment(array $input): array
    {
        $data = Validator::make($input, [
            'order_ids' => ['nullable', 'array', 'max:100'],
            'order_ids.*' => ['integer', 'min:1', 'distinct'],
            'customer' => ['nullable', 'array', 'max:' . CustomerLedger::MAX_KEYS],
            'customer.*' => ['string', 'max:255', 'regex:/^(email|phone|name):.+$/'],
            'amount' => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'method' => ['required', 'string', 'max:64'],
            'date' => ['nullable', 'date_format:Y-m-d'],
            'reference' => ['nullable', 'string', 'max:128'],
            'notes' => ['nullable', 'string', 'max:1000'],
            'reason' => ['required', 'string', 'min:3', 'max:500'],
            'preview' => ['nullable', 'boolean'],
        ])->validate();

        $orderIds = array_map('intval', $data['order_ids'] ?? []);
        $customer = $data['customer'] ?? [];
        if ($orderIds === [] && $customer === []) {
            throw new Refused('Name the orders (order_ids) or the customer (customer keys from customers_search) the payment is for. Nothing was recorded.');
        }

        $method = collect(Receivables::METHODS)->first(fn ($m) => strcasecmp($m, trim($data['method'])) === 0);
        if ($method === null) {
            throw new Refused('The method is one of: ' . implode(', ', Receivables::METHODS) . '. Nothing was recorded.');
        }

        $date = $data['date'] ?? now()->toDateString();
        if ($date > now()->toDateString()) {
            throw new Refused('A payment is recorded once it is made; that date is in the future. Nothing was recorded.');
        }

        $rows = $this->receivables->rows($customer, $orderIds);

        if ($orderIds !== []) {
            $missing = array_values(array_diff($orderIds, $rows->pluck('order_id')->all()));
            if ($missing !== []) {
                throw new Refused('Not in receivables: order #' . implode(', #', $missing) . '. An order is added to receivables on its page first. Nothing was recorded.', 404);
            }
        }

        $unpaid = $rows->reject(fn ($r) => $r['is_paid'] || $r['balance'] <= 0)->values();
        if ($unpaid->isEmpty()) {
            throw new Refused(($orderIds !== [] ? 'Nothing is owed on that order.' : 'That customer owes nothing in receivables.')
                . ' Nothing was recorded.');
        }

        $amount = round((float) $data['amount'], 2);
        $owed = round((float) $unpaid->sum('balance'), 2);
        if ($amount > $owed + 0.005) {
            throw new Refused('Only ' . number_format($owed, 2) . ' is owed on ' . ($unpaid->count() === 1 ? 'order #' . $unpaid[0]['order_id'] : $unpaid->count() . ' orders')
                . ', less than ' . number_format($amount, 2) . '. There is no customer credit to hold the rest. Nothing was recorded.');
        }

        $before = $this->summary($unpaid);

        if ((bool) ($data['preview'] ?? false)) {
            return [
                'preview' => true,
                'amount' => $amount,
                'split' => $this->receivables->split($unpaid, $amount),
                'owed_before' => $before['owed'],
                'owed_after' => round($before['owed'] - $amount, 2),
                'note' => 'Nothing was recorded. Show the user this split, then call again without preview to record it.',
            ];
        }

        try {
            $split = $this->receivables->record($unpaid->pluck('order_id')->all(), $amount, $method, $date,
                $data['reference'] ?? null, $data['notes'] ?? null, trim($data['reason']));
        } catch (\DomainException $e) {
            throw new Refused($e->getMessage());
        }

        $after = $this->summary($this->receivables->rows([], $unpaid->pluck('order_id')->all()));

        return [
            'preview' => false,
            'amount' => $amount,
            'method' => $method,
            'date' => $date,
            'split' => $split,
            'before' => $before,
            'after' => $after,
        ];
    }

    private function summary(\Illuminate\Support\Collection $rows): array
    {
        return [
            'owed' => round((float) $rows->reject(fn ($r) => $r['is_paid'])->sum('balance'), 2),
            'unpaid_orders' => $rows->reject(fn ($r) => $r['is_paid'])->count(),
        ];
    }
}
