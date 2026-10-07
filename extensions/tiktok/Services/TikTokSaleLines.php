<?php

namespace Extensions\tiktok\Services;

use App\Services\Orders\SaleLines\AbstractSaleLines;

final class TikTokSaleLines extends AbstractSaleLines
{
    protected function isComplete(): bool
    {
        return $this->has('original_total_product_price') || $this->has('total_amount');
    }

    protected function subtotalTitle(): string
    {
        return 'Merchandise Subtotal';
    }

    protected function subtotal(float $itemsSubtotal): float
    {
        foreach (['sub_total', 'original_total_product_price'] as $field) {
            if ($this->amount($field) > 0) {
                return $this->amount($field);
            }
        }

        return $itemsSubtotal;
    }

    protected function definitions(): array
    {
        return [
            self::SHIPPING => ['title' => 'Shipping Fee', 'field' => 'original_shipping_fee', 'sign' => 1, 'standard' => true],
            self::SHIPPING_DISCOUNT_PLATFORM => ['title' => 'Shipping discount by TikTok', 'field' => 'shipping_fee_platform_discount', 'sign' => -1],
            self::SHIPPING_DISCOUNT_SELLER => ['title' => 'Shipping discount by seller', 'field' => 'shipping_fee_seller_discount', 'sign' => -1],
            self::SHIPPING_DISCOUNT_COFUNDED => ['title' => 'Shipping discount, co-funded', 'field' => 'shipping_fee_cofunded_discount', 'sign' => -1],
            self::PLATFORM_DISCOUNT => ['title' => 'Platform discount', 'sign' => -1, 'standard' => true],
            self::SELLER_DISCOUNT => ['title' => 'Seller discount', 'sign' => -1, 'standard' => true],
            self::PAYMENT_DISCOUNT => ['title' => 'Payment discount', 'field' => 'payment_platform_discount', 'sign' => -1],
            self::HANDLING_FEE => ['title' => 'Handling fee', 'field' => 'handling_fee', 'sign' => 1],
            self::ITEM_INSURANCE => ['title' => 'Item insurance', 'field' => 'item_insurance_fee', 'sign' => 1],
            self::TAX => ['title' => 'Tax', 'field' => 'tax', 'sign' => 1],
        ];
    }

    protected function value(string $code, array $definition): float
    {
        if ($code !== self::PLATFORM_DISCOUNT && $code !== self::SELLER_DISCOUNT) {
            return parent::value($code, $definition);
        }

        $markdown = $this->has('sub_total') && $this->has('original_total_product_price')
            ? max(0.0, $this->amount('original_total_product_price') - $this->amount('sub_total'))
            : 0.0;
        $seller = $this->amount('seller_discount');
        if ($code === self::SELLER_DISCOUNT) {
            return round(max(0.0, $seller - $markdown), 2);
        }

        return round(max(0.0, $this->amount('platform_discount') - max(0.0, $markdown - $seller)), 2);
    }

    protected function total(float $sumOfLines): float
    {
        return $this->has('total_amount') ? $this->amount('total_amount') : $sumOfLines;
    }
}
