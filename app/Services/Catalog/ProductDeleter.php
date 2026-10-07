<?php

namespace App\Services\Catalog;

use App\Integrations\Contracts\ProductRemover;
use App\Integrations\IntegrationRegistry;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

final class ProductDeleter
{
    public function __construct(private readonly IntegrationRegistry $registry) {}

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

    public const OWNED_TABLES = ['product_option_value', 'product_option', 'product_image', 'product_special', 'product_vendors'];

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
        \App\Integrations\Listings\ListingVariations::forgetProducts([$productId]);
    }

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
