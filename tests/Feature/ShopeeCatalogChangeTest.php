<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopeeCatalogChangeTest extends TestCase
{
    use RefreshDatabase;

    private int $groupSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002, 'store_name' => 'Main shop',
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);

        $this->app->instance(ShopeeClient::class, new class extends ShopeeClient {
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                return ['ok' => false, 'status' => 500, 'body' => ['message' => 'not faked']];
            }
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                return ['ok' => false, 'status' => 500, 'body' => ['message' => 'not faked']];
            }
        });
    }

    private function user(bool $catalog): User
    {
        $keys = ['manage_shopee/product', 'view_shopee/product'];
        if ($catalog) {
            $keys[] = 'manage_catalog/product';
        }
        $group = UserGroup::create(['name' => 'Shopee catalog change group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function product(string $name, string $description): int
    {
        $pfx = (string) config('catalog.prefix');
        $id = DB::table($pfx . 'product')->insertGetId([
            'model' => 'SCC', 'sku' => 'SCC-' . uniqid(), 'quantity' => 5, 'price' => 130,
            'status' => 1, 'image' => 'catalog/plug.jpg', 'weight' => 0.05, 'length' => 2, 'width' => 2, 'height' => 1,
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

    private function editCatalog(int $productId, array $words = []): void
    {
        $pfx = (string) config('catalog.prefix');
        if ($words !== []) {
            DB::table($pfx . 'product_description')->where('product_id', $productId)->update($words);
        }
        DB::table($pfx . 'product')->where('product_id', $productId)->update(['date_modified' => now()->addMinute()]);
    }

    public function test_the_listing_page_shows_the_catalog_change_when_the_catalog_moved(): void
    {
        $pid = $this->product('Plug', '<p>First words.</p>');
        $listing = ShopeeListing::query()->create(['product_id' => $pid]);
        $this->assertSame('Plug', $listing->item_name, 'a new listing starts as a copy');

        $this->editCatalog($pid, ['description' => '<p>Catalog words now.</p>']);
        $this->assertSame(['description'], $listing->fresh()->catalogChange());

        $this->actingAs($this->user(false))->get(route('ext.shopee.listings.edit', $pid))
            ->assertOk()
            ->assertSee('data-lcx-open', false)
            ->assertSee('data-lcx-panel', false)
            ->assertSee('Catalog words now.')
            ->assertSee('Main shop');
    }

    public function test_the_same_values_raise_nothing_even_when_the_catalog_was_saved(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        ShopeeListing::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid);

        $this->actingAs($this->user(false))->get(route('ext.shopee.listings.edit', $pid))
            ->assertOk()
            ->assertDontSee('data-lcx-open', false)
            ->assertDontSee('data-lcx-panel', false);
    }

    public function test_ignore_clears_the_catalog_change_and_keeps_the_listing(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        ShopeeListing::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['name' => 'Plug, renamed']);

        $this->actingAs($this->user(false))
            ->post(route('ext.shopee.listings.catalog_change_ignore', $pid), ['back' => '/channels'])
            ->assertRedirect('/channels')
            ->assertSessionHas('status', 'Catalog change ignored.');

        $listing = ShopeeListing::query()->where('product_id', $pid)->first();
        $this->assertSame('Plug', $listing->item_name);
        $this->assertSame([], $listing->catalogChange());
    }

    public function test_save_writes_both_sides_and_clears_the_catalog_change(): void
    {
        $pid = $this->product('Plug', '<p>First words.</p>');
        ShopeeListing::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['description' => '<p>Catalog words.</p>']);

        $this->actingAs($this->user(true))->post(route('ext.shopee.listings.catalog_change_save', $pid), [
            'back' => '/channels',
            'listing' => ['title' => 'Plug for Shopee', 'description' => '<p>Catalog words.</p>', 'description_edited' => '1'],
            'catalog' => ['title' => 'Plug, catalog name', 'description_edited' => '0'],
        ])->assertRedirect(route('ext.shopee.listings.edit', $pid) . '?back=' . urlencode('/channels'))
            ->assertSessionHas('status', 'Saved.');

        $pfx = (string) config('catalog.prefix');
        $this->assertSame('Plug, catalog name', DB::table($pfx . 'product_description')->where('product_id', $pid)->value('name'));

        $listing = ShopeeListing::query()->where('product_id', $pid)->first();
        $this->assertSame('Plug for Shopee', $listing->item_name);
        $this->assertSame('<p>Catalog words.</p>', $listing->description);
        $this->assertSame([], $listing->catalogChange());
    }

    public function test_the_listings_table_button_opens_the_comparison(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        ShopeeListing::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['name' => 'Plug v2']);

        $this->actingAs($this->user(false))->get(route('ext.shopee.products.index'))
            ->assertOk()
            ->assertSee('Catalog change')
            ->assertSee('compare=1', false);
    }

    public function test_the_group_page_shows_the_same_catalog_change_as_the_listings_table(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        ShopeeListing::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['name' => 'Plug v2']);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create(['name' => 'Plugs', 'shopee_category_id' => 100013, 'logistic_ids' => [41003]]);
        DB::table('shopee_product_group_products')->insert(['shopee_product_group_id' => $group->id, 'product_id' => $pid]);

        $manager = $this->user(false);
        $manager->userGroup->permissions()->attach(Permission::whereIn('key', ['manage_shopee/product_group', 'view_shopee/product_group'])->pluck('id')->all());

        $this->actingAs($manager)->get(route('ext.shopee.product-groups.products', $group->id))
            ->assertOk()
            ->assertSee('Catalog change')
            ->assertSee(e(route('ext.shopee.listings.edit', [$pid, 'compare' => 1])), false);
    }

    public function test_pushing_unchanged_words_from_the_review_keeps_the_stored_description(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p><p><img src="/image/catalog/plug.jpg"></p>');
        $listing = ShopeeListing::query()->create(['product_id' => $pid]);
        $stored = $listing->description;
        $this->assertStringContainsString('<img', (string) $stored);

        $this->actingAs($this->user(false))
            ->post(route('ext.shopee.products.push_direct', $pid), ['description' => 'Words.']);
        $this->assertSame($stored, $listing->fresh()->description);
        $this->assertSame('Plug', $listing->fresh()->item_name, 'a push without the title leaves it alone');

        $this->actingAs($this->user(false))
            ->post(route('ext.shopee.products.push_direct', $pid), ['description' => 'Other words.']);
        $this->assertSame('Other words.', $listing->fresh()->description);
    }

    public function test_the_listing_form_saves_the_markup_it_holds(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        $listing = ShopeeListing::query()->create(['product_id' => $pid]);

        $this->actingAs($this->user(false))
            ->post(route('ext.shopee.listings.update', $pid), ['description' => '<p>Own words.</p><p><img src="/image/catalog/plug.jpg"></p>'])
            ->assertSessionHasNoErrors();

        $fresh = (string) $listing->fresh()->description;
        $this->assertStringContainsString('<p>Own words.</p>', $fresh);
        $this->assertStringContainsString('<img', $fresh, 'a picture arranged in the box survives the save');
    }
}
