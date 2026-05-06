<?php

namespace App\Services;

use App\Integrations\IntegrationRegistry;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductOptionValue;
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
            'venta_sync'    => 'Venta',
            default         => '',
        };
        $orderLabel = 'Order #' . $order->order_id . ($sourceLabel !== '' ? " ($sourceLabel)" : '');

        // Ensure fresh relationship data (products may have just been inserted)
        $order->load('products.options');

        // Resolve default warehouse once for all line items (null if extension not installed)
        $defaultWarehouse = self::resolveDefaultWarehouse();

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

                // Sync warehouse inventory for this option value
                self::syncWarehouseInventory(
                    $defaultWarehouse,
                    (int) $optionValue->product_id,
                    (int) $optionValue->product_option_value_id,
                    $direction * $orderProduct->quantity,
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

                // Sync warehouse inventory for product-level (only for products without options)
                if (!$optionValue) {
                    self::syncWarehouseInventory(
                        $defaultWarehouse,
                        (int) $product->product_id,
                        0,
                        $direction * $orderProduct->quantity,
                    );
                }
            }
        }
    }

    /**
     * Resolve the default warehouse (null if warehousing extension is not installed).
     */
    private static function resolveDefaultWarehouse(): ?object
    {
        if (!class_exists(\Extensions\warehousing\Services\WarehouseStockService::class)) {
            return null;
        }

        return \Extensions\warehousing\Services\WarehouseStockService::getDefaultWarehouse();
    }

    /**
     * Sync warehouse inventory for a product/option-value after an order stock adjustment.
     * Directly increments/decrements the warehouse_inventory row without calling
     * syncProductTotal() — OrderStockService already manages catalog quantities.
     */
    private static function syncWarehouseInventory(?object $warehouse, int $productId, int $povId, int $delta): void
    {
        if (!$warehouse || $delta === 0) {
            return;
        }

        $inv = \Extensions\warehousing\Services\WarehouseStockService::getOrCreateInventory(
            $warehouse->id,
            $productId,
            $povId,
        );

        if ($delta >= 0) {
            $inv->increment('quantity', $delta);
        } else {
            $inv->decrement('quantity', abs($delta));
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

        // --- Marketplace SKU lookup via the integration registry ---
        // Each marketplace extension that maintains its own SKU↔catalog map
        // (Lazada has seller_sku → LazadaProductVariant → catalog product) can
        // implement Contracts\SkuResolver and contribute here. Core no longer
        // imports the marketplace's models directly.
        if (!$product && $sku !== '') {
            foreach (app(IntegrationRegistry::class)->skuResolvers() as $resolver) {
                $resolved = $resolver->resolveCatalogProduct($sku);
                if ($resolved === null) {
                    continue;
                }
                $product = Product::where('product_id', $resolved['product_id'])->first();
                if (!$optionValue && !empty($resolved['product_option_value_id'])) {
                    $optionValue = ProductOptionValue::where('product_option_value_id', $resolved['product_option_value_id'])->first();
                }
                if ($product) {
                    break;
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
