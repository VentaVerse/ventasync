<?php

namespace Extensions\shopee\Services;

use App\Services\Orders\SaleLines\AbstractSaleLines;

/**
 * A Shopee order's sale as Seller Center shows it, from the escrow detail's
 * order_income once Shopee has settled the order. Shopee's buyer total is the
 * total. Item markdown (order_seller_discount, shopee_discount) is already in
 * the selling price used as the subtotal, so it is not a line of its own.
 */
final class ShopeeSaleLines extends AbstractSaleLines
{
    protected function isComplete(): bool
    {
        return $this->has('order_original_price') || $this->has('buyer_total_amount');
    }

    protected function subtotalTitle(): string
    {
        return 'Merchandise Subtotal';
    }

    protected function subtotal(float $itemsSubtotal): float
    {
        foreach (['order_selling_price', 'order_discounted_price'] as $field) {
            if ($this->amount($field) > 0) {
                return $this->amount($field);
            }
        }

        return $itemsSubtotal;
    }

    protected function definitions(): array
    {
        return [
            self::SHIPPING => ['title' => 'Shipping Fee', 'field' => 'buyer_paid_shipping_fee', 'sign' => 1, 'standard' => true],
            self::PLATFORM_VOUCHER => ['title' => 'Shopee Voucher', 'field' => 'voucher_from_shopee', 'sign' => -1, 'standard' => true],
            self::SELLER_VOUCHER => ['title' => 'Seller Voucher', 'field' => 'voucher_from_seller', 'sign' => -1, 'standard' => true],
            self::COINS => ['title' => 'Coins', 'field' => 'coins', 'sign' => -1],
            self::BUYER_TRANSACTION_FEE => ['title' => 'Transaction fee paid by buyer', 'field' => 'buyer_transaction_fee', 'sign' => 1],
            // Optional cover the buyer adds at checkout; Shopee collects it and it is not paid out to the seller.
            self::PRODUCT_PROTECTION => ['title' => 'Product protection', 'field' => 'final_product_protection', 'sign' => 1],
        ];
    }

    protected function totalTitle(): string
    {
        return 'Total Buyer Payment';
    }

    protected function total(float $sumOfLines): float
    {
        return $this->has('buyer_total_amount') ? $this->amount('buyer_total_amount') : $sumOfLines;
    }
}
