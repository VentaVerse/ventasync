<?php

namespace App\Services;

use App\Channels\ChannelRegistry;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderStatus;
use App\Models\Catalog\Product;
use App\Models\Catalog\ProductVariationValue;
use Illuminate\Support\Facades\DB;

class OrderStockService
{
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

        if ($oldSubtract === $newSubtract) {
            return;
        }

        $direction = ($oldSubtract === 0 && $newSubtract === 1) ? -1 : 1;
        $source = StockHistoryLogger::sourceFromOrder($order->channel ?? '');
        $orderLabel = self::label($order, $source);

        $order->load('products.variations');

        $defaultWarehouse = self::resolveDefaultWarehouse();

        foreach ($order->products as $orderProduct) {
            $resolved = self::resolve($orderProduct);
            self::move($order, $resolved['product'], $resolved['variation_value'], $direction * (int) $orderProduct->quantity, $source, $orderLabel, $defaultWarehouse);
        }
    }

    public static function snapshot(Order $order): array
    {
        $order->unsetRelation('products');
        $order->load('products.variations');

        $lines = [];
        foreach ($order->products as $orderProduct) {
            $resolved = self::resolve($orderProduct);
            if (! $resolved['product'] && ! $resolved['variation_value']) {
                continue;
            }
            $key = (int) ($resolved['product']->product_id ?? $resolved['variation_value']->product_id) . '|' . (int) ($resolved['variation_value']->product_variation_value_id ?? 0);
            $lines[$key] ??= ['product' => $resolved['product'], 'variation_value' => $resolved['variation_value'], 'qty' => 0];
            $lines[$key]['qty'] += (int) $orderProduct->quantity;
        }

        return $lines;
    }

    public static function reconcile(Order $order, array $before, int $oldStatusId, int $newStatusId): void
    {
        $old = self::subtracts($oldStatusId) ? $before : [];
        $new = self::subtracts($newStatusId) ? self::snapshot($order) : [];

        $source = StockHistoryLogger::sourceFromOrder($order->channel ?? '');
        $orderLabel = self::label($order, $source);
        $defaultWarehouse = self::resolveDefaultWarehouse();

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $deductMore = ($new[$key]['qty'] ?? 0) - ($old[$key]['qty'] ?? 0);
            if ($deductMore === 0) {
                continue;
            }
            $entry = $new[$key] ?? $old[$key];
            self::move($order, $entry['product'], $entry['variation_value'], -$deductMore, $source, $orderLabel, $defaultWarehouse);
        }
    }

    private static function subtracts(int $statusId): bool
    {
        if ($statusId <= 0) {
            return false;
        }

        return (int) (OrderStatus::where('order_status_id', $statusId)->value('subtract_stock') ?? 0) === 1;
    }

    private static function label(Order $order, string $source): string
    {
        $sourceLabel = match ($source) {
            'lazada_sync'   => 'Lazada',
            'shopee_sync'   => 'Shopee',
            'tiktok_sync'   => 'TikTok',
            'opencart_sync' => 'OpenCart',
            'ventacart_sync'    => 'VentaCart',
            default         => '',
        };

        return 'Order #' . $order->order_id . ($sourceLabel !== '' ? " ($sourceLabel)" : '');
    }

    private static function move(Order $order, ?Product $product, ?ProductVariationValue $variationValue, int $delta, string $source, string $orderLabel, ?object $defaultWarehouse): void
    {
        if ($delta === 0) {
            return;
        }

        $type = $delta < 0 ? 'deduct' : 'restore';
        $amount = abs($delta);
        $variationValue = $variationValue?->fresh();
        $product = $product?->fresh();

        if ($variationValue && (int) $variationValue->subtract_stock) {
            $ovQtyBefore = (int) $variationValue->quantity;
            $variationValue->quantity = $variationValue->quantity + $delta;
            $variationValue->save();

            DB::table('product_variation_parts as cv')
                ->join('product_variations as c', 'c.id', '=', 'cv.product_variation_id')
                ->where('cv.product_variation_value_id', $variationValue->product_variation_value_id)
                ->where('c.product_id', $variationValue->product_id)
                ->update(['c.quantity' => $variationValue->quantity]);

            StockHistoryLogger::log(
                productId: (int) $variationValue->product_id,
                variationValueId: (int) $variationValue->product_variation_value_id,
                orderId: (int) $order->order_id,
                type: $type,
                qtyBefore: $ovQtyBefore,
                qtyAfter: (int) $variationValue->quantity,
                source: $source,
                note: "$orderLabel: {$type}ed {$amount} (variation)",
            );

            self::syncWarehouseStock(
                $defaultWarehouse,
                (int) $variationValue->product_id,
                (int) $variationValue->product_variation_value_id,
                $delta,
            );
        }

        if ($product && (int) $product->subtract_stock) {
            $pQtyBefore = (int) $product->quantity;
            $product->quantity = $product->quantity + $delta;
            $product->save();

            StockHistoryLogger::log(
                productId: (int) $product->product_id,
                variationValueId: null,
                orderId: (int) $order->order_id,
                type: $type,
                qtyBefore: $pQtyBefore,
                qtyAfter: (int) $product->quantity,
                source: $source,
                note: "$orderLabel: {$type}ed {$amount}",
            );

            if (! $variationValue) {
                self::syncWarehouseStock(
                    $defaultWarehouse,
                    (int) $product->product_id,
                    0,
                    $delta,
                );
            }
        }
    }

    private static function resolveDefaultWarehouse(): ?object
    {
        if (!class_exists(\Extensions\Warehousing\Services\WarehouseStockService::class)) {
            return null;
        }

        return \Extensions\Warehousing\Services\WarehouseStockService::getDefaultWarehouse();
    }

    private static function syncWarehouseStock(?object $warehouse, int $productId, int $povId, int $delta): void
    {
        if (!$warehouse || $delta === 0) {
            return;
        }

        $inv = \Extensions\Warehousing\Services\WarehouseStockService::getOrCreateStock(
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

    private static function resolve($orderProduct): array
    {
        $product = null;
        $variationValue = null;
        $sku = trim((string) $orderProduct->sku);

        $orderVariation = $orderProduct->variations->first();
        if ($orderVariation && $orderVariation->product_variation_value_id > 0) {
            $variationValue = ProductVariationValue::where('product_variation_value_id', $orderVariation->product_variation_value_id)->first();
            if ($variationValue) {
                $product = Product::where('product_id', $variationValue->product_id)->first();
            }
        }

        if (!$product && $orderProduct->product_id > 0) {
            $product = Product::where('product_id', $orderProduct->product_id)->first();
        }

        if (!$product && $sku !== '') {
            foreach (app(ChannelRegistry::class)->skuResolvers() as $resolver) {
                $resolved = $resolver->resolveCatalogProduct($sku);
                if ($resolved === null) {
                    continue;
                }
                $product = Product::where('product_id', $resolved['product_id'])->first();
                if (!$variationValue && !empty($resolved['product_variation_value_id'])) {
                    $variationValue = ProductVariationValue::where('product_variation_value_id', $resolved['product_variation_value_id'])->first();
                }
                if ($product) {
                    break;
                }
            }

            if (!$product) {
                $product = Product::where('sku', $sku)->first();
            }
        }

        if ($product && !$variationValue && $sku !== '') {
            $variationValue = ProductVariationValue::where('product_id', $product->product_id)
                ->where('sku', $sku)
                ->first();
        }

        return ['product' => $product, 'variation_value' => $variationValue];
    }
}
