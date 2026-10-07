<?php

namespace Extensions\ventacart\Services\VentaCart;

use App\Services\Orders\SaleLines\AbstractSaleLines;

final class VentaCartSaleLines extends AbstractSaleLines
{
    private const CODE_PREFIX = 'ventacart_';

    protected function isComplete(): bool
    {
        return $this->has('subtotal') || $this->has('total');
    }

    protected function subtotalTitle(): string
    {
        return 'Subtotal';
    }

    protected function subtotal(float $itemsSubtotal): float
    {
        return $this->has('subtotal') ? $this->amount('subtotal') : $itemsSubtotal;
    }

    protected function definitions(): array
    {
        return [
            self::SHIPPING => ['title' => 'Shipping', 'field' => 'shipping', 'sign' => 1, 'standard' => true],
        ];
    }

    protected function extraLines(): array
    {
        $lines = [];
        foreach (is_array($this->data['lines'] ?? null) ? $this->data['lines'] : [] as $line) {
            if (! is_array($line)) {
                continue;
            }
            $amount = round((float) str_replace(',', '', (string) ($line['amount'] ?? 0)), 2);
            if ($amount == 0.0) {
                continue;
            }
            $code = preg_replace('/[^a-z0-9_]+/', '_', strtolower((string) ($line['code'] ?? 'line')));
            $lines[] = [
                'code' => substr(self::CODE_PREFIX . $code, 0, 32),
                'title' => (string) ($line['label'] ?? $line['code'] ?? 'Adjustment'),
                'value' => $amount,
            ];
        }

        return $lines;
    }

    protected function total(float $sumOfLines): float
    {
        return $this->has('total') ? $this->amount('total') : $sumOfLines;
    }
}
