<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Lazada\LazadaExtension;
use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LazadaAddToStoreTest extends TestCase
{
    use RefreshDatabase;

    private LazadaStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->store = LazadaStore::create(['mode' => 'live', 'store_name' => 'Gearshipper']);
        $this->artisan('permissions:sync-catalog');
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
        $pid = DB::table('products')->insertGetId([
            'sku' => $sku, 'quantity' => 5, 'price' => 100, 'status' => $status,
            'image' => '', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'brand_id' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
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
        $this->seedProduct('Catalog only widget', 'OFF-1');
        LazadaListing::create(['store_id' => $this->store->id, 'product_id' => $onStore]);

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('On store widget', $html);
        $this->assertStringNotContainsString('Catalog only widget', $html, 'a catalog product not on this store must not appear on This store');
    }

    public function test_a_new_store_lists_nothing_and_offers_the_add_panel(): void
    {
        $this->seedProduct('Catalog only widget', 'OFF-1');

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

    public function test_catalog_search_defaults_to_products_not_yet_on_this_store(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $this->seedProduct('Catalog only widget', 'OFF-1');
        LazadaListing::create(['store_id' => $this->store->id, 'product_id' => $onStore]);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalog-search')->assertOk();

        $names = collect($r->json('items'))->pluck('name')->all();
        $this->assertContains('Catalog only widget', $names);
        $this->assertNotContains('On store widget', $names, 'the panel is for adding; what is already on the store is hidden by default');
        $this->assertSame(1, $r->json('total'));
    }

    public function test_catalog_search_can_show_everything_flagged(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $this->seedProduct('Catalog only widget', 'OFF-1');
        LazadaListing::create(['store_id' => $this->store->id, 'product_id' => $onStore]);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalog-search?all=1')->assertOk();

        $byName = collect($r->json('items'))->keyBy('name');
        $this->assertTrue($byName['On store widget']['on_store']);
        $this->assertFalse($byName['Catalog only widget']['on_store']);
    }

    public function test_catalog_search_narrows_by_name_model_or_sku_and_skips_disabled(): void
    {
        $this->seedProduct('Shimano Deore XT', 'SHI-XT');
        $this->seedProduct('Continental GP5000', 'CON-GP5K');
        $this->seedProduct('Disabled thing', 'DIS-1', 0);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalog-search?q=SHI-XT')->assertOk();
        $this->assertSame(['Shimano Deore XT'], collect($r->json('items'))->pluck('name')->all());

        $all = $this->actingAs($this->manager())->getJson($this->base() . '/catalog-search')->assertOk();
        $this->assertNotContains('Disabled thing', collect($all->json('items'))->pluck('name')->all(), 'a disabled catalog product is not something to put on a store');
    }

    public function test_catalog_search_is_capped_and_says_how_much_is_out_of_view(): void
    {
        for ($i = 1; $i <= 30; $i++) {
            $this->seedProduct('Widget ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'W-' . $i);
        }

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalog-search')->assertOk();

        $this->assertSame(30, $r->json('total'));
        $this->assertSame(25, $r->json('shown'), 'a slide-over cannot list a whole catalog; it caps and names the total');
        $this->assertCount(25, $r->json('items'));
    }

    public function test_add_to_store_over_json_puts_the_product_on_the_store(): void
    {
        $pid = $this->seedProduct('Catalog only widget', 'OFF-1');
        $this->assertSame(0, LazadaListing::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->count());

        $this->actingAs($this->manager())
            ->postJson($this->base() . '/' . $pid . '/add-to-store')
            ->assertOk()->assertJson(['ok' => true, 'product_id' => $pid, 'added' => true]);

        $row = LazadaListing::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->first();
        $this->assertNotNull($row);
        $this->assertSame($this->store->id, (int) $row->store_id, 'the row is stamped with this store');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();
        $this->assertStringContainsString('Catalog only widget', $html);
    }

    public function test_add_to_store_as_a_form_post_lands_back_with_a_status(): void
    {
        $pid = $this->seedProduct('Widget', 'W-1');

        $this->actingAs($this->manager())
            ->from($this->base() . '?list=add')
            ->post($this->base() . '/' . $pid . '/add-to-store')
            ->assertRedirect()->assertSessionHas('status');

        $this->assertSame(1, LazadaListing::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->count());
    }

    public function test_bulk_add_puts_the_selected_products_on_the_store(): void
    {
        $a = $this->seedProduct('Widget A', 'A-1');
        $b = $this->seedProduct('Widget B', 'B-1');

        $this->actingAs($this->manager())
            ->from($this->base() . '?list=add')
            ->post($this->base() . '/add-to-store', ['product_ids' => [$a, $b]])
            ->assertRedirect();

        $this->assertSame(2, LazadaListing::withoutGlobalScope('lazadaStore')->whereIn('product_id', [$a, $b])->where('store_id', $this->store->id)->count());
    }

    public function test_add_to_store_is_idempotent(): void
    {
        $pid = $this->seedProduct('Widget', 'W-1');
        LazadaListing::create(['store_id' => $this->store->id, 'product_id' => $pid]);

        $this->actingAs($this->manager())
            ->postJson($this->base() . '/' . $pid . '/add-to-store')
            ->assertOk()->assertJson(['added' => false]);

        $this->assertSame(1, LazadaListing::withoutGlobalScope('lazadaStore')->where('product_id', $pid)->where('store_id', $this->store->id)->count(),
            'adding a product already on the store must not double it');
    }

    public function test_the_full_catalog_page_is_still_reachable_as_the_overflow(): void
    {
        $this->seedProduct('Catalog only widget', 'OFF-1');

        $html = $this->actingAs($this->manager())->get($this->base() . '?list=add')->assertOk()->getContent();

        $this->assertStringContainsString('Catalog only widget', $html);
        $this->assertStringContainsString('Add to store', $html);
        $this->assertStringContainsString('Back to Products', $html);
        $this->assertStringNotContainsString('cc-worktab', $html);
    }

    public function test_remove_from_store_takes_the_product_off_the_store_freely(): void
    {
        $pid = $this->seedProduct('Live widget', 'LIVE-1');
        $listing = LazadaListing::create([
            'store_id' => $this->store->id, 'product_id' => $pid,
            'lazada_item_id' => '901234', 'live_status' => 'active',
        ]);
        $group = \Extensions\Lazada\Models\LazadaProductGroup::create(['store_id' => $this->store->id, 'name' => 'Group']);
        $listing->groups()->attach($group->id, ['product_id' => $pid]);

        $this->actingAs($this->manager())
            ->from($this->base())
            ->post($this->base() . '/' . $pid . '/remove-from-store')
            ->assertRedirect($this->base())->assertSessionHas('status');

        $this->assertSame(0, LazadaListing::withoutGlobalScope('lazadaStore')->where('id', $listing->id)->count(), 'the listing row is gone, so nothing is left to sync');
        $this->assertSame(0, DB::table('ch_lazada_product_group_products')->where('lazada_listing_id', $listing->id)->count(), 'its group membership went with it');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();
        $this->assertStringNotContainsString('Live widget', $html);
    }

    public function test_the_remove_confirm_names_the_live_listing_and_the_marketplace_door(): void
    {
        $pid = $this->seedProduct('Live widget', 'LIVE-1');
        LazadaListing::create(['store_id' => $this->store->id, 'product_id' => $pid, 'lazada_item_id' => '901234', 'live_status' => 'active']);

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('Remove from this channel', $html);
        $this->assertStringContainsString('901234', $html);
        $this->assertStringContainsString('stays up, still orderable', $html, 'the confirm says plainly that the marketplace listing is untouched');
        $this->assertStringContainsString('Use Delete from Lazada for that', $html);
    }

    public function test_remove_from_store_cannot_reach_another_stores_row(): void
    {
        $other = LazadaStore::create(['mode' => 'live', 'store_name' => 'Outlet']);
        $pid = $this->seedProduct('Theirs', 'T-1');
        $theirs = LazadaListing::create(['store_id' => $other->id, 'product_id' => $pid]);

        $this->actingAs($this->manager())
            ->post($this->base() . '/' . $pid . '/remove-from-store')
            ->assertNotFound();

        $this->assertSame(1, LazadaListing::withoutGlobalScope('lazadaStore')->where('id', $theirs->id)->count());
    }

    private function group(string $name = 'Helmets'): \Extensions\Lazada\Models\LazadaProductGroup
    {
        return \Extensions\Lazada\Models\LazadaProductGroup::create(['store_id' => $this->store->id, 'name' => $name]);
    }

    private function groupBase(int $groupId): string
    {
        return '/channels/lazada/' . $this->store->id . '/product-groups/' . $groupId;
    }

    private function inGroup(\Extensions\Lazada\Models\LazadaProductGroup $group, LazadaListing $listing): void
    {
        \Extensions\Lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'lazada_listing_id' => $listing->id, 'product_id' => $listing->product_id,
        ]);
    }

    public function test_the_edit_form_is_the_groups_settings_only(): void
    {
        $group = $this->group();
        $listing = LazadaListing::create(['store_id' => $this->store->id, 'product_id' => $this->seedProduct('Already grouped', 'G-1')]);
        $this->inGroup($group, $listing);

        $html = $this->actingAs($this->manager())->get($this->groupBase($group->id) . '/edit')->assertOk()->getContent();

        $this->assertStringNotContainsString('gf-dual', $html);
        $this->assertStringNotContainsString('Products in this product group', $html);
        $this->assertStringNotContainsString('product_ids', $html);

        $this->actingAs($this->manager())
            ->put($this->groupBase($group->id), ['name' => 'Helmets renamed', 'lazada_category_id' => 100, 'product_ids' => []])
            ->assertRedirect();

        $this->assertSame('Helmets renamed', $group->fresh()->name);
        $this->assertSame(1, \Extensions\Lazada\Models\LazadaProductGroupProduct::query()->where('lazada_product_group_id', $group->id)->where('product_id', $listing->product_id)->count(),
            'membership is the products page\'s business, not the form\'s');
    }
}
