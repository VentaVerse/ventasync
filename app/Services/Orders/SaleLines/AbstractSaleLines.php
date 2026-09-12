<?php

namespace App\Services\Orders\SaleLines;

/**
 * An order's sale, line by line, for the order page's Sale section.
 *
 * A channel declares its lines: the field that feeds each, its title, its sign,
 * and whether it is standard (always listed, at 0.00 when the channel reports
 * nothing for it) or incidental (listed only when non-zero). This class builds
 * the result, so a new channel gets the always-listed behaviour by declaring
 * its lines and nothing else.
 *
 * Values are in the order's currency. The subtotal comes first and the total
 * last. When the channel's full breakdown is not known yet, only the items,
 * the shipping and their sum are listed.
 */
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

    /**
     * @param  array<string, mixed>  $data  the channel's own figures for the order
     * @param  float  $itemsSubtotal  the order's items, used when the channel gives no subtotal
     * @param  float  $shipping  the order's shipping, used before the full breakdown is known
     * @return list<array{code: string, title: string, value: float}>
     */
    final public static function lines(array $data, float $itemsSubtotal, float $shipping): array
    {
        return (new static($data))->build($itemsSubtotal, $shipping);
    }

    /** @param  array<string, mixed>  $data */
    final protected function __construct(protected readonly array $data)
    {
    }

    /** Whether the channel's full breakdown is known for this order. */
    abstract protected function isComplete(): bool;

    abstract protected function subtotalTitle(): string;

    abstract protected function subtotal(float $itemsSubtotal): float;

    /**
     * The lines between the subtotal and the total, in display order.
     *
     * @return array<string, array{title: string, field?: string|list<string>, sign?: int, standard?: bool}>
     */
    abstract protected function definitions(): array;

    protected function totalTitle(): string
    {
        return 'Total';
    }

    /** The total line: by default the lines added up. */
    protected function total(float $sumOfLines): float
    {
        return $sumOfLines;
    }

    /**
     * The magnitude a declared line carries: the first of its fields that is
     * non-zero. A channel overrides this for a line it has to work out.
     *
     * @param  array{title: string, field?: string|list<string>, sign?: int, standard?: bool}  $definition
     */
    protected function value(string $code, array $definition): float
    {
        foreach ((array) ($definition['field'] ?? []) as $field) {
            if ($this->amount($field) > 0) {
                return $this->amount($field);
            }
        }

        return 0.0;
    }

    /**
     * Lines the channel reports as a list of its own, signed, placed after the
     * declared ones.
     *
     * @return list<array{code: string, title: string, value: float}>
     */
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

    /** @return list<array{code: string, title: string, value: float}> */
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
