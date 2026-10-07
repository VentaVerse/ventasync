<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokListing;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Services\TikTokStoreProducts;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\TikTokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TiktokAddToStoreTest extends TestCase
{
    use RefreshDatabase;

    private TikTokSetting $store;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->store = TikTokSetting::create([
            'mode' => 'production', 'store_name' => 'Gearshipper', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3),
        ]);
        $this->artisan('permissions:sync-catalogue');
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TT add manager ' . uniqid()]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'view_tiktok/dashboard', 'view_tiktok/product', 'manage_tiktok/product',
                'view_tiktok/product_group', 'manage_tiktok/product_group',
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

    private function onStore(int $productId, ?int $storeId = null, array $extra = []): TikTokListing
    {
        return TikTokListing::create(array_merge(['tiktok_setting_id' => $storeId ?? $this->store->id, 'product_id' => $productId], $extra));
    }

    private function base(): string
    {
        return '/channels/tiktok/' . $this->store->id . '/products';
    }

    private function group(string $name = 'Helmets'): TikTokProductGroup
    {
        return TikTokProductGroup::create(['tiktok_setting_id' => $this->store->id, 'name' => $name, 'tiktok_category_id' => '900001']);
    }

    private function groupBase(int $groupId): string
    {
        return '/channels/tiktok/' . $this->store->id . '/product-groups/' . $groupId;
    }

    public function test_this_store_shows_only_products_this_store_carries(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $legacy = $this->seedProduct('Legacy pivot widget', 'LEG-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->onStore($onStore);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $this->group()->id, 'product_id' => $legacy, 'tiktok_product_id' => 'tt-legacy', 'sync_status' => 'synced']);

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('On store widget', $html);
        $this->assertStringContainsString('Legacy pivot widget', $html);
        $this->assertStringNotContainsString('Catalogue only widget', $html, 'a catalogue product not on this store must not appear on This store');
    }

    public function test_a_new_store_lists_nothing_and_offers_the_add_panel(): void
    {
        $this->seedProduct('Catalogue only widget', 'OFF-1');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();

        $this->assertStringContainsString('No products on this store yet', $html);
        $this->assertStringContainsString('data-add-panel-open', $html);
        $this->assertStringContainsString('id="tiktok-add-panel"', $html);
        $this->assertStringNotContainsString('<span>All products</span>', $html, 'the catalogue framing is gone');
    }

    public function test_the_panel_is_not_rendered_for_a_read_only_operator(): void
    {
        $group = UserGroup::create(['name' => 'TT viewer ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', ['view_tiktok/dashboard', 'view_tiktok/product'])->pluck('id')->all());
        $viewer = User::factory()->create(['user_group_id' => $group->id]);

        $html = $this->actingAs($viewer)->get($this->base())->assertOk()->getContent();

        $this->assertStringNotContainsString('data-add-panel-open', $html);
        $this->assertStringNotContainsString('id="tiktok-add-panel"', $html);
    }

    public function test_catalogue_search_defaults_to_products_not_yet_on_this_store(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->onStore($onStore);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search')->assertOk();

        $names = collect($r->json('items'))->pluck('name')->all();
        $this->assertContains('Catalogue only widget', $names);
        $this->assertNotContains('On store widget', $names);
        $this->assertSame(1, $r->json('total'));
    }

    public function test_catalogue_search_can_show_everything_flagged(): void
    {
        $onStore = $this->seedProduct('On store widget', 'ON-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->onStore($onStore);

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search?all=1')->assertOk();

        $byName = collect($r->json('items'))->keyBy('name');
        $this->assertTrue($byName['On store widget']['on_store']);
        $this->assertFalse($byName['Catalogue only widget']['on_store']);
    }

    public function test_catalogue_search_narrows_and_skips_disabled_and_is_capped(): void
    {
        $this->seedProduct('Shimano Deore XT', 'SHI-XT');
        $this->seedProduct('Disabled thing', 'DIS-1', 0);
        for ($i = 1; $i <= 30; $i++) {
            $this->seedProduct('Widget ' . str_pad((string) $i, 2, '0', STR_PAD_LEFT), 'W-' . $i);
        }

        $r = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search?q=SHI-XT')->assertOk();
        $this->assertSame(['Shimano Deore XT'], collect($r->json('items'))->pluck('name')->all());

        $all = $this->actingAs($this->manager())->getJson($this->base() . '/catalogue-search')->assertOk();
        $this->assertNotContains('Disabled thing', collect($all->json('items'))->pluck('name')->all());
        $this->assertSame(31, $all->json('total'));
        $this->assertSame(25, $all->json('shown'), 'a slide-over cannot list a whole catalogue; it caps and names the total');
    }

    public function test_add_to_store_over_json_puts_the_product_on_the_store(): void
    {
        $pid = $this->seedProduct('Catalogue only widget', 'OFF-1');

        $this->actingAs($this->manager())
            ->postJson($this->base() . '/' . $pid . '/add-to-store')
            ->assertOk()->assertJson(['ok' => true, 'product_id' => $pid, 'added' => true]);

        $row = TikTokListing::withoutGlobalScope('tiktokStore')->where('product_id', $pid)->first();
        $this->assertNotNull($row, 'the membership is a listing record for this store');
        $this->assertSame($this->store->id, (int) $row->tiktok_setting_id);
        $this->assertNull($row->tiktok_category_id, 'blank: the listing page fills it in');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();
        $this->assertStringContainsString('Catalogue only widget', $html);

        $this->actingAs($this->manager())
            ->postJson($this->base() . '/' . $pid . '/add-to-store')
            ->assertOk()->assertJson(['added' => false]);
        $this->assertSame(1, TikTokListing::withoutGlobalScope('tiktokStore')->where('product_id', $pid)->count());
    }

    public function test_form_add_and_bulk_add_and_the_overflow_page(): void
    {
        $a = $this->seedProduct('Widget A', 'A-1');
        $b = $this->seedProduct('Widget B', 'B-1');

        $this->actingAs($this->manager())
            ->from($this->base() . '?list=add')
            ->post($this->base() . '/' . $a . '/add-to-store')
            ->assertRedirect()->assertSessionHas('status');
        $this->actingAs($this->manager())
            ->from($this->base() . '?list=add')
            ->post($this->base() . '/add-to-store', ['product_ids' => [$a, $b]])
            ->assertRedirect();
        $this->assertSame(2, TikTokListing::withoutGlobalScope('tiktokStore')->whereIn('product_id', [$a, $b])->where('tiktok_setting_id', $this->store->id)->count());

        $c = $this->seedProduct('Catalogue only widget', 'OFF-1');
        $html = $this->actingAs($this->manager())->get($this->base() . '?list=add')->assertOk()->getContent();
        $this->assertStringContainsString('Catalogue only widget', $html);
        $this->assertStringContainsString('Add to store', $html);
        $this->assertStringContainsString('Back to Listings', $html);
    }

    public function test_remove_from_store_takes_the_product_off_the_store_freely(): void
    {
        $pid = $this->seedProduct('Live widget', 'LIVE-1');
        $this->onStore($pid, null, ['tiktok_product_id' => 'tt-901234', 'live_status' => 'ACTIVATE']);
        $group = $this->group();
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'pending']);

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();
        $this->assertStringContainsString('Remove from this channel', $html);
        $this->assertStringContainsString('tt-901234', $html);
        $this->assertStringContainsString('stays up, still orderable', $html);
        $this->assertStringContainsString('Use Delete from TikTok Shop for that', $html);

        $this->actingAs($this->manager())
            ->from($this->base())
            ->post($this->base() . '/' . $pid . '/remove-from-store')
            ->assertRedirect($this->base())->assertSessionHas('status');

        $this->assertSame(0, TikTokListing::withoutGlobalScope('tiktokStore')->where('product_id', $pid)->count(), 'the listing record is gone, so nothing is left to sync');
        $this->assertSame(0, TikTokProductGroupProduct::query()->where('product_id', $pid)->count(), 'its group membership went with it');

        $html = $this->actingAs($this->manager())->get($this->base())->assertOk()->getContent();
        $this->assertStringNotContainsString('Live widget', $html);
    }

    public function test_remove_from_store_cannot_reach_another_stores_product(): void
    {
        $other = TikTokSetting::create([
            'mode' => 'production', 'store_name' => 'Outlet', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c2', 'expires_at' => now()->addDays(3),
        ]);
        $pid = $this->seedProduct('Theirs', 'T-1');
        $this->onStore($pid, $other->id);

        $this->actingAs($this->manager())
            ->post($this->base() . '/' . $pid . '/remove-from-store')
            ->assertNotFound();

        $this->assertSame(1, TikTokListing::withoutGlobalScope('tiktokStore')->where('product_id', $pid)->count());
    }

    public function test_the_group_page_offers_the_panel_and_not_add_a_product_by_name(): void
    {
        $group = $this->group();

        $html = $this->actingAs($this->manager())->get($this->groupBase($group->id) . '/products')->assertOk()->getContent();

        $this->assertStringContainsString('data-add-panel-open', $html);
        $this->assertStringContainsString('id="tiktok-group-add-panel"', $html);
        $this->assertStringContainsString('In this group', $html);
        $this->assertStringNotContainsString('Add a product by name', $html);
        $this->assertStringNotContainsString('data-group-manual-add', $html);
    }

    public function test_group_search_offers_only_this_stores_products_and_names_the_owner_elsewhere(): void
    {
        $group = $this->group('Helmets');
        $other = $this->group('Gloves');
        $inGroup = $this->seedProduct('Already grouped', 'G-1');
        $free = $this->seedProduct('On store free', 'S-1');
        $elsewhere = $this->seedProduct('Owned elsewhere', 'E-1');
        $this->seedProduct('Catalogue only widget', 'OFF-1');
        foreach ([$inGroup, $free, $elsewhere] as $pid) {
            $this->onStore($pid);
        }
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $inGroup, 'sync_status' => 'pending']);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $other->id, 'product_id' => $elsewhere, 'sync_status' => 'pending']);

        $r = $this->actingAs($this->manager())->getJson($this->groupBase($group->id) . '/products/search')->assertOk();
        $byName = collect($r->json('items'))->keyBy('name');
        $this->assertSame(['Catalogue only widget', 'On store free', 'Owned elsewhere'], $byName->keys()->sort()->values()->all());
        $this->assertSame('Gloves', $byName['Owned elsewhere']['elsewhere']);
        $this->assertNull($byName['On store free']['elsewhere']);
        $this->assertTrue($byName['On store free']['listed']);
        $this->assertFalse($byName['Catalogue only widget']['listed'], 'the row says the store does not carry it yet');

        $all = $this->actingAs($this->manager())->getJson($this->groupBase($group->id) . '/products/search?all=1')->assertOk();
        $allByName = collect($all->json('items'))->keyBy('name');
        $this->assertTrue($allByName['Already grouped']['on_store']);
    }

    public function test_add_to_group_and_move_here_and_the_store_gate(): void
    {
        $group = $this->group('Helmets');
        $other = $this->group('Gloves');
        $free = $this->seedProduct('On store free', 'S-1');
        $elsewhere = $this->seedProduct('Owned elsewhere', 'E-1');
        $off = $this->seedProduct('Catalogue only widget', 'OFF-1');
        $this->onStore($free);
        $this->onStore($elsewhere);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $other->id, 'product_id' => $elsewhere, 'sync_status' => 'pending']);

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $free . '/add')
            ->assertOk()->assertJson(['ok' => true, 'added' => true, 'moved_from' => null]);
        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $free . '/add')
            ->assertOk()->assertJson(['added' => false]);
        $this->assertSame(1, TikTokProductGroupProduct::query()->where('tiktok_product_group_id', $group->id)->where('product_id', $free)->count());

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $off . '/add')
            ->assertOk()->assertJson(['ok' => true, 'added' => true, 'listed' => true]);
        $this->assertTrue(TikTokStoreProducts::has($off), 'it is on this store now');
        $this->assertSame(1, TikTokProductGroupProduct::query()->where('product_id', $off)->count());
        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/424242/add')
            ->assertNotFound();

        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $elsewhere . '/add')
            ->assertStatus(409);
        $this->actingAs($this->manager())
            ->postJson($this->groupBase($group->id) . '/products/' . $elsewhere . '/add', ['move' => true])
            ->assertOk()->assertJson(['ok' => true, 'added' => true, 'moved_from' => 'Gloves']);
        $owners = TikTokProductGroupProduct::query()->where('product_id', $elsewhere)->pluck('tiktok_product_group_id')->map(fn ($v) => (int) $v)->all();
        $this->assertSame([$group->id], $owners, 'one product, one group: it moved rather than doubled');
    }

    public function test_the_edit_form_is_the_groups_settings_only(): void
    {
        \Extensions\tiktok\Models\TikTokCategory::create(['id' => '900001', 'name' => 'Helmets', 'parent_id' => null, 'is_leaf' => true]);
        \Extensions\tiktok\Models\TikTokCategoryTemplate::create(['category_id' => '900001', 'attributes' => [], 'fetched_at' => now()]);
        $group = $this->group();
        $pid = $this->seedProduct('Already grouped', 'G-1');
        $this->onStore($pid);
        TikTokProductGroupProduct::create(['tiktok_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'pending']);

        $html = $this->actingAs($this->manager())->get($this->groupBase($group->id) . '/edit')->assertOk()->getContent();

        $this->assertStringNotContainsString('gf-dual', $html);
        $this->assertStringNotContainsString('Products in this product group', $html);
        $this->assertStringNotContainsString('product_ids', $html);

        $this->actingAs($this->manager())
            ->put($this->groupBase($group->id), ['name' => 'Helmets renamed', 'tiktok_category_id' => '900001', 'product_ids' => []])
            ->assertRedirect();

        $this->assertSame('Helmets renamed', $group->fresh()->name);
        $this->assertSame(1, TikTokProductGroupProduct::query()->where('tiktok_product_group_id', $group->id)->where('product_id', $pid)->count(),
            'membership is the products page\'s business, not the form\'s');
    }
}
