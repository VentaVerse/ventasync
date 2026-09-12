<?php

namespace Extensions\lazada\Services;

use App\Services\Orders\SaleLines\AbstractSaleLines;

/**
 * A Lazada order's sale as Lazada reports it on the order: price, shipping fee,
 * and the vouchers and shipping discounts split by who funded them. Lazada
 * sends no single amount the buyer paid, so the total is the lines added up.
 */
final class LazadaSaleLines extends AbstractSaleLines
{
    private const COMBINED_VOUCHER = 'voucher';

    protected function isComplete(): bool
    {
        return $this->has('price');
    }

    protected function subtotalTitle(): string
    {
        return 'Price';
    }

    protected function subtotal(float $itemsSubtotal): float
    {
        return $this->amount('price');
    }

    protected function definitions(): array
    {
        return [
            self::SHIPPING => ['title' => 'Shipping Fee', 'field' => ['shipping_fee_original', 'shipping_fee'], 'sign' => 1, 'standard' => true],
            self::SHIPPING_DISCOUNT_PLATFORM => ['title' => 'Shipping discount by Lazada', 'field' => 'shipping_fee_discount_platform', 'sign' => -1],
            self::SHIPPING_DISCOUNT_SELLER => ['title' => 'Shipping discount by seller', 'field' => 'shipping_fee_discount_seller', 'sign' => -1],
            self::PLATFORM_VOUCHER => ['title' => 'Voucher by Lazada', 'field' => 'voucher_platform', 'sign' => -1, 'standard' => true],
            self::SELLER_VOUCHER => ['title' => 'Voucher by seller', 'field' => 'voucher_seller', 'sign' => -1, 'standard' => true],
            self::COMBINED_VOUCHER => ['title' => 'Voucher', 'field' => 'voucher', 'sign' => -1],
        ];
    }

    /** Older orders carry one combined voucher instead of the split; it is listed only for them. */
    protected function value(string $code, array $definition): float
    {
        if ($code === self::COMBINED_VOUCHER && ($this->has('voucher_platform') || $this->has('voucher_seller'))) {
            return 0.0;
        }

        return parent::value($code, $definition);
    }
}
