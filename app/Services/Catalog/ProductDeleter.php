<?php

namespace App\Services\Catalog;

use App\Integrations\Contracts\ProductRemover;
use App\Integrations\IntegrationRegistry;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

/**
 * The one way a catalog product is deleted (the owner, 2026-09-07): the
 * catalog row and every channel's local rows go together, in one
 * transaction, and no marketplace is called. The item a store holds
 * stays there; the flash says where. See ProductRemover.
 */
final class ProductDeleter
{
    public function __construct(private readonly IntegrationRegistry $registry) {}

    /**
     * Where the products sit, merged across channels, keyed by product id.
     *
     * @param list<int> $productIds
     * @return array<int, list<array{channel:string, store:string, item:string, live:?bool}>>
     */
    public function presence(array $productIds): array
    {
        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        if ($productIds === []) {
            return [];
        }
        $out = [];
        foreach ($this->removers() as $remover) {
            foreach ($remover->productPresence($productIds) as $pid => $rows) {
                foreach ($rows as $row) {
                    $out[(int) $pid][] = $row;
                }
            }
        }

        return $out;
    }

    /**
     * The sentence a delete confirmation carries, or '' when the product
     * is on no store: "It stays on Shopee (Main store) and Lazada (PH);
     * only VentaSync's record of it is removed."
     *
     * @param list<array{channel:string, store:string, item:string, live:?bool}> $presence
     */
    public function note(array $presence): string
    {
        $stores = [];
        foreach ($presence as $row) {
            if ($row['live'] === false) {
                continue; // the mirror says the item is already gone there
            }
            $stores[$row['channel'] . ' (' . $row['store'] . ')'] = true;
        }
        if ($stores === []) {
            return '';
        }

        return 'It stays on ' . $this->join(array_keys($stores)) . '; only VentaSync\'s record of it is removed.';
    }

    /**
     * One note per product id, '' where the product is on no store.
     *
     * @param list<int> $productIds
     * @return array<int, string>
     */
    public function notes(array $productIds): array
    {
        $out = [];
        foreach ($this->presence($productIds) as $pid => $rows) {
            $out[$pid] = $this->note($rows);
        }

        return $out;
    }

    /** The per-product tables a delete must empty, besides description, category and the row itself. */
    public const OWNED_TABLES = ['product_option_value', 'product_option', 'product_image', 'product_special', 'product_vendors'];

    /**
     * The rows a product owns: its variations (option rows and values,
     * combinations and their values), images, specials, vendors. Shared by
     * the delete and the orphan purge (catalog:purge-orphan-rows).
     */
    public static function forgetOwnedRows(string $pfx, int $productId): void
    {
        $comboIds = DB::table('product_option_combinations')->where('product_id', $productId)->pluck('id')->all();
        if ($comboIds !== []) {
            DB::table('product_option_combination_values')->whereIn('combination_id', $comboIds)->delete();
            DB::table('product_option_combinations')->whereIn('id', $comboIds)->delete();
        }
        foreach (self::OWNED_TABLES as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($pfx . $table)) {
                DB::table($pfx . $table)->where('product_id', $productId)->delete();
            }
        }
    }

    /**
     * Delete the products: every channel forgets each one, then the
     * catalog row goes, all in one transaction per product.
     *
     * @param list<int> $productIds
     * @return array{deleted: list<int>, stays: list<string>, names: array<int, string>}
     *         stays: the "Channel (Store)" labels where an item is still up
     */
    public function delete(array $productIds): array
    {
        $pfx = (string) config('catalog.prefix');
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($v) => $v > 0)));
        $names = DB::table($pfx . 'product_description')
            ->whereIn('product_id', $productIds)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->pluck('name', 'product_id')
            ->map(fn ($n) => html_entity_decode((string) $n, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->all();

        $result = ['deleted' => [], 'stays' => [], 'names' => $names];

        foreach ($productIds as $id) {
            $stays = DB::transaction(function () use ($id, $pfx) {
                $stays = [];
                foreach ($this->removers() as $remover) {
                    array_push($stays, ...$remover->removeProduct($id));
                }

                /* Everything the product owns goes with it (live, 2026-09-07:
                 * the variations, images and specials stayed behind, and the
                 * SKU rule read the orphaned variation rows as a product still
                 * holding those SKUs, so a re-import of the same item was
                 * refused as "already used by a variation of product #2767",
                 * a product that no longer existed). */
                self::forgetOwnedRows($pfx, $id);
                DB::table($pfx . 'product_to_category')->where('product_id', $id)->delete();
                DB::table($pfx . 'product_description')->where('product_id', $id)->delete();
                DB::table($pfx . 'product')->where('product_id', $id)->delete();

                if (class_exists(\Extensions\warehousing\Models\WarehouseInventory::class)) {
                    \Extensions\warehousing\Models\WarehouseInventory::where('product_id', $id)->delete();
                }

                return $stays;
            });

            ActivityLogger::log('deleted', 'Product', $id, $names[$id] ?? null);
            $result['deleted'][] = $id;
            array_push($result['stays'], ...$stays);
        }

        $result['stays'] = array_values(array_unique($result['stays']));

        return $result;
    }

    /**
     * The flash a delete leaves behind, in words, and its tone.
     *
     * @return array{tone: string, text: string}
     */
    public function flash(array $result, int $asked): array
    {
        $deleted = count($result['deleted']);
        $text = $asked === 1 ? 'Deleted.' : "Deleted {$deleted} products.";
        if ($result['stays'] !== []) {
            $text .= ($asked === 1 ? ' It stays on ' : ' They stay on ') . $this->join($result['stays'])
                . '; remove ' . ($asked === 1 ? 'it' : 'them') . ' there when you want ' . ($asked === 1 ? 'it' : 'them') . ' gone.';
        }

        return ['tone' => 'status', 'text' => $text];
    }

    /** @return list<ProductRemover> */
    private function removers(): array
    {
        return $this->registry->productRemovers();
    }

    /** @param list<string> $items */
    private function join(array $items): string
    {
        $items = array_values($items);
        if (count($items) <= 1) {
            return (string) ($items[0] ?? '');
        }
        $last = array_pop($items);

        return implode(', ', $items) . ' and ' . $last;
    }
}
