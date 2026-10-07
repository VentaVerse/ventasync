<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CatalogChangeTest extends TestCase
{
    use RefreshDatabase;

    private int $groupSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        LazadaSetting::query()->create([
            'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r',
        ]);
    }

    private function user(bool $catalog): User
    {
        $keys = ['manage_lazada/product', 'view_lazada/product'];
        if ($catalog) {
            $keys[] = 'manage_catalog/product';
        }
        $group = UserGroup::create(['name' => 'Catalog change group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function product(string $name, string $description, string $image = 'catalog/plug.jpg'): int
    {
        $pfx = (string) config('catalog.prefix');
        $id = DB::table($pfx . 'product')->insertGetId([
            'model' => 'CC', 'sku' => 'CC-' . uniqid(), 'quantity' => 5, 'price' => 130,
            'status' => 1, 'image' => $image, 'weight' => 0.05, 'length' => 2, 'width' => 2, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now()->subMinute(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $id, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => $description,
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $id;
    }

    private function editCatalog(int $productId, array $words = [], array $product = []): void
    {
        $pfx = (string) config('catalog.prefix');
        if ($words !== []) {
            DB::table($pfx . 'product_description')->where('product_id', $productId)->update($words);
        }
        DB::table($pfx . 'product')->where('product_id', $productId)->update($product + ['date_modified' => now()->addMinute()]);
    }

    public function test_a_new_listing_starts_as_an_exact_copy_of_the_catalog(): void
    {
        $pid = $this->product('Qable Circa TRS Plug', '<p>Gold tip, black body.</p>');

        $listing = LazadaProduct::query()->create(['product_id' => $pid]);

        $this->assertSame('Qable Circa TRS Plug', $listing->item_name);
        $this->assertSame('<p>Gold tip, black body.</p>', $listing->description);
        $this->assertSame(['catalog/plug.jpg'], $listing->image_order);
        $this->assertNotNull($listing->catalog_seen_at);
        $this->assertSame([], $listing->fresh()->catalogChange(), 'a new listing compares the same on every field');
    }

    public function test_a_catalog_edit_does_not_reach_the_listing_and_shows_a_catalog_change(): void
    {
        $pid = $this->product('Plug', '<p>First words.</p>');
        $listing = LazadaProduct::query()->create(['product_id' => $pid]);

        $this->editCatalog($pid, ['description' => '<p>adfasfasfdasfasfasf</p>']);

        $listing->refresh();
        $this->assertSame('<p>First words.</p>', $listing->description, 'the listing keeps its own copy');
        $this->assertSame(['description'], $listing->catalogChange());

        $this->actingAs($this->user(false))->get(route('ext.lazada.products.edit', $pid))
            ->assertOk()
            ->assertSee('data-lcx-open', false)
            ->assertSee('data-lcx-panel', false)
            ->assertSee('adfasfasfdasfasfasf');
    }

    public function test_the_same_values_raise_nothing_even_when_the_catalog_was_saved(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        $listing = LazadaProduct::query()->create(['product_id' => $pid]);
        $seen = $listing->catalog_seen_at;

        $this->editCatalog($pid);

        $this->assertSame([], $listing->fresh()->catalogChange());
        $modified = \Illuminate\Support\Carbon::parse(DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $pid)->value('date_modified'));
        $this->assertTrue($listing->fresh()->catalog_seen_at->gte($modified), 'a listing found the same is marked compared as of that change');
        $this->assertTrue($listing->fresh()->catalog_seen_at->gt($seen));

        $this->actingAs($this->user(false))->get(route('ext.lazada.products.edit', $pid))
            ->assertOk()
            ->assertDontSee('data-lcx-panel', false);
    }

    public function test_ignore_clears_the_catalog_change_and_keeps_the_listing(): void
    {
        $pid = $this->product('Plug', '<p>First words.</p>');
        LazadaProduct::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['name' => 'Plug, renamed']);

        $this->actingAs($this->user(false))
            ->post(route('ext.lazada.products.catalog_change_ignore', $pid), ['back' => '/channels'])
            ->assertRedirect('/channels')
            ->assertSessionHas('status', 'Catalog change ignored.');

        $listing = LazadaProduct::query()->where('product_id', $pid)->first();
        $this->assertSame('Plug', $listing->item_name);
        $this->assertSame([], $listing->catalogChange());

        $this->editCatalog($pid, ['name' => 'Plug, renamed again'], ['date_modified' => now()->addMinutes(2)]);
        $this->assertSame(['title'], $listing->fresh()->catalogChange(), 'the button comes back when the catalog moves again');
    }

    public function test_save_writes_both_sides_and_clears_the_catalog_change(): void
    {
        $pid = $this->product('Plug', '<p>First words.</p>');
        LazadaProduct::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['description' => '<p>Catalog words.</p>']);

        $this->actingAs($this->user(true))->post(route('ext.lazada.products.catalog_change_save', $pid), [
            'back' => '/channels',
            'listing' => [
                'title' => 'Plug for Lazada', 'description' => '<p>Catalog words.</p>', 'description_edited' => '1',
                'price' => '150', 'weight' => '0.05', 'length' => '2', 'width' => '2', 'height' => '1',
            ],
            'catalog' => ['title' => 'Plug, catalog name', 'description' => '<p>Catalog words.</p>', 'description_edited' => '0', 'price' => '150'],
        ])->assertRedirect(route('ext.lazada.products.edit', $pid) . '?back=' . urlencode('/channels'))
            ->assertSessionHas('status', 'Saved.');

        $pfx = (string) config('catalog.prefix');
        $this->assertSame('Plug, catalog name', DB::table($pfx . 'product_description')->where('product_id', $pid)->value('name'));
        $this->assertEquals(150, (float) DB::table($pfx . 'product')->where('product_id', $pid)->value('price'));

        $listing = LazadaProduct::query()->where('product_id', $pid)->first();
        $this->assertSame('Plug for Lazada', $listing->item_name);
        $this->assertSame('<p>Catalog words.</p>', $listing->description);
        $this->assertNull($listing->price, 'a price equal to the catalog\'s at save stays blank, so it keeps following');
        $this->assertSame([], $listing->catalogChange());
    }

    public function test_a_catalog_size_with_decimals_or_a_missing_weight_never_blocks_save(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product')->where('product_id', $pid)->update(['weight' => 0, 'length' => 2.5]);
        LazadaProduct::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['name' => 'Plug v2']);

        $page = $this->actingAs($this->user(true))->get(route('ext.lazada.products.edit', $pid))->assertOk()->getContent();
        $this->assertMatchesRegularExpression('/id="lcx-listing-length"[^>]*value=""[^>]*placeholder="2.5"/s', $page,
            'an empty listing size follows the catalog, whose figure is the placeholder');
        $this->assertMatchesRegularExpression('/id="lcx-listing-price"[^>]*value=""[^>]*placeholder="130"/s', $page);
        $this->assertMatchesRegularExpression('/id="lcx-catalog-length"[^>]*value="2.5"/s', $page);

        $this->post(route('ext.lazada.products.catalog_change_save', $pid), [
            'back' => '/channels',
            'listing' => ['title' => 'Plug v2', 'price' => '', 'weight' => '', 'length' => '', 'width' => '', 'height' => ''],
            'catalog' => ['title' => 'Plug v2', 'price' => '130', 'weight' => '0', 'length' => '2.5', 'width' => '2', 'height' => '1'],
        ])->assertSessionHasNoErrors()
            ->assertRedirect(route('ext.lazada.products.edit', $pid) . '?back=' . urlencode('/channels'))
            ->assertSessionHas('status', 'Saved.');

        $this->assertEquals(0, (float) DB::table($pfx . 'product')->where('product_id', $pid)->value('weight'), 'an empty catalog weight saves as it was');
        $this->assertEquals(2.5, (float) DB::table($pfx . 'product')->where('product_id', $pid)->value('length'));
        $listing = LazadaProduct::query()->where('product_id', $pid)->first();
        $this->assertNull($listing->package_length, 'the listing keeps following the catalog size');
        $this->assertNull($listing->weight);
        $this->assertSame([], $listing->catalogChange());
    }

    public function test_without_catalog_permission_save_leaves_the_catalog_alone(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        LazadaProduct::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['name' => 'Plug v2']);

        $this->actingAs($this->user(false))->post(route('ext.lazada.products.catalog_change_save', $pid), [
            'listing' => ['title' => 'Plug v2'],
            'catalog' => ['title' => 'Somebody else\'s name'],
        ])->assertRedirect();

        $pfx = (string) config('catalog.prefix');
        $this->assertSame('Plug v2', DB::table($pfx . 'product_description')->where('product_id', $pid)->value('name'));
        $this->assertSame('Plug v2', LazadaProduct::query()->where('product_id', $pid)->value('item_name'));
    }

    public function test_the_image_library_opens_above_the_comparison_panel(): void
    {
        $css = (string) file_get_contents(base_path('resources/css/blotter.css'));
        $this->assertMatchesRegularExpression('/\.modal-backdrop\[data-ilp-modal\],\s*#x-confirm-modal\s*\{\s*z-index:\s*70;/', $css,
            'the image library and the confirm dialog must stack above a slide-over panel (z-index 60)');
        $this->assertStringContainsString("z-index: 60;", $css);

        $script = (string) file_get_contents(base_path('resources/js/pages/image-library.js'));
        $this->assertStringContainsString('returnTo = document.activeElement;', $script);
        $this->assertStringContainsString('returnTo.focus();', $script);
    }

    public function test_the_listings_table_button_opens_the_comparison(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        LazadaProduct::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['name' => 'Plug v2']);

        $this->actingAs($this->user(false))->get(route('ext.lazada.products.index'))
            ->assertOk()
            ->assertSee('Catalog change')
            ->assertSee('compare=1', false);
    }
}
