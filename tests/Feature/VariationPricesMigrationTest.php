<?php

namespace Tests\Feature;

use App\Channels\Listings\VariationPrices;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class VariationPricesMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        if (Schema::hasColumn('ch_lazada_listing_variations', 'price')) {
            Schema::table('ch_lazada_listing_variations', fn (Blueprint $t) => $t->dropColumn('price'));
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['ch_listing_variation_prices', 'ch_lazada_listing_variations', 'ch_lazada_listings', 'product_variation_values', 'products'] as $table) {
            DB::table($table)->delete();
        }
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    public function test_the_upgrade_moves_lazadas_variation_prices_into_the_shared_table(): void
    {
        Schema::table('ch_lazada_listing_variations', fn (Blueprint $t) => $t->decimal('price', 15, 4)->nullable());

        $pid = DB::table('products')->insertGetId([
            'sku' => 'MIG', 'quantity' => 1, 'price' => 100, 'status' => 1, 'image' => '', 'weight' => 0,
            'length' => 0, 'width' => 0, 'height' => 0, 'brand_id' => 0, 'date_added' => now(), 'date_modified' => now(),
        ]);
        $pov = fn (string $sku) => DB::table('product_variation_values')->insertGetId([
            'product_variation_type_id' => 1, 'product_id' => $pid, 'variation_type_id' => 1, 'variation_value_id' => 1,
            'quantity' => 1, 'subtract_stock' => 1, 'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
            'weight' => 0, 'weight_prefix' => '+', 'sku' => $sku,
        ]);
        $red = $pov('MIG-Red');
        $blue = $pov('MIG-BLUE');
        $listing = DB::table('ch_lazada_listings')->insertGetId(['store_id' => 7, 'product_id' => $pid]);
        DB::table('ch_lazada_listing_variations')->insert([
            ['lazada_listing_id' => $listing, 'product_variation_value_id' => $red, 'price' => 210],
            ['lazada_listing_id' => $listing, 'product_variation_value_id' => $blue, 'price' => null],
        ]);

        (require database_path('migrations/2026_10_21_000002_listing_variation_prices.php'))->up();

        $this->assertSame([$pid => ['mig-red' => 210.0]], VariationPrices::forProducts('lazada', 7, [$pid]));
        $this->assertFalse(Schema::hasColumn('ch_lazada_listing_variations', 'price'));
        $this->assertSame(2, DB::table('ch_lazada_listing_variations')->count(), 'the SKU links stay');
    }
}
