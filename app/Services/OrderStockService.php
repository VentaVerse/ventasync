<?php

namespace App\Services;

use App\Models\Catalog\Order;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductOptionValue;
use App\Models\LazadaProduct;
use App\Models\LazadaProductVariant;

class OrderStockService
{
    /**
     * Adjust product stock when an order transitions between statuses.
     *
     * @param Order $order
     * @param int   $oldStatusId  Previous status (0 = new order)
     * @param int   $newStatusId  New status
     */
    public static function adjustStock(Order $order, int $oldStatusId, int $newStatusId): void
    {
        if ($oldStatusId === $newStatusId) {
            return;
        }

        $oldSubtract = 0;
        $newSubtract = 0;

        if ($oldStatusId > 0) {
            $oldStatus = OrderStatus::where('order_status_id', $oldStatusId)->first();
            $oldSubtract = $oldStatus ? (int) $oldStatus->subtract_stock : 0;
        }

        if ($newStatusId > 0) {
            $newStatus = OrderStatus::where('order_status_id', $newStatusId)->first();
            $newSubtract = $newStatus ? (int) $newStatus->subtract_stock : 0;
        }

        // No change in subtract behaviour
        if ($oldSubtract === $newSubtract) {
            return;
        }

        // Direction: deduct (-1) when entering a subtract status, restore (+1) when leaving
        $direction = ($oldSubtract === 0 && $newSubtract === 1) ? -1 : 1;

        // Ensure fresh relationship data (products may have just been inserted)
        $order->load('products.options');

        foreach ($order->products as $orderProduct) {
            $resolved = self::resolve($orderProduct);
            $product = $resolved['product'];
            $optionValue = $resolved['option_value'];

            if ($optionValue && (int) $optionValue->subtract) {
                // Option-level stock: adjust the specific option value
                $optionValue->quantity = $optionValue->quantity + ($direction * $orderProduct->quantity);
                $optionValue->save();
            }

            if ($product && (int) $product->subtract) {
                // Product-level stock: always adjust main product total
                $product->quantity = $product->quantity + ($direction * $orderProduct->quantity);
                $product->save();
            }
        }
    }

    /**
     * Resolve the catalog Product and ProductOptionValue for an order product.
     */
    private static function resolve($orderProduct): array
    {
        $product = null;
        $optionValue = null;
        $sku = trim((string) $orderProduct->model);

        // --- Try direct IDs from OrderOption ---
        $orderOption = $orderProduct->options->first();
        if ($orderOption && $orderOption->product_option_value_id > 0) {
            $optionValue = ProductOptionValue::where('product_option_value_id', $orderOption->product_option_value_id)->first();
            if ($optionValue) {
                $product = Product::where('product_id', $optionValue->product_id)->first();
            }
        }

        // --- Try direct product_id on order product ---
        if (!$product && $orderProduct->product_id > 0) {
            $product = Product::where('product_id', $orderProduct->product_id)->first();
        }

        // --- Lazada variant chain: seller_sku → variant → product + option value ---
        if (!$product && $sku !== '') {
            $variant = LazadaProductVariant::where('seller_sku', $sku)->first();
            if ($variant) {
                $lazadaProduct = LazadaProduct::where('id', $variant->lazada_product_id)->first();
                if ($lazadaProduct && $lazadaProduct->product_id > 0) {
                    $product = Product::where('product_id', $lazadaProduct->product_id)->first();
                }
                if (!$optionValue && $variant->product_option_value_id > 0) {
                    $optionValue = ProductOptionValue::where('product_option_value_id', $variant->product_option_value_id)->first();
                }
            }

            // Final fallback: match directly against catalog product sku/model
            if (!$product) {
                $product = Product::where('sku', $sku)->first()
                    ?? Product::where('model', $sku)->first();
            }
        }

        // --- Match option value by SKU on the product ---
        // If we found the product but not the option value, try matching
        // the seller SKU against product_option_value.sku
        if ($product && !$optionValue && $sku !== '') {
            $optionValue = ProductOptionValue::where('product_id', $product->product_id)
                ->where('sku', $sku)
                ->first();
        }

        return ['product' => $product, 'option_value' => $optionValue];
    }
}
