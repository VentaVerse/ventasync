<?php

namespace App\Services\Catalog;

use App\Channels\Contracts\ProductRemover;
use App\Channels\ChannelRegistry;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

final class ProductDeleter
{
    public function __construct(private readonly ChannelRegistry $registry) {}

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

    public function note(array $presence): string
    {
        $stores = [];
        foreach ($presence as $row) {
            if ($row['live'] === false) {
                continue;
            }
            $stores[$row['channel'] . ' (' . $row['store'] . ')'] = true;
        }
        if ($stores === []) {
            return '';
        }

        return 'It stays on ' . $this->join(array_keys($stores)) . '; only VentaSync\'s record of it is removed.';
    }

    public function notes(array $productIds): array
    {
        $out = [];
        foreach ($this->presence($productIds) as $pid => $rows) {
            $out[$pid] = $this->note($rows);
        }

        return $out;
    }

    public const OWNED_TABLES = ['product_variation_values', 'product_variation_types', 'product_images', 'product_specials', 'product_suppliers'];

    public static function forgetOwnedRows( int $productId): void
    {
        $comboIds = DB::table('product_variations')->where('product_id', $productId)->pluck('id')->all();
        if ($comboIds !== []) {
            DB::table('product_variation_parts')->whereIn('product_variation_id', $comboIds)->delete();
            DB::table('product_variations')->whereIn('id', $comboIds)->delete();
        }
        foreach (self::OWNED_TABLES as $table) {
            if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                DB::table($table)->where('product_id', $productId)->delete();
            }
        }
        \App\Channels\Listings\ListingVariations::forgetProducts([$productId]);
    }

    public function delete(array $productIds): array
    {
        $productIds = array_values(array_unique(array_filter(array_map('intval', $productIds), fn ($v) => $v > 0)));
        $names = DB::table('product_descriptions')
            ->whereIn('product_id', $productIds)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->pluck('name', 'product_id')
            ->map(fn ($n) => html_entity_decode((string) $n, ENT_QUOTES | ENT_HTML5, 'UTF-8'))
            ->all();

        $result = ['deleted' => [], 'stays' => [], 'names' => $names];

        foreach ($productIds as $id) {
            $stays = DB::transaction(function () use ($id) {
                $stays = [];
                foreach ($this->removers() as $remover) {
                    array_push($stays, ...$remover->removeProduct($id));
                }

                self::forgetOwnedRows( $id);
                DB::table('product_categories')->where('product_id', $id)->delete();
                DB::table('product_descriptions')->where('product_id', $id)->delete();
                DB::table('products')->where('product_id', $id)->delete();

                if (class_exists(\Extensions\Warehousing\Models\WarehouseStock::class)) {
                    \Extensions\Warehousing\Models\WarehouseStock::where('product_id', $id)->delete();
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

    private function removers(): array
    {
        return $this->registry->productRemovers();
    }

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
