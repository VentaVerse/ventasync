<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VentaCartItemImportTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://one.ventacart.test';

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('ventacart');
        $manager->enable('ventacart');
        $this->app->register(\Extensions\ventacart\VentaCartExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
    }

    private function store(): VentaCartSetting
    {
        return VentaCartSetting::query()->create([
            'store_name' => 'One', 'enabled' => true,
            'base_url' => self::BASE, 'api_token' => 't',
        ]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'VentaCart import managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_ventacart/listing', 'manage_ventacart/listing'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => $langId,
            'name' => 'Existing ' . $sku, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    public function test_the_page_starts_blank_and_reads_nothing(): void
    {
        $setting = $this->store();
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.products.import', $setting->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Nothing fetched yet', $html);
        $this->assertStringContainsString('Fetch from One', $html);
        Http::assertNothingSent();
    }

    public function test_fetch_renders_the_live_diff_and_saves_none_of_it(): void
    {
        $setting = $this->store();
        $this->seedProduct('KNOWN-1');

        Http::fake([
            self::BASE . '/api/v1/products?*' => Http::response(['data' => [
                ['id' => 11, 'sku' => 'KNOWN-1', 'name' => 'Known product', 'price' => 100, 'quantity' => 5],
                ['id' => 12, 'sku' => 'NEW-1', 'name' => 'Store-only product', 'price' => 250, 'quantity' => 3, 'variants' => [['sku' => 'NEW-1-A']]],
            ]]),
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.products.import', ['store' => $setting->id, 'fetch' => 1]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Store-only product', $html);
        $this->assertStringNotContainsString('Known product', $html, 'a SKU the catalog knows is never listed');
        $this->assertStringContainsString('name="refs[]" value="NEW-1"', $html);
        $this->assertStringContainsString('Read 2 items on the store just now', $html);
    }

    public function test_import_creates_the_product_with_variations_and_the_link(): void
    {
        $setting = $this->store();

        Http::fake([
            self::BASE . '/api/v1/products/IMP-1/variants' => Http::response(['data' => [
                ['sku' => 'IMP-1-RED', 'name' => 'Red', 'price' => 260, 'quantity' => 4],
                ['sku' => 'IMP-1-BLUE', 'name' => 'Blue', 'price' => 270, 'quantity' => 2],
            ]]),
            self::BASE . '/api/v1/products/IMP-1' => Http::response(['data' => [
                'id' => 77, 'sku' => 'IMP-1', 'name' => 'Importable thing',
                'description' => '<p>Store words.</p>', 'price' => 250, 'quantity' => 6, 'weight' => 0.4,
                'images' => [],
            ]]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.products.import_one', $setting->id), ['ref' => 'IMP-1'])
            ->assertRedirect();

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->where('sku', 'IMP-1')->first();
        $this->assertNotNull($product, 'the import must create the catalog product');
        $this->assertSame(1, (int) $product->status, 'already selling on the store: it arrives enabled');

        $variantSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $product->product_id)->pluck('sku')->all();
        $this->assertEqualsCanonicalizing(['IMP-1-RED', 'IMP-1-BLUE'], $variantSkus,
            'variants become a single Variation axis with their own SKUs');

        $link = VentaCartProductLink::query()->where('product_id', $product->product_id)->first();
        $this->assertNotNull($link);
        $this->assertSame(77, (int) $link->ventacart_product_id);
    }

    public function test_a_sku_the_catalog_holds_rolls_the_import_back(): void
    {
        $setting = $this->store();
        $this->seedProduct('CLASH-V');

        Http::fake([
            self::BASE . '/api/v1/products/IMP-2/variants' => Http::response(['data' => [
                ['sku' => 'CLASH-V', 'name' => 'Red', 'price' => 260, 'quantity' => 4],
            ]]),
            self::BASE . '/api/v1/products/IMP-2' => Http::response(['data' => [
                'id' => 78, 'sku' => 'IMP-2', 'name' => 'Clashing thing', 'price' => 250, 'quantity' => 6, 'images' => [],
            ]]),
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.ventacart.products.import_one', $setting->id), ['ref' => 'IMP-2']);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Not imported', session('error'));
        $pfx = (string) config('catalog.prefix');
        $this->assertNull(DB::table($pfx . 'product')->where('sku', 'IMP-2')->first(),
            'a refused import must leave nothing half-created');
    }

    public function test_import_selected_runs_each_ref_and_reports_honestly(): void
    {
        $setting = $this->store();
        $this->seedProduct('CLASH-V');

        Http::fake([
            self::BASE . '/api/v1/products/SEL-1/variants' => Http::response(['data' => []]),
            self::BASE . '/api/v1/products/SEL-1' => Http::response(['data' => [
                'id' => 81, 'sku' => 'SEL-1', 'name' => 'Good one', 'price' => 100, 'quantity' => 2, 'images' => [],
            ]]),
            self::BASE . '/api/v1/products/SEL-2/variants' => Http::response(['data' => [
                ['sku' => 'CLASH-V', 'name' => 'Red', 'price' => 110, 'quantity' => 1],
            ]]),
            self::BASE . '/api/v1/products/SEL-2' => Http::response(['data' => [
                'id' => 82, 'sku' => 'SEL-2', 'name' => 'Clashing one', 'price' => 100, 'quantity' => 1, 'images' => [],
            ]]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.products.import_selected', $setting->id), [
                'refs' => ['SEL-1', 'SEL-2'],
            ])
            ->assertRedirect();

        $this->assertStringContainsString('Imported 1 of 2 selected items', session('status'),
            'a mixed run reports what landed and what did not');
        $pfx = (string) config('catalog.prefix');
        $this->assertNotNull(DB::table($pfx . 'product')->where('sku', 'SEL-1')->first());
        $this->assertNull(DB::table($pfx . 'product')->where('sku', 'SEL-2')->first(),
            'the refused import rolled back alone - it did not take the good one with it');
    }

    public function test_link_writes_the_real_link_from_the_values_the_page_carried(): void
    {
        $setting = $this->store();
        $pid = $this->seedProduct('MINE-1');

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.products.link_item', $setting->id), [
                'ref' => 'STORE-SKU-9', 'channel_id' => 99, 'product_id' => $pid,
            ])
            ->assertRedirect();

        $link = VentaCartProductLink::query()->where('product_id', $pid)->first();
        $this->assertNotNull($link, 'linking writes real data - the one thing this page does persist');
        $this->assertSame('STORE-SKU-9', $link->sku);
        $this->assertSame(99, (int) $link->ventacart_product_id);
    }
}
