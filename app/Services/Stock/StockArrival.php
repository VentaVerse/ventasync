<?php

namespace App\Services\Stock;

use App\Services\Catalog\ProductQuantityWriter;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Stock that arrived: adds a quantity to a product or one of its variations,
 * for any module that receives goods, without that module knowing where
 * stock is kept.
 *
 * With the warehousing extension installed, the units land in a warehouse
 * the way its own Stock Adjustment page puts them there, and the stock the
 * stores see follows from the sellable warehouses. Without it, the product's
 * own stock goes up the way a stock adjustment does it.
 */
final class StockArrival
{
    private const WAREHOUSES = \Extensions\warehousing\Services\WarehouseStockService::class;

    /**
     * Where stock can land: every warehouse, the default first. Empty when
     * the warehousing extension is not installed.
     *
     * @return list<array{id: int, name: string, default: bool, sellable: bool}>
     */
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

    /**
     * Add $quantity units.
     *
     * @return array{warehouse: ?string, for_sale: bool, before: int, after: int}  the product's stock the stores see, before and after
     *
     * @throws RuntimeException when the units cannot honestly be placed
     */
    public function add(int $productId, ?int $variationId, int $quantity, ?int $warehouseId, string $note, string $actor): array
    {
        if ($quantity < 1) {
            throw new RuntimeException('Nothing to add.');
        }

        $pfx = (string) config('catalog.prefix');

        // A value of a two-type product is part of several combinations
        // ("Red" is Red/Small and Red/Large), so there is no one place for it.
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
