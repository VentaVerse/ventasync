<?php

namespace App\Services;

use App\Models\Catalog\Order;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductOptionValue;
use App\Models\LazadaProduct;
use App\Models\LazadaProductVariant;
use Illuminate\Support\Facades\DB;

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
        $type = $direction === -1 ? 'deduct' : 'restore';
        $source = StockHistoryLogger::sourceFromOrder($order->marketplace_source ?? '');
        $sourceLabel = match ($source) {
            'lazada_sync'   => 'Lazada',
            'shopee_sync'   => 'Shopee',
            'tiktok_sync'   => 'TikTok',
            'opencart_sync' => 'OpenCart',
            default         => '',
        };
        $orderLabel = 'Order #' . $order->order_id . ($sourceLabel !== '' ? " ($sourceLabel)" : '');

        // Ensure fresh relationship data (products may have just been inserted)
        $order->load('products.options');

        foreach ($order->products as $orderProduct) {
            $resolved = self::resolve($orderProduct);
            $product = $resolved['product'];
            $optionValue = $resolved['option_value'];

            if ($optionValue && (int) $optionValue->subtract) {
                $ovQtyBefore = (int) $optionValue->quantity;
                // Option-level stock: adjust the specific option value
                $optionValue->quantity = $optionValue->quantity + ($direction * $orderProduct->quantity);
                $optionValue->save();

                // Also sync the combination quantity to stay in sync with product_option_value
                DB::table('product_option_combination_values as cv')
                    ->join('product_option_combinations as c', 'c.id', '=', 'cv.combination_id')
                    ->where('cv.product_option_value_id', $optionValue->product_option_value_id)
                    ->where('c.product_id', $optionValue->product_id)
                    ->update(['c.quantity' => $optionValue->quantity]);

                StockHistoryLogger::log(
                    productId: (int) $optionValue->product_id,
                    optionValueId: (int) $optionValue->product_option_value_id,
                    orderId: (int) $order->order_id,
                    type: $type,
                    qtyBefore: $ovQtyBefore,
                    qtyAfter: (int) $optionValue->quantity,
                    source: $source,
                    note: "$orderLabel — {$type}ed {$orderProduct->quantity} (option)",
                );
            }

            if ($product && (int) $product->subtract) {
                $pQtyBefore = (int) $product->quantity;
                // Product-level stock: always adjust main product total
                $product->quantity = $product->quantity + ($direction * $orderProduct->quantity);
                $product->save();

                StockHistoryLogger::log(
                    productId: (int) $product->product_id,
                    optionValueId: null,
                    orderId: (int) $order->order_id,
                    type: $type,
                    qtyBefore: $pQtyBefore,
                    qtyAfter: (int) $product->quantity,
                    source: $source,
                    note: "$orderLabel — {$type}ed {$orderProduct->quantity}",
                );
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
