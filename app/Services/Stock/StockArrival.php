<?php

namespace App\Services\Stock;

use App\Services\Catalog\ProductQuantityWriter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class StockArrival
{
    private const WAREHOUSES = \Extensions\warehousing\Services\WarehouseStockService::class;

    public function locations(): array
    {
        if (! class_exists(self::WAREHOUSES) || ! \Illuminate\Support\Facades\Schema::hasTable('warehouses')) {
            return [];
        }

        return DB::table('warehouses')->orderByDesc('is_default')->orderBy('sort_order')->orderBy('name')
            ->get(['id', 'name', 'is_default', 'is_sellable'])
            ->map(fn ($w) => ['id' => (int) $w->id, 'name' => (string) $w->name, 'default' => (bool) $w->is_default, 'sellable' => (bool) $w->is_sellable])
            ->all();
    }

    public function add(int $productId, ?int $variationId, int $quantity, ?int $warehouseId, string $note, string $actor): array
    {
        if ($quantity < 1) {
            throw new RuntimeException('Nothing to add.');
        }

        $pfx = (string) config('catalog.prefix');

        if ($variationId !== null && DB::table($pfx . 'product_option')->where('product_id', $productId)->count() > 1) {
            throw new RuntimeException('it names one value of a product with two variation types, not a combination, so there is no single variation to add it to');
        }

        $before = (int) DB::table($pfx . 'product')->where('product_id', $productId)->value('quantity');
        $locations = $this->locations();

        if ($locations !== []) {
            $warehouse = $warehouseId === null
                ? ($locations[0]['default'] ? $locations[0] : null)
                : collect($locations)->firstWhere('id', $warehouseId);
            if ($warehouse === null) {
                throw new RuntimeException($warehouseId === null ? 'there is no default warehouse; name one' : "there is no warehouse {$warehouseId}");
            }

            $service = self::WAREHOUSES;
            $service::adjustStock($warehouse['id'], $productId, (int) ($variationId ?? 0), $quantity, 'purchase_order', $note);

            return [
                'warehouse' => $warehouse['name'],
                'for_sale' => $warehouse['sellable'],
                'before' => $before,
                'after' => (int) DB::table($pfx . 'product')->where('product_id', $productId)->value('quantity'),
            ];
        }

        $sku = $variationId !== null
            ? (string) DB::table($pfx . 'product_option_value')->where('product_option_value_id', $variationId)->value('sku')
            : null;
        if ($variationId !== null && $sku === '') {
            throw new RuntimeException('the variation has no SKU to add stock by');
        }
        $result = app(ProductQuantityWriter::class)->from('purchase_order')->set($productId, $sku, null, $quantity, $note, $actor);

        return [
            'warehouse' => null,
            'for_sale' => true,
            'before' => $before,
            'after' => (int) ($result['product_after'] ?? $result['after'] ?? DB::table($pfx . 'product')->where('product_id', $productId)->value('quantity')),
        ];
    }
}
