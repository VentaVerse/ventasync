<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokCategoryTemplate;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokListingStates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The status menu on a TikTok Shop store's Listings page: the tree, the two
 * switches that narrow it, the store's tab row it combines with, and the
 * store's item id on the product's own line.
 */
class TiktokListingStatusMenuTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3),
        ]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok menu desk']);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/product', 'view_tiktok/product'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function product(string $name, string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $id = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100, 'status' => 1, 'image' => '',
            'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now()->subMinute(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $id, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $id;
    }

    private function store(): void
    {
        $listedOk = $this->product('Listed and fine', 'TMN-1');
        TikTokListing::query()->create(['product_id' => $listedOk, 'tiktok_product_id' => 'tt-9001', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);

        $listedFailed = $this->product('Listed but failed', 'TMN-2');
        TikTokListing::query()->create(['product_id' => $listedFailed, 'tiktok_product_id' => 'tt-9002', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);
        $this->app->make(TikTokListingStates::class)->recordOutcome($listedFailed, 'TikTok Shop refused the update.');

        $notListed = $this->product('Not on TikTok yet', 'TMN-3');
        TikTokListing::query()->create(['product_id' => $notListed]);
    }

    public function test_the_menu_sits_beside_the_tabs_and_push_failed_narrows_listed(): void
    {
        $this->store();
        $user = $this->manager();

        $page = $this->actingAs($user)->get(route('ext.tiktok.products.index'))->assertOk();
        $page->assertSee('Product group')->assertSee('Filters')->assertSee('data-lsm', false);
        $page->assertSee('All products')->assertSee('class="lss"', false);
        $page->assertDontSee('name="sync_status"', false);
        $page->assertSee('class="x-segment-bar"', false);
        $page->assertDontSee('cc-col-chanid', false);
        $page->assertSee('TikTok tt-9001');
        $page->assertDontSee('cc-head-refresh', false);
        $page->assertSee('x-segment-group__refresh', false);

        $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => 'uploaded']))
            ->assertOk()->assertSee('Listed and fine')->assertSee('Listed but failed')->assertDontSee('Not on TikTok yet');

        $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => 'uploaded', 'failed' => 1]))
            ->assertOk()->assertSee('Listed but failed')->assertDontSee('Listed and fine')->assertDontSee('Not on TikTok yet')
            ->assertSee('Live on TikTok Shop · Push failed');
    }

    public function test_the_older_links_still_land_where_they_did(): void
    {
        $this->store();
        $user = $this->manager();

        $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => 'listed']))
            ->assertOk()->assertSee('Listed and fine')->assertDontSee('Not on TikTok yet')->assertSee('products match: Live on TikTok Shop', false);

        $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => 'not_listed']))
            ->assertOk()->assertSee('Not on TikTok yet')->assertDontSee('Listed and fine');

        $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => 'error']))
            ->assertOk()->assertSee('Listed but failed')->assertDontSee('Listed and fine')->assertSee('matches: Push failed', false);
    }

    /** A retired eligibility address lands on its half, and the store's tab row still counts under that choice. */
    public function test_a_retired_eligibility_link_lands_on_its_half(): void
    {
        TikTokCategory::create(['id' => '900009', 'name' => 'Guitar Accessories', 'parent_id' => null, 'is_leaf' => true]);
        TikTokCategoryTemplate::create(['category_id' => '900009', 'attributes' => [], 'fetched_at' => now()]);

        $complete = $this->product('Listed and complete', 'TMN-4');
        TikTokListing::query()->create(['product_id' => $complete, 'tiktok_product_id' => 'tt-9004', 'tiktok_category_id' => '900009', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);
        $gap = $this->product('Listed without a category', 'TMN-5');
        TikTokListing::query()->create(['product_id' => $gap, 'tiktok_product_id' => 'tt-9005', 'live_status' => 'ACTIVATE', 'live_checked_at' => now()]);
        $unlisted = $this->product('Not on TikTok yet', 'TMN-3');
        TikTokListing::query()->create(['product_id' => $unlisted]);
        $user = $this->manager();

        // Both retired rungs belonged to Listed, so both land there: the live
        // products, complete or not, and never the one that was never pushed.
        foreach (['listed_not_ready', 'listed_ready'] as $retired) {
            $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => $retired]))
                ->assertOk()->assertSee('Listed without a category')->assertSee('Listed and complete')->assertDontSee('Not on TikTok yet');
        }

        $page = $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => 'uploaded']))->assertOk();
        $page->assertSee('class="x-segment-bar"', false);
        $this->assertMatchesRegularExpression('/tiktok_tab=live"[^>]*>\s*<span>Live<\/span>\s*<span class="x-segment__count">2</s', $page->getContent(), 'the tab counts only what the menu chose');

        $this->actingAs($user)->get(route('ext.tiktok.products.index', ['sync_status' => 'uploaded', 'tiktok_tab' => 'deactivated']))
            ->assertOk()->assertDontSee('Listed without a category');
    }
}
