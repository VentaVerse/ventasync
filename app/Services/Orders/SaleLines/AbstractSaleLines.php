<?php

namespace App\Services\Orders\SaleLines;

abstract class AbstractSaleLines
{
    public const SUB_TOTAL = 'sub_total';
    public const SHIPPING = 'shipping';
    public const PLATFORM_VOUCHER = 'platform_voucher';
    public const SELLER_VOUCHER = 'seller_voucher';
    public const PLATFORM_DISCOUNT = 'platform_discount';
    public const SELLER_DISCOUNT = 'seller_discount';
    public const COINS = 'coins';
    public const PRODUCT_PROTECTION = 'product_protection';
    public const BUYER_TRANSACTION_FEE = 'buyer_transaction_fee';
    public const SHIPPING_DISCOUNT_PLATFORM = 'shipping_discount_platform';
    public const SHIPPING_DISCOUNT_SELLER = 'shipping_discount_seller';
    public const SHIPPING_DISCOUNT_COFUNDED = 'shipping_discount_cofunded';
    public const PAYMENT_DISCOUNT = 'payment_discount';
    public const HANDLING_FEE = 'handling_fee';
    public const ITEM_INSURANCE = 'item_insurance';
    public const TAX = 'tax';
    public const TOTAL = 'total';

    final public static function lines(array $data, float $itemsSubtotal, float $shipping): array
    {
        return (new static($data))->build($itemsSubtotal, $shipping);
    }

    final protected function __construct(protected readonly array $data)
    {
    }

    abstract protected function isComplete(): bool;

    abstract protected function subtotalTitle(): string;

    abstract protected function subtotal(float $itemsSubtotal): float;

    abstract protected function definitions(): array;

    protected function totalTitle(): string
    {
        return 'Total';
    }

    protected function total(float $sumOfLines): float
    {
        return $sumOfLines;
    }

    protected function value(string $code, array $definition): float
    {
        foreach ((array) ($definition['field'] ?? []) as $field) {
            if ($this->amount($field) > 0) {
                return $this->amount($field);
            }
        }

        return 0.0;
    }

    protected function extraLines(): array
    {
        return [];
    }

    protected function amount(string $field): float
    {
        return abs((float) str_replace(',', '', (string) ($this->data[$field] ?? 0)));
    }

    protected function has(string $field): bool
    {
        return isset($this->data[$field]);
    }

    private function build(float $itemsSubtotal, float $shipping): array
    {
        if (! $this->isComplete()) {
            $lines = [['code' => self::SUB_TOTAL, 'title' => 'Items', 'value' => $itemsSubtotal]];
            if ($shipping != 0.0) {
                $lines[] = ['code' => self::SHIPPING, 'title' => 'Shipping', 'value' => $shipping];
            }
            $lines[] = ['code' => self::TOTAL, 'title' => 'Total', 'value' => $itemsSubtotal + $shipping];

            return $lines;
        }

        $lines = [['code' => self::SUB_TOTAL, 'title' => $this->subtotalTitle(), 'value' => $this->subtotal($itemsSubtotal)]];

        foreach ($this->definitions() as $code => $definition) {
            $magnitude = $this->value($code, $definition);
            if ($magnitude > 0) {
                $lines[] = ['code' => $code, 'title' => $definition['title'], 'value' => ($definition['sign'] ?? 1) * $magnitude];
            } elseif (! empty($definition['standard'])) {
                $lines[] = ['code' => $code, 'title' => $definition['title'], 'value' => 0.0];
            }
        }

        foreach ($this->extraLines() as $line) {
            $lines[] = $line;
        }

        $lines[] = ['code' => self::TOTAL, 'title' => $this->totalTitle(), 'value' => $this->total(array_sum(array_column($lines, 'value')))];

        return $lines;
    }
}
