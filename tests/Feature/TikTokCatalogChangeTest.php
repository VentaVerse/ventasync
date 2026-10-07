<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\TiktokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TikTokCatalogChangeTest extends TestCase
{
    use RefreshDatabase;

    private int $groupSeq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(TiktokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3),
        ]);
    }

    private function user(): User
    {
        $group = UserGroup::create(['name' => 'TikTok catalog change group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', [
            'view_tiktok/product', 'manage_tiktok/product', 'view_tiktok/listing', 'manage_tiktok/listing',
        ])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function product(string $name, string $description): int
    {
        $pfx = (string) config('catalog.prefix');
        $id = DB::table($pfx . 'product')->insertGetId([
            'model' => 'TCC', 'sku' => 'TCC-' . uniqid(), 'quantity' => 5, 'price' => 130,
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

    private function editCatalog(int $productId, array $words): void
    {
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product_description')->where('product_id', $productId)->update($words);
        DB::table($pfx . 'product')->where('product_id', $productId)->update(['date_modified' => now()->addMinute()]);
    }

    public function test_the_listing_page_and_the_table_show_a_catalog_change(): void
    {
        $pid = $this->product('Plug', '<p>First words.</p>');
        $listing = TikTokListing::query()->create(['product_id' => $pid]);
        $this->assertSame('Plug', $listing->title, 'a new listing starts as a copy');

        $this->editCatalog($pid, ['description' => '<p>Catalog words.</p>']);

        $this->assertSame('<p>First words.</p>', $listing->fresh()->description, 'the listing keeps its own copy');
        $this->assertSame(['description'], $listing->fresh()->catalogChange());

        $user = $this->user();
        $this->actingAs($user)->get(route('ext.tiktok.listings.edit', $pid))
            ->assertOk()
            ->assertSee('data-lcx-open', false)
            ->assertSee('data-lcx-panel', false)
            ->assertSee('Catalog words.');

        $this->actingAs($user)->get(route('ext.tiktok.products.index'))
            ->assertOk()
            ->assertSee('Catalog change')
            ->assertSee('compare=1', false);
    }

    public function test_ignore_clears_the_catalog_change_and_keeps_the_listing(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');
        TikTokListing::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['name' => 'Plug, renamed']);

        $this->actingAs($this->user())
            ->post(route('ext.tiktok.listings.catalog_change_ignore', $pid), ['back' => '/channels'])
            ->assertRedirect('/channels')
            ->assertSessionHas('status', 'Catalog change ignored.');

        $listing = TikTokListing::query()->where('product_id', $pid)->first();
        $this->assertSame('Plug', $listing->title);
        $this->assertSame([], $listing->catalogChange());

        $this->actingAs($this->user())->get(route('ext.tiktok.listings.edit', $pid))
            ->assertOk()
            ->assertDontSee('data-lcx-panel', false);
    }

    public function test_save_writes_the_listing_and_clears_the_catalog_change(): void
    {
        $pid = $this->product('Plug', '<p>First words.</p>');
        TikTokListing::query()->create(['product_id' => $pid]);
        $this->editCatalog($pid, ['name' => 'Plug v2']);

        $this->actingAs($this->user())->post(route('ext.tiktok.listings.catalog_change_save', $pid), [
            'back' => '/channels',
            'listing' => ['title' => 'Plug v2'],
            'catalog' => ['title' => 'Not allowed'],
        ])->assertRedirect(route('ext.tiktok.listings.edit', $pid) . '?back=' . urlencode('/channels'))
            ->assertSessionHas('status', 'Saved.');

        $pfx = (string) config('catalog.prefix');
        $this->assertSame('Plug v2', DB::table($pfx . 'product_description')->where('product_id', $pid)->value('name'), 'no catalog permission, no catalog write');

        $listing = TikTokListing::query()->where('product_id', $pid)->first();
        $this->assertSame('Plug v2', $listing->title);
        $this->assertSame([], $listing->catalogChange());
    }

    public function test_a_product_without_a_listing_has_nothing_to_compare(): void
    {
        $pid = $this->product('Plug', '<p>Words.</p>');

        $this->actingAs($this->user())
            ->post(route('ext.tiktok.listings.catalog_change_ignore', $pid))
            ->assertNotFound();
    }
}
