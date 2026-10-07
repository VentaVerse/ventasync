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
        $page->assertSee('Product group')->assertSee('Filters')->assertSee('data-lsm', false);
        $page->assertSee('All products')->assertSee('Ungrouped');
        $page->assertSee('class="lss"', false);
        $page->assertDontSee('name="sync_status"', false);

        $this->actingAs($user)->get(route('ext.lazada.products.index', ['sync_status' => 'uploaded']))
            ->assertOk()->assertSee('Listed and fine')->assertSee('Listed but failed')->assertDontSee('Not on Lazada yet');

        $this->actingAs($user)->get(route('ext.lazada.products.index', ['sync_status' => 'uploaded', 'failed' => 1]))
            ->assertOk()->assertSee('Listed but failed')->assertDontSee('Listed and fine')->assertDontSee('Not on Lazada yet')
            ->assertSee('Live on Lazada · Push failed');
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

    public function test_the_rail_keeps_every_other_filter_and_leads_with_the_biggest_group(): void
    {
        $menu = StatusMenu::build([
            'url' => fn (array $p) => '/listings?' . http_build_query($p),
            'query' => ['q' => 'cable', 'page' => 3, 'sync_status' => 'error', 'lazada_tab' => 'active'],
            'sync' => 'error', 'failed' => false, 'change' => false,
            'group' => 'all', 'store' => 'Lazada',
            'counts' => ['all' => 10, 'store' => 10, 'not_uploaded' => 4, 'uploaded' => 6, 'failed' => 2, 'change' => 1],
            'groups' => [
                ['id' => '7', 'name' => 'Cables', 'count' => 2],
                ['id' => '4', 'name' => 'Mosky', 'count' => 6],
                ['id' => 'none', 'name' => StatusMenu::UNGROUPED, 'count' => 2],
            ],
        ]);

        $this->assertSame('Push failed', $menu['label']);
        $this->assertTrue($menu['flags'][0]['on'], 'an old error link switches Push failed on');

        $this->assertSame(StatusMenu::EVERYTHING, $menu['everything']['label']);
        $this->assertSame(10, $menu['everything']['count']);

        $this->assertSame(
            ['Mosky', 'Cables', StatusMenu::UNGROUPED],
            array_column($menu['groups'], 'label')
        );
        $this->assertSame([6, 2, 2], array_column($menu['groups'], 'count'));

        $big = StatusMenu::build([
            'url' => fn (array $p) => '/listings?' . http_build_query($p),
            'query' => [], 'sync' => 'all', 'failed' => false, 'change' => false,
            'group' => 'all', 'store' => 'Lazada',
            'counts' => ['all' => 99, 'store' => 99],
            'groups' => [
                ['id' => 'none', 'name' => StatusMenu::UNGROUPED, 'count' => 90],
                ['id' => '4', 'name' => 'Mosky', 'count' => 9],
            ],
        ]);
        $this->assertSame(
            ['Mosky', StatusMenu::UNGROUPED],
            array_column($big['groups'], 'label')
        );
        $this->assertTrue($menu['everything']['active'], 'no group chosen means the whole store is');

        $mosky = $menu['groups'][0];
        $this->assertStringContainsString('q=cable', $mosky['url'], 'the search stays');
        $this->assertStringContainsString('lazada_tab=active', $mosky['url'], 'the store tab stays chosen');
        $this->assertStringNotContainsString('page=', $mosky['url'], 'a new choice starts at the first page');
        $this->assertStringContainsString('failed=1', $mosky['url'], 'the switch stays on when the rail moves');
        $this->assertStringContainsString('group=4', $mosky['url']);

        $onLive = StatusMenu::build([
            'url' => fn (array $p) => '/listings?' . http_build_query($p),
            'query' => ['sync_status' => 'uploaded', 'group' => '4', 'q' => 'cable'],
            'sync' => 'uploaded', 'failed' => false, 'change' => false,
            'group' => '4', 'store' => 'Lazada',
            'counts' => ['all' => 10, 'store' => 10],
            'groups' => [['id' => '7', 'name' => 'Cables', 'count' => 2]],
        ]);
        $this->assertStringNotContainsString('sync_status', $onLive['groups'][0]['url'], 'a group opens on All');
        $this->assertStringNotContainsString('sync_status', $onLive['everything']['url'], 'All products opens on All');
        $this->assertStringContainsString('q=cable', $onLive['groups'][0]['url'], 'the search still rides along');

        $this->assertSame(['All', 'Not live on Lazada', 'Live on Lazada'], array_column($menu['stand'], 'label'));
        $this->assertSame([10, 4, 6], array_column($menu['stand'], 'count'));
        $this->assertStringContainsString('sync_status=uploaded', $menu['stand'][2]['url']);
        $this->assertStringNotContainsString('failed=1', $menu['flags'][0]['url'], 'pressing an on switch turns it off');
    }

    public function test_a_retired_eligibility_link_folds_onto_its_half(): void
    {
        $this->assertSame('uploaded', StatusMenu::stand('listed_ready'));
        $this->assertSame('uploaded', StatusMenu::stand('listed_not_ready'));
        $this->assertSame('not_uploaded', StatusMenu::stand('ready'));
        $this->assertSame('not_uploaded', StatusMenu::stand('not_ready'));
        $this->assertSame('all', StatusMenu::stand('something else'));

        $this->store();
        $this->actingAs($this->manager())->get(route('ext.lazada.products.index', ['sync_status' => 'listed_not_ready']))
            ->assertOk()->assertSee('Listed and fine')->assertDontSee('Not on Lazada yet');
    }

    public function test_a_group_narrows_the_list_and_the_segment_splits_it(): void
    {
        $ids = $this->store();
        $group = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Cables', 'lazada_category_id' => 4321]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id,
            'product_id' => $ids['listedOk'],
            'lazada_product_id' => LazadaProduct::query()->where('product_id', $ids['listedOk'])->value('id'),
            'sync_status' => 'pending',
        ]);
        $user = $this->manager();

        $all = $this->actingAs($user)->get(route('ext.lazada.products.index', ['sync_status' => 'uploaded']))->assertOk();
        $all->assertSee('Product group: Cables');
        $this->assertMatchesRegularExpression('/<a class="co-item__var" href="[^"]*group=' . $group->id . '"[^>]*>Product group: Cables<\/a>/', $all->getContent());
        $this->assertDoesNotMatchRegularExpression('/href="[^"]*sync_status[^"]*"[^>]*>Product group:/', $all->getContent(), 'the group opens on All');

        $page = $this->actingAs($user)->get(route('ext.lazada.products.index', ['group' => $group->id]))->assertOk();
        $page->assertSee('Listed and fine')->assertDontSee('Not on Lazada yet')->assertSee('Cables');
        $page->assertDontSee('Product group: ');

        $this->actingAs($user)->get(route('ext.lazada.products.index', ['group' => 'none']))
            ->assertOk()->assertSee('Not on Lazada yet')->assertDontSee('Listed and fine');

        $this->actingAs($user)->get(route('ext.lazada.products.index', ['group' => $group->id, 'lazada_tab' => 'inactive']))
            ->assertOk()->assertSee('class="x-segment-bar"', false);
    }

    public function test_a_ticked_product_moves_between_groups_from_the_listings_page(): void
    {
        $ids = $this->store();
        $pid = $ids['notListed'];
        $groupA = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Fuzz', 'lazada_category_id' => 4321]);
        $groupB = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Delay', 'lazada_category_id' => 4321]);
        $manager = $this->manager();
        $manager->userGroup->permissions()->attach(Permission::whereIn('key', ['manage_lazada/product_group', 'view_lazada/product_group'])->pluck('id')->all());
        $listingOnlyGroup = UserGroup::create(['name' => 'Listings without groups']);
        $listingOnlyGroup->permissions()->attach(Permission::whereIn('key', ['manage_lazada/product', 'view_lazada/product'])->pluck('id')->all());
        $listingOnly = User::factory()->create(['user_group_id' => $listingOnlyGroup->id]);
        $back = parse_url(route('ext.lazada.products.index'), PHP_URL_PATH);
        $pivot = fn () => \Illuminate\Support\Facades\DB::table('lazada_product_group_products')->where('product_id', $pid)->pluck('lazada_product_group_id')->map(fn ($v) => (int) $v)->all();

        $this->actingAs($listingOnly)->get(route('ext.lazada.products.index'))->assertOk()->assertDontSee('Move to group');
        $this->actingAs($listingOnly)->post(route('ext.lazada.product-groups.move'), ['ids' => [$pid], 'group' => $groupA->id, '_return' => $back])->assertRedirect()->assertSessionHas('error');
        $this->assertSame([], $pivot());

        $this->actingAs($manager)->get(route('ext.lazada.products.index'))->assertOk()->assertSee('Move to group');

        $this->actingAs($manager)->post(route('ext.lazada.product-groups.move'), ['ids' => [$pid], 'group' => $groupA->id, '_return' => $back])
            ->assertRedirect($back)->assertSessionHas('status', '1 product moved to ' . $groupA->name . '.');
        $this->assertSame([$groupA->id], $pivot());

        $this->actingAs($manager)->post(route('ext.lazada.product-groups.move'), ['ids' => [$pid], 'group' => $groupB->id, '_return' => $back])
            ->assertRedirect($back);
        $this->assertSame([$groupB->id], $pivot());

        $this->actingAs($manager)->get(route('ext.lazada.products.index', ['group' => $groupB->id]))->assertOk()->assertSee('Remove from group')->assertSee('Manage group');

        $this->actingAs($manager)->post(route('ext.lazada.product-groups.move'), ['ids' => [$pid], 'group' => 'none', '_return' => $back])
            ->assertRedirect($back)->assertSessionHas('status', '1 product ungrouped.');
        $this->assertSame([], $pivot());
    }

    public function test_a_whole_group_sends_as_a_run_from_the_listings_page(): void
    {
        $ids = $this->store();
        $group = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Fuzz']);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'product_id' => $ids['notListed'],
            'lazada_product_id' => LazadaProduct::query()->where('product_id', $ids['notListed'])->value('id'),
        ]);
        $manager = $this->manager();
        $manager->userGroup->permissions()->attach(Permission::whereIn('key', ['manage_lazada/product_group', 'view_lazada/product_group'])->pluck('id')->all());
        $listingOnlyGroup = UserGroup::create(['name' => 'Listings without groups']);
        $listingOnlyGroup->permissions()->attach(Permission::whereIn('key', ['manage_lazada/product', 'view_lazada/product'])->pluck('id')->all());
        $listingOnly = User::factory()->create(['user_group_id' => $listingOnlyGroup->id]);
        $page = route('ext.lazada.products.index', ['group' => $group->id]);

        $this->actingAs($listingOnly)->get($page)->assertOk()->assertDontSee('Send group');
        $this->actingAs($listingOnly)->post(route('ext.lazada.product-groups.send_run_begin', ['id' => $group->id]))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, \App\Models\AutomationRun::query()->count());

        $this->actingAs($manager)->get($page)->assertOk()->assertSee('Send group')
            ->assertSee('Send all 1 product in Fuzz to Lazada on group settings, rather than their own?');

        $answer = $this->actingAs($manager)->postJson(route('ext.lazada.product-groups.send_run_begin', ['id' => $group->id]))->assertOk()->json();
        $this->assertSame('failed', $answer['run']['status']);
        $this->assertSame(1, $answer['run']['total']);
        $this->assertSame('This product group has no Lazada category configured. Set a category first.', $answer['outcome']);
        $this->assertSame('group:' . $group->id, \App\Models\AutomationRun::query()->value('subject'));
    }
}
