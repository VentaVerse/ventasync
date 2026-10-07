<?php

use App\Integrations\Listings\CatalogCopy;
use App\Support\Catalog\DescriptionText;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLES = [
        'lazada_products' => ['title' => 'item_name', 'description' => 'description', 'images' => 'image_order', 'plain' => false],
        'shopee_listings' => ['title' => 'item_name', 'description' => 'description', 'images' => 'image_order', 'plain' => true],
        'tiktok_listings' => ['title' => 'title', 'description' => 'description', 'images' => 'image_order', 'plain' => false],
        'venta_listings' => ['title' => 'name', 'description' => 'description', 'images' => 'image_order', 'plain' => false],
        'woocommerce_listings' => ['title' => 'name', 'description' => 'description', 'images' => 'image_order', 'plain' => false],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $cols) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            if (! Schema::hasColumn($table, 'catalog_seen_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->timestamp('catalog_seen_at')->nullable());
            }
            if (! Schema::hasColumns($table, [$cols['title'], $cols['description'], $cols['images'], 'product_id'])) {
                continue;
            }

            DB::table($table)->orderBy('id')->chunkById(200, function ($rows) use ($table, $cols) {
                $catalog = CatalogCopy::catalog($rows->pluck('product_id')->all());
                foreach ($rows as $row) {
                    $entry = $catalog[(int) $row->product_id] ?? null;
                    if ($entry === null) {
                        continue;
                    }

                    $update = [];
                    if (trim((string) ($row->{$cols['title']} ?? '')) === '') {
                        $update[$cols['title']] = $entry['title'];
                    }
                    if (DescriptionText::isBlank($row->{$cols['description']} ?? null)) {
                        $update[$cols['description']] = $entry['description'];
                    }
                    $images = json_decode((string) ($row->{$cols['images']} ?? ''), true);
                    if ((! is_array($images) || $images === []) && $entry['photos'] !== []) {
                        $update[$cols['images']] = json_encode($entry['photos']);
                    }

                    $filled = (object) array_merge((array) $row, $update);
                    if (CatalogCopy::differences(CatalogCopy::listing($filled, $cols, $entry), $entry, $cols['plain']) === []) {
                        $update['catalog_seen_at'] = now();
                    }
                    if ($update !== []) {
                        DB::table($table)->where('id', $row->id)->update($update);
                    }
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_keys(self::TABLES) as $table) {
            if (Schema::hasTable($table) && Schema::hasColumn($table, 'catalog_seen_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('catalog_seen_at'));
            }
        }
    }
};
