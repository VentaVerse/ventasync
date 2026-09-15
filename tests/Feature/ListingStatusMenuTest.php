<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Integrations\Listings\StatusMenu;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * The status menu down the left of a store's Listings page: Where it stands
 * as one tree, and Needs you as two switches that narrow it. Pinned on
 * Lazada; every channel builds its menu through the same StatusMenu.
 */
class ListingStatusMenuTest extends TestCase
{
    use RefreshDatabase;

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

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Menu desk']);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_lazada/product', 'view_lazada/product'])->pluck('id')->all());

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

    /** @return array{listedOk: int, listedFailed: int, notListed: int} */
    private function store(): array
    {
        $listedOk = $this->product('Listed and fine', 'MN-1');
        LazadaProduct::query()->create(['product_id' => $listedOk, 'lazada_item_id' => '9001', 'live_status' => 'active', 'live_checked_at' => now()]);

        $listedFailed = $this->product('Listed but failed', 'MN-2');
        LazadaProduct::query()->create(['product_id' => $listedFailed, 'lazada_item_id' => '9002', 'live_status' => 'active', 'live_checked_at' => now(),
            'last_push_error' => 'Lazada refused the update', 'last_push_failed_at' => now()]);

        $notListed = $this->product('Not on Lazada yet', 'MN-3');
        LazadaProduct::query()->create(['product_id' => $notListed]);

        return compact('listedOk', 'listedFailed', 'notListed');
    }

    public function test_the_menu_counts_each_place_and_the_list_follows_the_choice(): void
    {
        $ids = $this->store();
        $user = $this->manager();

        $page = $this->actingAs($user)->get(route('ext.lazada.products.index'))->assertOk();
        $page->assertSee('Where it stands')->assertSee('Needs you')->assertSee('data-lsm', false);
        $page->assertDontSee('name="sync_status"', false);

        $this->actingAs($user)->get(route('ext.lazada.products.index', ['sync_status' => 'uploaded']))
            ->assertOk()->assertSee('Listed and fine')->assertSee('Listed but failed')->assertDontSee('Not on Lazada yet');

        $this->actingAs($user)->get(route('ext.lazada.products.index', ['sync_status' => 'uploaded', 'failed' => 1]))
            ->assertOk()->assertSee('Listed but failed')->assertDontSee('Listed and fine')->assertDontSee('Not on Lazada yet')
            ->assertSee('Listed · Push failed');
    }

    public function test_an_old_error_link_reads_as_push_failed_switched_on(): void
    {
        $this->store();

        $this->actingAs($this->manager())->get(route('ext.lazada.products.index', ['sync_status' => 'error']))
            ->assertOk()->assertSee('Listed but failed')->assertDontSee('Listed and fine')->assertSee('matches: Push failed', false);
    }

    public function test_catalog_change_narrows_to_the_listings_showing_the_button(): void
    {
        $ids = $this->store();
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product_description')->where('product_id', $ids['listedOk'])->update(['name' => 'Listed and fine, renamed']);
        DB::table($pfx . 'product')->where('product_id', $ids['listedOk'])->update(['date_modified' => now()->addMinute()]);

        $this->actingAs($this->manager())->get(route('ext.lazada.products.index', ['change' => 1]))
            ->assertOk()->assertSee('compare=1', false)->assertDontSee('Listed but failed')->assertDontSee('Not on Lazada yet');
    }

    public function test_the_tree_keeps_every_other_filter_and_a_store_status_sits_under_listed(): void
    {
        $menu = StatusMenu::build([
            'url' => fn (array $p) => '/listings?' . http_build_query($p),
            'query' => ['q' => 'cable', 'page' => 3, 'sync_status' => 'error'],
            'sync' => 'error', 'failed' => false, 'change' => false,
            'counts' => ['all' => 10, 'not_uploaded' => 4, 'ready' => 3, 'not_ready' => 1, 'uploaded' => 6, 'failed' => 2, 'change' => 1],
            'market' => ['param' => 'lazada_tab', 'current' => 'all', 'store' => 'Lazada', 'defs' => ['active' => 'Active'], 'counts' => ['active' => 5], 'meta' => 'not refreshed yet'],
        ]);

        $this->assertSame('Push failed', $menu['label']);
        $this->assertTrue($menu['flags'][0]['on'], 'an old error link switches Push failed on');
        [$all, $notListed, $listed] = $menu['tree'];
        $this->assertTrue($all['active']);
        $this->assertStringContainsString('q=cable', $listed['url'], 'the search stays');
        $this->assertStringNotContainsString('page=', $listed['url'], 'a new choice starts at the first page');
        $this->assertStringContainsString('failed=1', $listed['url'], 'the switch stays on when the tree moves');
        $this->assertSame(['Ready to push', 'Needs details'], array_column($notListed['kids'], 'label'));
        $this->assertSame('On Lazada, not refreshed yet', $listed['meta']);
        $this->assertStringContainsString('sync_status=uploaded', $listed['kids'][0]['url']);
        $this->assertStringContainsString('lazada_tab=active', $listed['kids'][0]['url']);
        $this->assertStringNotContainsString('failed=1', $menu['flags'][0]['url'], 'pressing an on switch turns it off');
    }
}
