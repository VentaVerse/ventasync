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
        $source = StockHistoryLogger::sourceFromOrder($order->marketplace_source ?? '');
        $orderLabel = self::label($order, $source);

        $order->load('products.options');

        $defaultWarehouse = self::resolveDefaultWarehouse();

        foreach ($order->products as $orderProduct) {
            $resolved = self::resolve($orderProduct);
            self::move($order, $resolved['product'], $resolved['option_value'], $direction * (int) $orderProduct->quantity, $source, $orderLabel, $defaultWarehouse);
        }
    }

    public static function snapshot(Order $order): array
    {
        $order->unsetRelation('products');
        $order->load('products.options');

        $lines = [];
        foreach ($order->products as $orderProduct) {
            $resolved = self::resolve($orderProduct);
            if (! $resolved['product'] && ! $resolved['option_value']) {
                continue;
            }
            $key = (int) ($resolved['product']->product_id ?? $resolved['option_value']->product_id) . '|' . (int) ($resolved['option_value']->product_option_value_id ?? 0);
            $lines[$key] ??= ['product' => $resolved['product'], 'option_value' => $resolved['option_value'], 'qty' => 0];
            $lines[$key]['qty'] += (int) $orderProduct->quantity;
        }

        return $lines;
    }

    public static function reconcile(Order $order, array $before, int $oldStatusId, int $newStatusId): void
    {
        $old = self::subtracts($oldStatusId) ? $before : [];
        $new = self::subtracts($newStatusId) ? self::snapshot($order) : [];

        $source = StockHistoryLogger::sourceFromOrder($order->marketplace_source ?? '');
        $orderLabel = self::label($order, $source);
        $defaultWarehouse = self::resolveDefaultWarehouse();

        foreach (array_unique(array_merge(array_keys($old), array_keys($new))) as $key) {
            $deductMore = ($new[$key]['qty'] ?? 0) - ($old[$key]['qty'] ?? 0);
            if ($deductMore === 0) {
                continue;
            }
            $entry = $new[$key] ?? $old[$key];
            self::move($order, $entry['product'], $entry['option_value'], -$deductMore, $source, $orderLabel, $defaultWarehouse);
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

    private static function move(Order $order, ?Product $product, ?ProductOptionValue $optionValue, int $delta, string $source, string $orderLabel, ?object $defaultWarehouse): void
    {
        if ($delta === 0) {
            return;
        }

        $type = $delta < 0 ? 'deduct' : 'restore';
        $amount = abs($delta);
        $optionValue = $optionValue?->fresh();
        $product = $product?->fresh();

        if ($optionValue && (int) $optionValue->subtract) {
            $ovQtyBefore = (int) $optionValue->quantity;
            $optionValue->quantity = $optionValue->quantity + $delta;
            $optionValue->save();

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
                note: "$orderLabel: {$type}ed {$amount} (option)",
            );

            self::syncWarehouseInventory(
                $defaultWarehouse,
                (int) $optionValue->product_id,
                (int) $optionValue->product_option_value_id,
                $delta,
            );
        }

        if ($product && (int) $product->subtract) {
            $pQtyBefore = (int) $product->quantity;
            $product->quantity = $product->quantity + $delta;
            $product->save();

            StockHistoryLogger::log(
                productId: (int) $product->product_id,
                optionValueId: null,
                orderId: (int) $order->order_id,
                type: $type,
                qtyBefore: $pQtyBefore,
                qtyAfter: (int) $product->quantity,
                source: $source,
                note: "$orderLabel: {$type}ed {$amount}",
            );

            if (! $optionValue) {
                self::syncWarehouseInventory(
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
        if (!class_exists(\Extensions\warehousing\Services\WarehouseStockService::class)) {
            return null;
        }

        return \Extensions\warehousing\Services\WarehouseStockService::getDefaultWarehouse();
    }

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

    private static function resolve($orderProduct): array
    {
        $product = null;
        $optionValue = null;
        $sku = trim((string) $orderProduct->model);

        $orderOption = $orderProduct->options->first();
        if ($orderOption && $orderOption->product_option_value_id > 0) {
            $optionValue = ProductOptionValue::where('product_option_value_id', $orderOption->product_option_value_id)->first();
            if ($optionValue) {
                $product = Product::where('product_id', $optionValue->product_id)->first();
            }
        }

        if (!$product && $orderProduct->product_id > 0) {
            $product = Product::where('product_id', $orderProduct->product_id)->first();
        }

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

            if (!$product) {
                $product = Product::where('sku', $sku)->first()
                    ?? Product::where('model', $sku)->first();
            }
        }

        if ($product && !$optionValue && $sku !== '') {
            $optionValue = ProductOptionValue::where('product_id', $product->product_id)
                ->where('sku', $sku)
                ->first();
        }

        return ['product' => $product, 'option_value' => $optionValue];
    }
}
