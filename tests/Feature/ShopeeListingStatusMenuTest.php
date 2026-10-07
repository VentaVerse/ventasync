<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeListingStates;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopeeListingStatusMenuTest extends TestCase
{
    use RefreshDatabase;

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
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Menu desk']);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product'])->pluck('id')->all());

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
        ShopeeListing::query()->create(['product_id' => $id]);

        return $id;
    }

    private function store(): void
    {
        $listedOk = $this->product('Listed and fine', 'SMN-1');
        ShopeeProductLink::query()->create(['product_id' => $listedOk, 'shopee_item_id' => 9001, 'sku' => 'SMN-1', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);

        $listedFailed = $this->product('Listed but failed', 'SMN-2');
        ShopeeProductLink::query()->create(['product_id' => $listedFailed, 'shopee_item_id' => 9002, 'sku' => 'SMN-2', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        app(ShopeeListingStates::class)->recordOutcome($listedFailed, 'Push failed: Category is invalid.');

        $this->product('Not on Shopee yet', 'SMN-3');
    }

    public function test_the_menu_sits_beside_the_tab_row_and_push_failed_combines_with_listed(): void
    {
        $this->store();
        $user = $this->manager();

        $page = $this->actingAs($user)->get(route('ext.shopee.products.index'))->assertOk();
        $page->assertSee('Product group')->assertSee('Filters')->assertSee('data-lsm', false);
        $page->assertSee('All products')->assertSee('class="lss"', false);
        $page->assertDontSee('name="sync_status"', false);
        $page->assertSee('class="x-segment-bar"', false);
        $page->assertSee('x-segment__item', false);
        $page->assertDontSee('>Shopee ID<', false);
        $page->assertDontSee('cc-head-refresh', false);
        $page->assertSee('x-segment-group__refresh', false);
        $this->assertMatchesRegularExpression('/class="co-item__sku lsm-chanid"[^>]*>Shopee 9001</', $page->getContent(), 'the item id rides the product line');
        $page->assertSee('shopee_tab=live', false);
        $page->assertSee('lsm-n lsm-n--bad">1<', false);

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'uploaded']))
            ->assertOk()->assertSee('Listed and fine')->assertSee('Listed but failed')->assertDontSee('Not on Shopee yet');

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'uploaded', 'failed' => 1]))
            ->assertOk()->assertSee('Listed but failed')->assertDontSee('Listed and fine')->assertDontSee('Not on Shopee yet')
            ->assertSee('Live on Shopee · Push failed');
    }

    public function test_the_pages_older_links_still_land_where_they_did(): void
    {
        $this->store();
        $user = $this->manager();

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'listed']))
            ->assertOk()->assertSee('Listed and fine')->assertDontSee('Not on Shopee yet');

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'not_listed']))
            ->assertOk()->assertSee('Not on Shopee yet')->assertDontSee('Listed and fine');

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'error']))
            ->assertOk()->assertSee('Listed but failed')->assertDontSee('Listed and fine')->assertSee('matches: Push failed', false);

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'listed_not_ready']))
            ->assertOk()->assertSee('Listed and fine')->assertDontSee('Not on Shopee yet');

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['sync_status' => 'not_ready']))
            ->assertOk()->assertSee('Not on Shopee yet')->assertDontSee('Listed and fine');
    }

    public function test_a_ticked_product_moves_between_groups_from_the_listings_page(): void
    {
        $pid = $this->product('Movable pedal', 'SMV-1');
        $groupA = \Extensions\shopee\Models\ShopeeProductGroup::create(['name' => 'Fuzz', 'shopee_category_id' => 100013, 'logistic_ids' => [8003]]);
        $groupB = \Extensions\shopee\Models\ShopeeProductGroup::create(['name' => 'Delay', 'shopee_category_id' => 100013, 'logistic_ids' => [8003]]);
        $manager = $this->manager();
        $manager->userGroup->permissions()->attach(Permission::whereIn('key', ['manage_shopee/product_group', 'view_shopee/product_group'])->pluck('id')->all());
        $listingOnlyGroup = UserGroup::create(['name' => 'Listings without groups']);
        $listingOnlyGroup->permissions()->attach(Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product'])->pluck('id')->all());
        $listingOnly = User::factory()->create(['user_group_id' => $listingOnlyGroup->id]);
        $back = parse_url(route('ext.shopee.products.index'), PHP_URL_PATH);
        $pivot = fn () => \Illuminate\Support\Facades\DB::table('shopee_product_group_products')->where('product_id', $pid)->pluck('shopee_product_group_id')->map(fn ($v) => (int) $v)->all();

        $this->actingAs($listingOnly)->get(route('ext.shopee.products.index'))->assertOk()->assertDontSee('Move to group');
        $this->actingAs($listingOnly)->post(route('ext.shopee.product-groups.move'), ['ids' => [$pid], 'group' => $groupA->id, '_return' => $back])->assertRedirect()->assertSessionHas('error');
        $this->assertSame([], $pivot());

        $this->actingAs($manager)->get(route('ext.shopee.products.index'))->assertOk()->assertSee('Move to group');

        $this->actingAs($manager)->post(route('ext.shopee.product-groups.move'), ['ids' => [$pid], 'group' => $groupA->id, '_return' => $back])
            ->assertRedirect($back)->assertSessionHas('status', '1 product moved to ' . $groupA->name . '.');
        $this->assertSame([$groupA->id], $pivot());

        $this->actingAs($manager)->post(route('ext.shopee.product-groups.move'), ['ids' => [$pid], 'group' => $groupB->id, '_return' => $back])
            ->assertRedirect($back);
        $this->assertSame([$groupB->id], $pivot());

        $this->actingAs($manager)->get(route('ext.shopee.products.index', ['group' => $groupB->id]))->assertOk()->assertSee('Remove from group')->assertSee('Manage group');

        $this->actingAs($manager)->post(route('ext.shopee.product-groups.move'), ['ids' => [$pid], 'group' => 'none', '_return' => $back])
            ->assertRedirect($back)->assertSessionHas('status', '1 product ungrouped.');
        $this->assertSame([], $pivot());
    }

    public function test_a_whole_group_sends_as_a_run_from_the_listings_page(): void
    {
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create(['name' => 'Fuzz', 'shopee_category_id' => 100013, 'logistic_ids' => [8003]]);
        foreach ([$this->product('Run pedal one', 'SRN-1'), $this->product('Run pedal two', 'SRN-2')] as $pid) {
            \Extensions\shopee\Models\ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $pid]);
        }
        $offList = $this->product('Not carried here', 'SRN-3');
        ShopeeListing::query()->where('product_id', $offList)->delete();
        \Extensions\shopee\Models\ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $offList]);
        $manager = $this->manager();
        $manager->userGroup->permissions()->attach(Permission::whereIn('key', ['manage_shopee/product_group', 'view_shopee/product_group'])->pluck('id')->all());
        $listingOnlyGroup = UserGroup::create(['name' => 'Listings without groups']);
        $listingOnlyGroup->permissions()->attach(Permission::whereIn('key', ['manage_shopee/product', 'view_shopee/product'])->pluck('id')->all());
        $listingOnly = User::factory()->create(['user_group_id' => $listingOnlyGroup->id]);
        $page = route('ext.shopee.products.index', ['group' => $group->id]);

        $this->actingAs($listingOnly)->get($page)->assertOk()->assertDontSee('Send group');
        $this->actingAs($listingOnly)->post(route('ext.shopee.product-groups.send_run_begin', ['id' => $group->id]))->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, \App\Models\AutomationRun::query()->count());

        $this->actingAs($manager)->get($page)->assertOk()->assertSee('Send group')
            ->assertSee('Send all 2 products in Fuzz to Shopee on group settings, rather than their own?');
        $this->actingAs($manager)->get(route('ext.shopee.products.index', ['group' => $group->id, 'sync_status' => 'uploaded']))->assertOk()
            ->assertSee('Send all 2 products in Fuzz to Shopee on group settings, rather than their own?');

        ShopeeSetting::query()->update(['access_token' => null]);
        $answer = $this->actingAs($manager)->postJson(route('ext.shopee.product-groups.send_run_begin', ['id' => $group->id]))->assertOk()->json();
        $this->assertSame('failed', $answer['run']['status']);
        $this->assertSame(2, $answer['run']['total']);
        $this->assertSame('Missing Shopee Production settings.', $answer['outcome']);
        $this->assertSame('group:' . $group->id, \App\Models\AutomationRun::query()->value('subject'));
    }
}
