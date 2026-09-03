<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\venta\Models\VentaProductLink;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Models\VentaUnmatchedItem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Venta joins the import pattern (cleanup point 11, the owner 2026-09-04):
 * the scan queues what the store holds and the catalog cannot place,
 * and Import creates the real product - description, images skipped
 * gracefully, variations as a single axis (Venta variants carry no
 * option structure) - linked to the store and enabled, because it is
 * already selling there. A SKU the catalog already holds rolls the
 * whole import back.
 */
class VentaItemImportTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://one.venta.test';

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('venta');
        $manager->enable('venta');
        $this->app->register(\Extensions\venta\VentaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
    }

    private function store(): VentaSetting
    {
        return VentaSetting::query()->create([
            'store_name' => 'One', 'enabled' => true,
            'base_url' => self::BASE, 'api_token' => 't',
        ]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Venta import managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_venta/listing', 'manage_venta/listing'])->pluck('id')->all()
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

    public function test_the_scan_queues_what_the_store_holds_and_the_catalog_does_not(): void
    {
        $setting = $this->store();
        $this->seedProduct('KNOWN-1');

        Http::fake([
            self::BASE . '/api/v1/products?*' => Http::response(['data' => [
                ['id' => 11, 'sku' => 'KNOWN-1', 'name' => 'Known product', 'price' => 100, 'quantity' => 5],
                ['id' => 12, 'sku' => 'NEW-1', 'name' => 'Store-only product', 'price' => 250, 'quantity' => 3, 'variants' => [['sku' => 'NEW-1-A']]],
            ]]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.venta.products.sync_unmatched', $setting->id))
            ->assertRedirect();

        $this->assertSame(1, VentaUnmatchedItem::query()->where('status', 'unmatched')->count(),
            'a SKU the catalog knows is never queued');
        $row = VentaUnmatchedItem::query()->firstOrFail();
        $this->assertSame('NEW-1', $row->sku);
        $this->assertSame(1, (int) $row->variant_count);
    }

    public function test_import_creates_the_product_with_variations_and_the_link(): void
    {
        $setting = $this->store();
        $row = VentaUnmatchedItem::create([
            'venta_setting_id' => $setting->id, 'sku' => 'IMP-1', 'name' => 'Importable', 'status' => 'unmatched',
        ]);

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
            ->post(route('ext.venta.products.import_unmatched', [$setting->id, $row->id]))
            ->assertRedirect();

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->where('sku', 'IMP-1')->first();
        $this->assertNotNull($product, 'the import must create the catalog product');
        $this->assertSame(1, (int) $product->status, 'already selling on the store: it arrives enabled');

        $variantSkus = DB::table($pfx . 'product_option_value')
            ->where('product_id', $product->product_id)->pluck('sku')->all();
        $this->assertEqualsCanonicalizing(['IMP-1-RED', 'IMP-1-BLUE'], $variantSkus,
            'variants become a single Variation axis with their own SKUs');

        $link = VentaProductLink::query()->where('product_id', $product->product_id)->first();
        $this->assertNotNull($link);
        $this->assertSame(77, (int) $link->venta_product_id);

        $this->assertSame('linked', $row->fresh()->status);
        $this->assertSame((int) $product->product_id, (int) $row->fresh()->linked_product_id);
    }

    public function test_a_sku_the_catalog_holds_rolls_the_import_back(): void
    {
        $setting = $this->store();
        $existing = $this->seedProduct('CLASH-V');
        $row = VentaUnmatchedItem::create([
            'venta_setting_id' => $setting->id, 'sku' => 'IMP-2', 'name' => 'Clashing', 'status' => 'unmatched',
        ]);

        Http::fake([
            self::BASE . '/api/v1/products/IMP-2/variants' => Http::response(['data' => [
                ['sku' => 'CLASH-V', 'name' => 'Red', 'price' => 260, 'quantity' => 4],
            ]]),
            self::BASE . '/api/v1/products/IMP-2' => Http::response(['data' => [
                'id' => 78, 'sku' => 'IMP-2', 'name' => 'Clashing thing', 'price' => 250, 'quantity' => 6, 'images' => [],
            ]]),
        ]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.venta.products.import_unmatched', [$setting->id, $row->id]));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Not imported', session('error'));
        $pfx = (string) config('catalog.prefix');
        $this->assertNull(DB::table($pfx . 'product')->where('sku', 'IMP-2')->first(),
            'a refused import must leave nothing half-created');
        $this->assertSame('unmatched', $row->fresh()->status);
    }

    public function test_the_import_page_owns_the_queue(): void
    {
        $setting = $this->store();
        VentaUnmatchedItem::create([
            'venta_setting_id' => $setting->id, 'sku' => 'Q-1', 'name' => 'Queued thing', 'status' => 'unmatched',
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.venta.products.import', $setting->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Queued thing', $html);
        $this->assertStringContainsString(route('ext.venta.products.sync_unmatched', $setting->id), $html);
    }
}
