<?php

namespace App\Services\Sales;

use App\Models\Invoice;
use App\Models\InvoiceSettings;
use Illuminate\Support\Facades\DB;

class InvoiceNumbers
{
    public function preview(): string
    {
        $prefix = (string) InvoiceSettings::current()->prefix;

        return Invoice::format($prefix, $this->nextFor($prefix));
    }

    public function issue(int $orderId, ?int $userId): Invoice
    {
        InvoiceSettings::current();

        return DB::transaction(function () use ($orderId, $userId) {
            $settings = InvoiceSettings::query()->lockForUpdate()->first();

            $existing = Invoice::where('order_id', $orderId)->first();
            if ($existing) {
                return $existing;
            }

            $prefix = (string) $settings->prefix;

            return Invoice::create([
                'order_id' => $orderId,
                'prefix' => $prefix,
                'number' => $this->nextFor($prefix),
                'issued_at' => now(),
                'issued_by' => $userId,
            ]);
        });
    }

    public function lastIssued(): ?Invoice
    {
        $prefix = (string) InvoiceSettings::current()->prefix;

        return Invoice::where('prefix', $prefix)->orderByDesc('number')->first();
    }

    private function nextFor(string $prefix): int
    {
        return (int) Invoice::where('prefix', $prefix)->max('number') + 1;
    }
}
