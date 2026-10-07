<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use Extensions\ventacart\Services\VentaCart\VentaCartProductPush;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class VentaCartVariationImageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('ventacart');
        $manager->enable('ventacart');
        $this->app->register(\Extensions\ventacart\VentaCartExtension::class);
    }

    private function seedProduct(): int
    {
        $pfx = (string) config('catalog.prefix');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'VIMG-1', 'sku' => 'VIMG-1', 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/parent.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Variation image product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_each_variation_wears_its_own_image_and_a_blank_falls_back(): void
    {
        $pid = $this->seedProduct();
        DB::table('product_option_combinations')->insert([
            ['product_id' => $pid, 'sku' => 'VIMG-1PC', 'quantity' => 3, 'absolute_price' => 100, 'absolute_cost' => 40, 'image' => 'catalog/one-pc.png', 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $pid, 'sku' => 'VIMG-5PC', 'quantity' => 2, 'absolute_price' => 450, 'absolute_cost' => 180, 'image' => null, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);

        $push = app(VentaCartProductPush::class);
        $payload = $push->payload($push->product($pid));

        $variants = collect($payload['variants'] ?? [])->keyBy('sku');
        $this->assertCount(2, $variants);

        $this->assertStringContainsString('one-pc.png', (string) $variants['VIMG-1PC']['image'],
            'the variation with its own image sends it');
        $this->assertStringContainsString('parent.png', (string) $variants['VIMG-5PC']['image'],
            'a variation without one falls back to the product image rather than pushing blank');
        $this->assertStringStartsWith('http', (string) $variants['VIMG-1PC']['image'],
            'VentaCart fetches over the web from the address the ERP publishes - it must be absolute');
    }

    public function test_a_variation_switched_off_goes_up_switched_off_rather_than_being_left_out(): void
    {
        $pid = $this->seedProduct();
        DB::table('product_option_combinations')->insert([
            ['product_id' => $pid, 'sku' => 'VIMG-1PC', 'quantity' => 3, 'absolute_price' => 100, 'absolute_cost' => 40, 'image' => null, 'sort_order' => 0, 'created_at' => now(), 'updated_at' => now()],
            ['product_id' => $pid, 'sku' => 'VIMG-5PC', 'quantity' => 2, 'absolute_price' => 450, 'absolute_cost' => 180, 'image' => null, 'sort_order' => 1, 'created_at' => now(), 'updated_at' => now()],
        ]);
        \App\Integrations\Listings\ListingVariations::save('ventacart', 0, $pid, ['VIMG-1PC']);

        $push = app(VentaCartProductPush::class);
        $variants = collect($push->payload($push->product($pid))['variants'] ?? [])->keyBy('sku');

        $this->assertCount(2, $variants, 'nothing is left out, so the store deletes nothing');
        $this->assertTrue($variants['VIMG-1PC']['status']);
        $this->assertFalse($variants['VIMG-5PC']['status'], 'the store hides it and keeps it');
    }
}
