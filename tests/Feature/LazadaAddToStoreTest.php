<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * All Listings goes per-store (#201, the owner 2026-09-05): the Listings workbench
 * splits into two surfaces. THIS STORE shows only what this store carries; ADD
 * PRODUCTS is the main catalogue, where a product is added into the store's
 * list. A new store's This store is empty until products are added.
 */
class LazadaAddToStoreTest extends TestCase
{
    use RefreshDatabase;

    private LazadaSetting $store;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->store = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);
        $this->artisan('permissions:sync-catalogue');
    }

    private function manager(): User
    {
        // Unique per call: some tests act as a manager twice, and
        // user_groups.name is unique.
        $group = UserGroup::create(['name' => 'LZ add manager ' . uniqid()]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_lazada/dashboard', 'view_lazada/product', 'manage_lazada/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100, 'status' => 1,
            'image' => '', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    public function test_this_store_shows_only_products_added_to_this_store(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $offStore = $this->seedProduct('Catalogue only widget', 'OFF-1');
        LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $onStore]);

        $html = $this->actingAs($this->manager())
            ->get('/channels/lazada/' . $this->store->id . '/products')
            ->assertOk()->getContent();

        $this->assertStringContainsString('On store widget', $html, 'This store must show a product on this store');
        $this->assertStringNotContainsString('Catalogue only widget', $html, 'This store must NOT show a catalogue product that is not on this store');
    }

    public function test_a_new_store_lists_nothing_and_points_to_add_products(): void
    {
        $this->seedProduct('Catalogue only widget', 'OFF-1');

        $html = $this->actingAs($this->manager())
            ->get('/channels/lazada/' . $this->store->id . '/products')
            ->assertOk()->getContent();

        $this->assertStringContainsString('No products on this store yet', $html);
        $this->assertStringContainsString('/products?list=add', $html, 'the empty state must offer Add products');
    }

    public function test_add_products_shows_the_whole_catalogue_with_an_add_action(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $offStore = $this->seedProduct('Catalogue only widget', 'OFF-1');
        LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $onStore]);

        $html = $this->actingAs($this->manager())
            ->get('/channels/lazada/' . $this->store->id . '/products?list=add')
            ->assertOk()->getContent();

        // Both the catalogue product and the on-store one appear here.
        $this->assertStringContainsString('Catalogue only widget', $html);
        $this->assertStringContainsString('On store widget', $html);
        // The off-store product offers Add; the on-store one names its state.
        $this->assertStringContainsString('Add to store', $html);
        $this->assertStringContainsString('On this store', $html);
        $this->assertStringContainsString('your main catalogue', strtolower($html));
    }

    public function test_add_to_store_puts_a_catalogue_product_on_the_store(): void
    {
        $pid = $this->seedProduct('Catalogue only widget', 'OFF-1');

        $this->assertSame(0, LazadaProduct::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->count());

        $this->actingAs($this->manager())
            ->from('/channels/lazada/' . $this->store->id . '/products?list=add')
            ->post('/channels/lazada/' . $this->store->id . '/products/' . $pid . '/add-to-store')
            ->assertRedirect();

        $row = LazadaProduct::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->first();
        $this->assertNotNull($row, 'add-to-store must create the store listing row');
        $this->assertSame($this->store->id, (int) $row->lazada_setting_id, 'the row is stamped with this store');

        // It now shows under This store.
        $html = $this->actingAs($this->manager())
            ->get('/channels/lazada/' . $this->store->id . '/products')
            ->assertOk()->getContent();
        $this->assertStringContainsString('Catalogue only widget', $html);
    }

    public function test_bulk_add_puts_the_selected_products_on_the_store(): void
    {
        $a = $this->seedProduct('Widget A', 'A-1');
        $b = $this->seedProduct('Widget B', 'B-1');

        $this->actingAs($this->manager())
            ->from('/channels/lazada/' . $this->store->id . '/products?list=add')
            ->post('/channels/lazada/' . $this->store->id . '/products/add-to-store', ['product_ids' => [$a, $b]])
            ->assertRedirect();

        $this->assertSame(2, LazadaProduct::withoutGlobalScope('lazadaStore')->whereIn('product_id', [$a, $b])->where('lazada_setting_id', $this->store->id)->count());
    }

    public function test_add_to_store_is_idempotent(): void
    {
        $pid = $this->seedProduct('Widget', 'W-1');
        LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $pid]);

        $this->actingAs($this->manager())
            ->post('/channels/lazada/' . $this->store->id . '/products/' . $pid . '/add-to-store')
            ->assertRedirect();

        $this->assertSame(1, LazadaProduct::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->where('lazada_setting_id', $this->store->id)->count(),
            'adding a product already on the store must not double it');
    }
}
