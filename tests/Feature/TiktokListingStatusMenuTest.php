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

    public function test_a_ticked_product_moves_between_groups_from_the_listings_page(): void
    {
        $pid = $this->product('Movable pedal', 'TMV-1');
        TikTokListing::query()->create(['product_id' => $pid]);
        $groupA = \Extensions\tiktok\Models\TikTokProductGroup::create(['name' => 'Fuzz', 'tiktok_category_id' => '900009']);
        $groupB = \Extensions\tiktok\Models\TikTokProductGroup::create(['name' => 'Delay', 'tiktok_category_id' => '900009']);
        $manager = $this->manager();
        $manager->userGroup->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/product_group', 'view_tiktok/product_group'])->pluck('id')->all());
        $listingOnlyGroup = UserGroup::create(['name' => 'Listings without groups']);
        $listingOnlyGroup->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/product', 'view_tiktok/product'])->pluck('id')->all());
        $listingOnly = User::factory()->create(['user_group_id' => $listingOnlyGroup->id]);
        $back = parse_url(route('ext.tiktok.products.index'), PHP_URL_PATH);
        $pivot = fn () => \Illuminate\Support\Facades\DB::table('tiktok_product_group_products')->where('product_id', $pid)->pluck('tiktok_product_group_id')->map(fn ($v) => (int) $v)->all();

        $this->actingAs($listingOnly)->get(route('ext.tiktok.products.index'))->assertOk()->assertDontSee('Move to group');
        $this->actingAs($listingOnly)->post(route('ext.tiktok.product-groups.move'), ['ids' => [$pid], 'group' => $groupA->id, '_return' => $back])->assertRedirect()->assertSessionHas('error');
        $this->assertSame([], $pivot());

        $this->actingAs($manager)->get(route('ext.tiktok.products.index'))->assertOk()->assertSee('Move to group');

        $this->actingAs($manager)->post(route('ext.tiktok.product-groups.move'), ['ids' => [$pid], 'group' => $groupA->id, '_return' => $back])
            ->assertRedirect($back)->assertSessionHas('status', '1 product moved to ' . $groupA->name . '.');
        $this->assertSame([$groupA->id], $pivot());

        $this->actingAs($manager)->post(route('ext.tiktok.product-groups.move'), ['ids' => [$pid], 'group' => $groupB->id, '_return' => $back])
            ->assertRedirect($back);
        $this->assertSame([$groupB->id], $pivot());

        $this->actingAs($manager)->get(route('ext.tiktok.products.index', ['group' => $groupB->id]))->assertOk()->assertSee('Remove from group')->assertSee('Manage group');

        $this->actingAs($manager)->post(route('ext.tiktok.product-groups.move'), ['ids' => [$pid], 'group' => 'none', '_return' => $back])
            ->assertRedirect($back)->assertSessionHas('status', '1 product ungrouped.');
        $this->assertSame([], $pivot());
    }

    public function test_a_whole_group_sends_as_a_run_from_the_listings_page(): void
    {
        \Illuminate\Support\Facades\Http::fake();
        $group = \Extensions\tiktok\Models\TikTokProductGroup::create(['name' => 'Fuzz', 'tiktok_category_id' => '900009']);
        $pids = [$this->product('Run pedal one', 'TRN-1'), $this->product('Run pedal two', 'TRN-2')];
        foreach ($pids as $pid) {
            TikTokListing::query()->create(['product_id' => $pid]);
            \Extensions\tiktok\Models\TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'pending']);
        }
        $manager = $this->manager();
        $manager->userGroup->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/product_group', 'view_tiktok/product_group'])->pluck('id')->all());
        $listingOnlyGroup = UserGroup::create(['name' => 'Listings without groups']);
        $listingOnlyGroup->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/product', 'view_tiktok/product'])->pluck('id')->all());
        $listingOnly = User::factory()->create(['user_group_id' => $listingOnlyGroup->id]);
        $page = route('ext.tiktok.products.index', ['group' => $group->id]);

        $this->actingAs($listingOnly)->get($page)->assertOk()->assertDontSee('Send group');
        $this->actingAs($listingOnly)->post(route('ext.tiktok.product-groups.send_run_begin', ['id' => $group->id]))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, \App\Models\AutomationRun::query()->count());

        $this->actingAs($manager)->get($page)->assertOk()->assertSee('Send group')
            ->assertSee('Send all 2 products in Fuzz to TikTok Shop on group settings, rather than their own?');

        $answer = $this->actingAs($manager)->postJson(route('ext.tiktok.product-groups.send_run_begin', ['id' => $group->id]))->assertOk()->json();
        $this->assertSame('done', $answer['run']['status']);
        $this->assertSame(2, $answer['run']['total']);
        $this->assertSame('2 products: 0 ok, 2 failed.', $answer['outcome']);
        $this->assertSame(['error', 'error'], \Extensions\tiktok\Models\TikTokProductGroupProduct::query()->where('tiktok_product_group_id', $group->id)->orderBy('product_id')->pluck('sync_status')->all());
    }
}
