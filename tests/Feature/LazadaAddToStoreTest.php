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
        $group = UserGroup::create(['name' => 'LZ add manager ' . uniqid()]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'view_lazada/dashboard', 'view_lazada/product', 'manage_lazada/product',
                'view_lazada/product_group', 'manage_lazada/product_group',
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

    private function base(): string
    {
        return '/channels/lazada/' . $this->store->id . '/products';
    }

    public function test_this_store_shows_only_products_added_to_this_store(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $onStore]);

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('On store widget', $html);
        $this->assertStringNotContainsString('Catalogue only widget', $html, 'a catalogue product not on this store must not appear on This store');
    }

    public function test_a_new_store_lists_nothing_and_offers_the_add_panel(): void
    {
        $this->seedProduct('Catalogue only widget', 'OFF-1');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('No products on this store yet', $html);
        $this->assertStringContainsString('data-add-panel-open', $html);
        $this->assertStringContainsString('data-add-panel', $html, 'the panel markup rides the page for a manager');
        $this->assertStringNotContainsString('cc-worktab', $html, 'the two-tab treatment is gone');
    }

    public function test_the_panel_is_not_rendered_for_a_read_only_operator(): void
    {
        $group = UserGroup::create(['name' => 'LZ viewer ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', ['view_lazada/dashboard', 'view_lazada/product'])->pluck('id')->all());
        $viewer = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($viewer)->get($this->base())->assertOk()->getContent();

        $this->assertStringNotContainsString('data-add-panel-open', $html);
        $this->assertStringNotContainsString('id="lazada-add-panel"', $html);
    }

    public function test_catalogue_search_defaults_to_products_not_yet_on_this_store(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $onStore]);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search')->assertOk();

        $names = collect($r->json('items'))->pluck('name')->all();
        $this->assertContains('Catalogue only widget', $names);
        $this->assertNotContains('On store widget', $names, 'the panel is for adding; what is already on the store is hidden by default');
        $this->assertSame(1, $r->json('total'));
    }

    public function test_catalogue_search_can_show_everything_flagged(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $onStore]);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search?all=1')->assertOk();

        $byName = collect($r->json('items'))->keyBy('name');
        $this->assertTrue($byName['On store widget']['on_store']);
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
        $this->assertSame(0, LazadaProduct::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->count());

        $this->actingAs($this->manager())
            ->postJson($this->base() . '/' . $pid . '/add-to-store')
            ->assertOk()->assertJson(['ok' => true, 'product_id' => $pid, 'added' => true]);

        $row = LazadaProduct::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->store->id, (int) $row->lazada_setting_id, 'the row is stamped with this store');

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

        $this->assertSame(1, LazadaProduct::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->count());
    }

    public function test_bulk_add_puts_the_selected_products_on_the_store(): void
    {
        $a = $this->seedProduct('Widget A', 'A-1');
        $b = $this->seedProduct('Widget B', 'B-1');

        $this->actingAs($this->manager())
            ->from($this->base() . '?list=add')
            ->post($this->base() . '/add-to-store', ['product_ids' => [$a, $b]])
            ->assertRedirect();

        $this->assertSame(2, LazadaProduct::withoutGlobalScope('lazadaStore')->whereIn('product_id', [$a, $b])->where('lazada_setting_id', $this->store->id)->count());
    }

    public function test_add_to_store_is_idempotent(): void
    {
        $pid = $this->seedProduct('Widget', 'W-1');
        LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $pid]);

        $this->actingAs($this->manager())
            ->postJson($this->base() . '/' . $pid . '/add-to-store')
            ->assertOk()->assertJson(['added' => false]);

        $this->assertSame(1, LazadaProduct::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->where('lazada_setting_id', $this->store->id)->count(),
            'adding a product already on the store must not double it');
    }

    public function test_the_full_catalogue_page_is_still_reachable_as_the_overflow(): void
    {
        $this->seedProduct('Catalogue only widget', 'OFF-1');

        $html = $this->actingAs($this->manager())->get($this->base() . '?list=add')->assertOk()->getContent();

        $this->assertStringContainsString('Catalogue only widget', $html);
        $this->assertStringContainsString('Add to store', $html);
        $this->assertStringContainsString('Back to Listings', $html);
        $this->assertStringNotContainsString('cc-worktab', $html);
    }

    public function test_remove_from_store_takes_the_product_off_the_store_freely(): void
    {
        $pid = $this->seedProduct('Live widget', 'LIVE-1');
        $listing = LazadaProduct::create([
            'lazada_setting_id' => $this->store->id, 'product_id' => $pid,
            'lazada_item_id' => '901234', 'live_status' => 'active',
        ]);
        $group = \Extensions\lazada\Models\LazadaProductGroup::create(['lazada_setting_id' => $this->store->id, 'name' => 'Group', 'lazada_category_id' => 1]);
        $listing->groups()->attach($group->id, ['product_id' => $pid]);

        $this->actingAs($this->manager())
            ->from($this->base())
            ->post($this->base() . '/' . $pid . '/remove-from-store')
            ->assertRedirect($this->base())->assertSessionHas('status');

        $this->assertSame(0, LazadaProduct::withoutGlobalScope('lazadaStore')->where('id', $listing->id)->count(), 'the listing row is gone, so nothing is left to sync');
        $this->assertSame(0, DB::table('lazada_product_group_products')->where('lazada_product_id', $listing->id)->count(), 'its group membership went with it');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();
        $this->assertStringNotContainsString('Live widget', $html);
    }

    public function test_the_remove_confirm_names_the_live_listing_and_the_marketplace_door(): void
    {
        $pid = $this->seedProduct('Live widget', 'LIVE-1');
        LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $pid, 'lazada_item_id' => '901234', 'live_status' => 'active']);

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('Remove from this channel', $html);
        $this->assertStringContainsString('901234', $html);
        $this->assertStringContainsString('stays up, still orderable', $html, 'the confirm says plainly that the marketplace listing is untouched');
        $this->assertStringContainsString('Use Delete from Lazada for that', $html);
    }

    public function test_remove_from_store_cannot_reach_another_stores_row(): void
    {
        $other = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $pid = $this->seedProduct('Theirs', 'T-1');
        $theirs = LazadaProduct::create(['lazada_setting_id' => $other->id, 'product_id' => $pid]);

        $this->actingAs($this->manager())
            ->post($this->base() . '/' . $pid . '/remove-from-store')
            ->assertNotFound();

        $this->assertSame(1, LazadaProduct::withoutGlobalScope('lazadaStore')->where('id', $theirs->id)->count());
    }

    private function group(string $name = 'Helmets'): \Extensions\lazada\Models\LazadaProductGroup
    {
        return \Extensions\lazada\Models\LazadaProductGroup::create(['lazada_setting_id' => $this->store->id, 'name' => $name, 'lazada_category_id' => 100]);
    }

    private function groupBase(int $groupId): string
    {
        return '/channels/lazada/' . $this->store->id . '/product-groups/' . $groupId;
    }

    private function inGroup(\Extensions\lazada\Models\LazadaProductGroup $group, LazadaProduct $listing): void
    {
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'lazada_product_id' => $listing->id, 'product_id' => $listing->product_id,
        ]);
    }

    public function test_the_group_page_offers_the_panel_and_not_add_a_product_by_name(): void
    {
        $group = $this->group();

        $html = $this->actingAs($this->manager())->get($this->groupBase($group->id) . '/products')->assertOk()->getContent();

        $this->assertStringContainsString('data-add-panel-open', $html);
        $this->assertStringContainsString('id="lazada-group-add-panel"', $html);
        $this->assertStringContainsString('In this group', $html);
        $this->assertStringNotContainsString('Add a product by name', $html);
        $this->assertStringNotContainsString('data-group-manual-add', $html);
    }

    public function test_group_search_offers_only_this_stores_products_not_yet_in_the_group(): void
    {
        $group = $this->group();
        $grouped = LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $this->seedProduct('Already grouped', 'G-1')]);
        LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $this->seedProduct('On store free', 'S-1')]);
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->inGroup($group, $grouped);

        $r = $this->actingAs($this->manager())->getJson($this->groupBase($group->id) . '/products/search')->assertOk();
        $byName = collect($r->json('items'))->keyBy('name');
        $this->assertEqualsCanonicalizing(['On store free', 'Catalogue only widget'], $byName->keys()->all());
        $this->assertTrue($byName['On store free']['listed']);
        $this->assertFalse($byName['Catalogue only widget']['listed'], 'the row says the store does not carry it yet');

        $all = $this->actingAs($this->manager())->getJson($this->groupBase($group->id) . '/products/search?all=1')->assertOk();
        $byName = collect($all->json('items'))->keyBy('name');
        $this->assertTrue($byName['Already grouped']['on_store']);
        $this->assertFalse($byName['On store free']['on_store']);
    }

    public function test_group_search_names_the_group_that_owns_a_product_elsewhere(): void
    {
        $group = $this->group('Helmets');
        $other = $this->group('Gloves');
        $listing = LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $this->seedProduct('Owned elsewhere', 'E-1')]);
        $this->inGroup($other, $listing);

        $r = $this->actingAs($this->manager())->getJson($this->groupBase($group->id) . '/products/search')->assertOk();

        $item = collect($r->json('items'))->firstWhere('name', 'Owned elsewhere');
        $this->assertNotNull($item);
        $this->assertFalse($item['on_store']);
        $this->assertSame('Gloves', $item['elsewhere']);
    }

    public function test_add_to_group_over_json_puts_this_stores_product_in_the_group(): void
    {
        $group = $this->group();
        $listing = LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $this->seedProduct('On store free', 'S-1')]);
        $pid = $listing->product_id;

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add')
            ->assertOk()->assertJson(['ok' => true, 'product_id' => $pid, 'added' => true, 'moved_from' => null]);

        $pivot = \Extensions\lazada\Models\LazadaProductGroupProduct::query()->where('lazada_product_group_id', $group->id)->where('product_id', $pid)->first();
        $this->assertNotNull($pivot);
        $this->assertSame($listing->id, (int) $pivot->lazada_product_id, 'the pivot rides the store\'s existing listing row');
        $this->assertSame(1, LazadaProduct::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->count(), 'no second listing row was created');

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add')
            ->assertOk()->assertJson(['added' => false]);
    }

    public function test_add_to_group_lists_a_catalogue_product_on_the_store_as_it_joins(): void
    {
        $group = $this->group();
        $pid = $this->seedProduct('Catalogue only widget', 'OFF-1');

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add')
            ->assertOk()->assertJson(['ok' => true, 'added' => true, 'listed' => true]);

        $row = LazadaProduct::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->get();
        $this->assertCount(1, $row, 'one listing row for this store, the same the Listings page creates');
        $this->assertSame($this->store->id, (int) $row->first()->lazada_setting_id);
        $pivot = \Extensions\lazada\Models\LazadaProductGroupProduct::query()->where('lazada_product_group_id', $group->id)->where('product_id', $pid)->first();
        $this->assertNotNull($pivot);
        $this->assertSame((int) $row->first()->id, (int) $pivot->lazada_product_id, 'the pivot rides the new listing row');

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/424242/add')
            ->assertNotFound();
    }

    public function test_move_here_takes_the_product_from_the_group_that_owned_it(): void
    {
        $group = $this->group('Helmets');
        $other = $this->group('Gloves');
        $listing = LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $this->seedProduct('Owned elsewhere', 'E-1')]);
        $pid = $listing->product_id;
        $this->inGroup($other, $listing);

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add')
            ->assertStatus(409);
        $this->assertSame($other->id, (int) \Extensions\lazada\Models\LazadaProductGroupProduct::query()->where('product_id', $pid)->value('lazada_product_group_id'));

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $pid . '/add', ['move' => true])
            ->assertOk()->assertJson(['ok' => true, 'added' => true, 'moved_from' => 'Gloves']);

        $owners = \Extensions\lazada\Models\LazadaProductGroupProduct::query()->where('product_id', $pid)->pluck('lazada_product_group_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([$group->id], $owners, 'one product, one group: it moved rather than doubled');
    }

    public function test_the_edit_form_is_the_groups_settings_only(): void
    {
        $group = $this->group();
        $listing = LazadaProduct::create(['lazada_setting_id' => $this->store->id, 'product_id' => $this->seedProduct('Already grouped', 'G-1')]);
        $this->inGroup($group, $listing);

        $html = $this->actingAs($this->manager())->get($this->groupBase($group->id) . '/edit')->assertOk()->getContent();

        $this->assertStringNotContainsString('gf-dual', $html);
        $this->assertStringNotContainsString('Products in this product group', $html);
        $this->assertStringNotContainsString('product_ids', $html);

        $this->actingAs($this->manager())
            ->put($this->groupBase($group->id), ['name' => 'Helmets renamed', 'lazada_category_id' => 100, 'product_ids' => []])
            ->assertRedirect();

        $this->assertSame('Helmets renamed', $group->fresh()->name);
        $this->assertSame(1, \Extensions\lazada\Models\LazadaProductGroupProduct::query()->where('lazada_product_group_id', $group->id)->where('product_id', $listing->product_id)->count(),
            'membership is the products page\'s business, not the form\'s');
    }
}
