<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Shopee\Models\ShopeeProductGroup;
use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class AddProductsGroupChoiceTest extends TestCase
{
    use RefreshDatabase;

    private ShopeeStore $store;

    private ShopeeProductGroup $cables;

    private ShopeeProductGroup $pedals;

    private int $productId;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');

        $this->store = ShopeeStore::query()->create(['partner_id' => 1, 'partner_key' => 'k', 'shop_id' => 2, 'store_name' => 'Main shop', 'mode' => 'production']);
        $this->cables = ShopeeProductGroup::create(['name' => 'Cables', 'shopee_category_id' => 1, 'logistic_ids' => [1], 'store_id' => $this->store->id]);
        $this->pedals = ShopeeProductGroup::create(['name' => 'Pedals', 'shopee_category_id' => 1, 'logistic_ids' => [1], 'store_id' => $this->store->id]);

        $this->productId = (int) DB::table('products')->insertGetId([
            'sku' => 'SKU-P', 'quantity' => 5, 'price' => 100, 'status' => 1, 'image' => '',
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'brand_id' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert(['product_id' => $this->productId, 'language_id' => (int) config('catalog.default_language_id'), 'name' => 'Patch cable',
            'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '']);
    }

    private function user(): User
    {
        $group = UserGroup::create(['name' => 'Adders']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product', 'view_shopee/product_group', 'manage_shopee/product_group'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function add(array $body)
    {
        return $this->actingAs($this->user())
            ->postJson(route('ext.shopee.products.add_to_store', ['productId' => $this->productId]), $body)
            ->assertOk();
    }

    private function holdingGroups(): array
    {
        return DB::table('ch_shopee_product_group_products')->where('product_id', $this->productId)->pluck('shopee_product_group_id')->map(fn ($v) => (int) $v)->all();
    }

    public function test_the_product_lands_in_the_chosen_group(): void
    {
        $this->add(['group' => (string) $this->cables->id]);

        $this->assertSame([$this->cables->id], $this->holdingGroups());
        $this->assertTrue(DB::table('ch_shopee_listings')->where('product_id', $this->productId)->exists(), 'it is on the store too');
    }

    public function test_no_group_leaves_it_ungrouped(): void
    {
        $this->add([]);

        $this->assertSame([], $this->holdingGroups());
    }

    public function test_it_leaves_its_other_group_on_the_same_store(): void
    {
        DB::table('ch_shopee_product_group_products')->insert(['shopee_product_group_id' => $this->pedals->id, 'product_id' => $this->productId]);

        $this->add(['group' => (string) $this->cables->id]);

        $this->assertSame([$this->cables->id], $this->holdingGroups(), 'one product, one group per store');
    }

    public function test_a_group_of_another_store_is_refused(): void
    {
        $other = ShopeeStore::query()->create(['partner_id' => 3, 'partner_key' => 'k', 'shop_id' => 4, 'store_name' => 'Outlet', 'mode' => 'production']);
        $theirs = ShopeeProductGroup::create(['name' => 'Theirs', 'shopee_category_id' => 1, 'logistic_ids' => [1], 'store_id' => $other->id]);
        $this->app->instance('shopee.route-store', $this->store);

        $this->add(['group' => (string) $theirs->id]);

        $this->assertSame([], $this->holdingGroups());
    }

    public function test_the_window_opens_on_the_group_the_page_stands_in(): void
    {
        DB::table('ch_shopee_listings')->insert(['product_id' => $this->productId, 'store_id' => $this->store->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('ch_shopee_product_group_products')->insert(['shopee_product_group_id' => $this->cables->id, 'product_id' => $this->productId]);
        $user = $this->user();

        $this->actingAs($user)->get(route('ext.shopee.products.index', ['group' => $this->cables->id]))
            ->assertOk()
            ->assertSee('data-add-panel-group', false)
            ->assertSee('<option value="' . $this->cables->id . '" selected>Cables</option>', false);

        $this->actingAs($user)->get(route('ext.shopee.products.index'))
            ->assertOk()
            ->assertDontSee('" selected>Cables</option>', false);
    }
}
