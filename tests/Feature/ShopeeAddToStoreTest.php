<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Services\ShopeeStoreProducts;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductGroupProduct;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopeeAddToStoreTest extends TestCase
{
    use RefreshDatabase;

    private ShopeeSetting $store;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Gearshipper']);
        $this->artisan('permissions:sync-catalogue');
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'SP add manager ' . uniqid()]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'view_shopee/dashboard', 'view_shopee/product', 'manage_shopee/product',
                'view_shopee/product_group', 'manage_shopee/product_group',
            ])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku, int $status = 1): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100, 'status' => $status,
            'image' => '', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    private function onStore(int $productId, ?int $storeId = null): ShopeeListing
    {
        return ShopeeListing::create(['shopee_setting_id' => $storeId ?? $this->store->id, 'product_id' => $productId]);
    }

    private function base(): string
    {
        return '/channels/shopee/' . $this->store->id . '/products';
    }

    private function groupBase(int $groupId): string
    {
        return '/channels/shopee/' . $this->store->id . '/product-groups/' . $groupId;
    }

    public function test_this_store_shows_only_products_this_store_carries(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $linkedOnly = $this->seedProduct('Linked only widget', 'LNK-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->onStore($onStore);
        ShopeeProductLink::create(['shopee_setting_id' => $this->store->id, 'product_id' => $linkedOnly, 'shopee_item_id' => '777', 'sku' => 'LNK-1']);

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('On store widget', $html);
        $this->assertStringContainsString('Linked only widget', $html);
        $this->assertStringNotContainsString('Catalogue only widget', $html, 'a catalogue product not on this store must not appear on This store');
    }

    public function test_a_new_store_lists_nothing_and_offers_the_add_panel(): void
    {
        $this->seedProduct('Catalogue only widget', 'OFF-1');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('No products on this store yet', $html);
        $this->assertStringContainsString('data-add-panel-open', $html);
        $this->assertStringContainsString('id="shopee-add-panel"', $html, 'the panel markup rides the page for a manager');
        $this->assertStringNotContainsString('cc-worktab', $html, 'the two-tab treatment never came to Shopee');
        $this->assertStringNotContainsString('<span>All products</span>', $html, 'the catalogue framing is gone');
    }

    public function test_the_panel_is_not_rendered_for_a_read_only_operator(): void
    {
        $group = UserGroup::create(['name' => 'SP viewer ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/dashboard', 'view_shopee/product'])->pluck('id')->all());
        $viewer = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($viewer)->get($this->base())->assertOk()->getContent();

        $this->assertStringNotContainsString('data-add-panel-open', $html);
        $this->assertStringNotContainsString('id="shopee-add-panel"', $html);
    }

    public function test_catalogue_search_defaults_to_products_not_yet_on_this_store(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->onStore($onStore);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search')->assertOk();

        $names = collect($r->json('items'))->pluck('name')->all();
        $this->assertContains('Catalogue only widget', $names);
        $this->assertNotContains('On store widget', $names, 'the panel is for adding; what is already on the store is hidden by default');
        $this->assertSame(1, $r->json('total'));
    }

    public function test_catalogue_search_can_show_everything_flagged(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $linked = $this->seedProduct('Linked widget', 'LNK-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->onStore($onStore);
        ShopeeProductLink::create(['shopee_setting_id' => $this->store->id, 'product_id' => $linked, 'shopee_item_id' => '777', 'sku' => 'LNK-1']);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search?all=1')->assertOk();

        $byName = collect($r->json('items'))->keyBy('name');
        $this->assertTrue($byName['On store widget']['on_store']);
        $this->assertTrue($byName['Linked widget']['on_store'], 'a link row alone counts as on the store');
        $this->assertFalse($byName['Catalogue only widget']['on_store']);
    }

    public function test_catalogue_search_narrows_by_name_model_or_sku_and_skips_disabled(): void
    {
        $this->seedProduct('Shimano Deore XT', 'SHI-XT');
        $this->seedProduct('Continental GP5000', 'CON-GP5K');
        $this->seedProduct('Disabled thing', 'DIS-1', 0);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search?q=SHI-XT')->assertOk();
        $this->assertSame(['Shimano Deore XT'], collect($r->json('items'))->pluck('name')->all());

        $all = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search')->assertOk();
        $this->assertNotContains('Disabled thing', collect($all->json('items'))->pluck('name')->all(), 'a disabled catalogue product is not something to put on a store');
    }

    public function test_catalogue_search_is_capped_and_says_how_much_is_out_of_view(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->seedProduct('Widget ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'W-' . $i);
        }

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search')->assertOk();

        $this->assertSame(30, $r->json('total'));
        $this->assertSame(25, $r->json('shown'), 'a slide-over cannot list a whole catalogue; it caps and names the total');
        $this->assertCount(25, $r->json('items'));
    }

    public function test_add_to_store_over_json_puts_the_product_on_the_store(): void
    {
        $pid = $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->assertSame(0, ShopeeListing::withoutGlobalScope('shopeeStore')->where('product_id', $pid)->count());

        $this->actingAs($this->manager())
            ->postJson($this->base() . '/' . $pid . '/add-to-store')
            ->assertOk()->assertJson(['ok' => true, 'product_id' => $pid, 'added' => true]);

        $row = ShopeeListing::withoutGlobalScope('shopeeStore')->where('product_id', $pid)->first();
        $this->assertNotNull($row, 'the membership is a listing record for this store');
        $this->assertSame($this->store->id, (int) $row->shopee_setting_id, 'the record is stamped with this store');
        $this->assertNull($row->shopee_category_id, 'blank: the listing editor fills it in, readiness says what is missing');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();
        $this->assertStringContainsString('Catalogue only widget', $html);
    }

    public function test_add_to_store_as_a_form_post_lands_back_with_a_status(): void
    {
        $pid = $this->seedProduct('Widget', 'W-1');

        $this->actingAs($this->manager())
            ->from($this->base() . '?list=add')
            ->post($this->base() . '/' . $pid . '/add-to-store')
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, ShopeeListing::withoutGlobalScope('shopeeStore')->where('product_id', $pid)->count());
    }

    public function test_bulk_add_puts_the_selected_products_on_the_store(): void
    {
        $a = $this->seedProduct('Widget A', 'A-1');
        $b = $this->seedProduct('Widget B', 'B-1');

        $this->actingAs($this->manager())
            ->from($this->base() . '?list=add')
            ->post($this->base() . '/add-to-store', ['product_ids' => [$a, $b]])
            ->assertRedirect();

        $this->assertSame(2, ShopeeListing::withoutGlobalScope('shopeeStore')->whereIn('product_id', [$a, $b])->where('shopee_setting_id', $this->store->id)->count());
    }

    public function test_add_to_store_is_idempotent(): void
    {
        $pid = $this->seedProduct('Widget', 'W-1');
        $this->onStore($pid);

        $this->actingAs($this->manager())
            ->postJson($this->base() . '/' . $pid . '/add-to-store')
            ->assertOk()->assertJson(['added' => false]);

        $this->assertSame(1, ShopeeListing::withoutGlobalScope('shopeeStore')->where('product_id', $pid)->where('shopee_setting_id', $this->store->id)->count(),
            'adding a product already on the store must not double it');
    }

    public function test_the_full_catalogue_page_is_still_reachable_as_the_overflow(): void
    {
        $this->seedProduct('Catalogue only widget', 'OFF-1');

        $html = $this->actingAs($this->manager())->get($this->base() . '?list=add')->assertOk()->getContent();

        $this->assertStringContainsString('Catalogue only widget', $html);
        $this->assertStringContainsString('Add to store', $html);
        $this->assertStringContainsString('Back to Listings', $html);
    }

    public function test_remove_from_store_takes_the_product_off_the_store_freely(): void
    {
        $pid = $this->seedProduct('Live widget', 'LIVE-1');
        $this->onStore($pid);
        ShopeeProductLink::create(['shopee_setting_id' => $this->store->id, 'product_id' => $pid, 'shopee_item_id' => '901234', 'sku' => 'LIVE-1', 'live_status' => 'NORMAL']);
        $group = ShopeeProductGroup::create(['shopee_setting_id' => $this->store->id, 'name' => 'Group', 'shopee_category_id' => 100]);
        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $pid]);

        $this->actingAs($this->manager())
            ->from($this->base())
            ->post($this->base() . '/' . $pid . '/remove-from-store')
            ->assertRedirect($this->base())->assertSessionHas('status');

        $this->assertSame(0, ShopeeListing::withoutGlobalScope('shopeeStore')->where('product_id', $pid)->count(), 'the listing record is gone');
        $this->assertSame(0, ShopeeProductLink::withoutGlobalScope('shopeeStore')->where('product_id', $pid)->count(), 'the link rows are gone, so nothing is left to sync');
        $this->assertSame(0, ShopeeProductGroupProduct::query()->where('product_id', $pid)->count(), 'its group membership went with it');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();
        $this->assertStringNotContainsString('Live widget', $html);
    }

    public function test_the_remove_confirm_names_the_live_listing_and_the_marketplace_door(): void
    {
        $pid = $this->seedProduct('Live widget', 'LIVE-1');
        ShopeeProductLink::create(['shopee_setting_id' => $this->store->id, 'product_id' => $pid, 'shopee_item_id' => '901234', 'sku' => 'LIVE-1', 'live_status' => 'NORMAL']);

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('Remove from this channel', $html);
        $this->assertStringContainsString('901234', $html);
        $this->assertStringContainsString('stays up, still orderable', $html, 'the confirm says plainly that the marketplace listing is untouched');
        $this->assertStringContainsString('Use Delete from Shopee for that', $html);
    }

    public function test_remove_from_store_cannot_reach_another_stores_product(): void
    {
        $other = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $pid = $this->seedProduct('Theirs', 'T-1');
        $this->onStore($pid, $other->id);

        $this->actingAs($this->manager())
            ->post($this->base() . '/' . $pid . '/remove-from-store')
            ->assertNotFound();

        $this->assertSame(1, ShopeeListing::withoutGlobalScope('shopeeStore')->where('product_id', $pid)->count());
    }

    private function group(string $name = 'Helmets'): ShopeeProductGroup
    {
        return ShopeeProductGroup::create(['shopee_setting_id' => $this->store->id, 'name' => $name, 'shopee_category_id' => 100, 'logistic_ids' => [1]]);
    }

    public function test_the_group_page_offers_the_panel_and_not_add_a_product_by_name(): void
    {
        $group = $this->group();

        $html = $this->actingAs($this->manager())->get($this->groupBase($group->id) . '/products')->assertOk()->getContent();

        $this->assertStringContainsString('data-add-panel-open', $html);
        $this->assertStringContainsString('id="shopee-group-add-panel"', $html);
        $this->assertStringContainsString('In this group', $html, 'the panel speaks the group\'s vocabulary');
        $this->assertStringNotContainsString('Add a product by name', $html);
        $this->assertStringNotContainsString('data-group-manual-add', $html);
    }

    public function test_group_search_offers_the_main_catalogue_and_says_what_the_store_does_not_carry_yet(): void
    {
        $group = $this->group();
        $inGroup = $this->seedProduct('Already grouped', 'G-1');
        $onStore = $this->seedProduct('On store free', 'S-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->onStore($inGroup);
        $this->onStore($onStore);
        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $inGroup]);

        $r = $this->actingAs($this->manager())->getJson($this->groupBase($group->id) . '/products/search')->assertOk();

        $byName = collect($r->json('items'))->keyBy('name');
        $this->assertEqualsCanonicalizing(['On store free', 'Catalogue only widget'], $byName->keys()->all(), 'the catalogue, minus what the group already holds');
        $this->assertTrue($byName['On store free']['listed']);
        $this->assertFalse($byName['Catalogue only widget']['listed'], 'the row says the store does not carry it yet');

        $all = $this->actingAs($this->manager())->getJson($this->groupBase($group->id) . '/products/search?all=1')->assertOk();
        $byName = collect($all->json('items'))->keyBy('name');
        $this->assertTrue($byName['Already grouped']['on_store'], 'the panel\'s "already in" flag means in this group here');
        $this->assertFalse($byName['On store free']['on_store']);
    }

    public function test_adding_a_catalogue_product_to_a_group_lists_it_on_the_store_as_it_joins(): void
    {
        $group = $this->group();
        $pid = $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->assertFalse(ShopeeStoreProducts::has($pid));

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add')
            ->assertOk()->assertJson(['ok' => true, 'added' => true, 'listed' => true]);

        $this->assertTrue(ShopeeStoreProducts::has($pid), 'it is on this store now');
        $this->assertSame(1, ShopeeProductGroupProduct::query()->where('shopee_product_group_id', $group->id)->where('product_id', $pid)->count());

        $again = $this->seedProduct('On store already', 'S-2');
        $this->onStore($again);
        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $again . '/add')
            ->assertOk()->assertJson(['ok' => true, 'added' => true, 'listed' => false]);
        $this->assertSame(1, ShopeeListing::query()->where('product_id', $again)->count());

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/424242/add')
            ->assertNotFound();
    }

    public function test_group_search_names_the_group_that_owns_a_product_elsewhere(): void
    {
        $group = $this->group('Helmets');
        $other = $this->group('Gloves');
        $pid = $this->seedProduct('Owned elsewhere', 'E-1');
        $this->onStore($pid);
        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $other->id, 'product_id' => $pid]);

        $r = $this->actingAs($this->manager())->getJson($this->groupBase($group->id) . '/products/search')->assertOk();

        $item = collect($r->json('items'))->firstWhere('name', 'Owned elsewhere');
        $this->assertNotNull($item);
        $this->assertFalse($item['on_store']);
        $this->assertSame('Gloves', $item['elsewhere'], 'the panel can offer Move here instead of a refusal after the press');
    }

    public function test_add_to_group_over_json_puts_this_stores_product_in_the_group(): void
    {
        $group = $this->group();
        $pid = $this->seedProduct('On store free', 'S-1');
        $this->onStore($pid);

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add')
            ->assertOk()->assertJson(['ok' => true, 'product_id' => $pid, 'added' => true, 'moved_from' => null]);

        $this->assertSame(1, ShopeeProductGroupProduct::query()->where('shopee_product_group_id', $group->id)->where('product_id', $pid)->count());

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add')
            ->assertOk()->assertJson(['added' => false]);
        $this->assertSame(1, ShopeeProductGroupProduct::query()->where('shopee_product_group_id', $group->id)->where('product_id', $pid)->count());
    }

    public function test_move_here_takes_the_product_from_the_group_that_owned_it(): void
    {
        $group = $this->group('Helmets');
        $other = $this->group('Gloves');
        $pid = $this->seedProduct('Owned elsewhere', 'E-1');
        $this->onStore($pid);
        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $other->id, 'product_id' => $pid]);

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add')
            ->assertStatus(409);
        $this->assertSame($other->id, (int) ShopeeProductGroupProduct::query()->where('product_id', $pid)->value('shopee_product_group_id'));

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add', ['move' => true])
            ->assertOk()->assertJson(['ok' => true, 'added' => true, 'moved_from' => 'Gloves']);

        $owners = ShopeeProductGroupProduct::query()->where('product_id', $pid)->pluck('shopee_product_group_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([$group->id], $owners, 'one product, one group: it moved rather than doubled');
    }

    public function test_the_edit_form_is_the_groups_settings_only(): void
    {
        $group = $this->group();
        $pid = $this->seedProduct('Already grouped', 'G-1');
        $this->onStore($pid);
        ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $pid]);

        $html = $this->actingAs($this->manager())->get($this->groupBase($group->id) . '/edit')->assertOk()->getContent();

        $this->assertStringNotContainsString('gf-dual', $html, 'the dual list is gone');
        $this->assertStringNotContainsString('Products in this product group', $html);
        $this->assertStringNotContainsString('product_ids', $html);

        $this->actingAs($this->manager())
            ->put($this->groupBase($group->id), [
                'name' => 'Helmets renamed', 'shopee_category_id' => 100, 'logistic_ids' => [1], 'product_ids' => [],
            ])
            ->assertRedirect();

        $this->assertSame('Helmets renamed', $group->fresh()->name);
        $this->assertSame(1, ShopeeProductGroupProduct::query()->where('shopee_product_group_id', $group->id)->where('product_id', $pid)->count(),
            'membership is the products page\'s business, not the form\'s');
    }
}
