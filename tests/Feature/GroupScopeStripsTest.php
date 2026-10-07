<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductGroupProduct;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class GroupScopeStripsTest extends TestCase
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
            'store_name' => 'Main', 'enabled' => true, 'mode' => 'live',
            'partner_id' => 1, 'partner_key' => 'k', 'shop_id' => 2,
            'access_token' => 't', 'refresh_token' => 'r', 'region' => 'ph',
        ]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Strip managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/product_group', 'manage_shopee/product_group', 'view_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => $langId,
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    public function test_the_group_page_wears_member_scoped_strips_and_a_tab_narrows_them(): void
    {
        $group = ShopeeProductGroup::create([
            'name' => 'Strip group', 'shopee_category_id' => 100, 'logistic_ids' => [1],
        ]);

        $member = $this->seedProduct('Member live', 'MEM-1');
        $memberUnlisted = $this->seedProduct('Member unlisted', 'MEM-2');
        $stranger = $this->seedProduct('Stranger live', 'STR-1');

        foreach ([$member, $memberUnlisted] as $pid) {
            ShopeeProductGroupProduct::create(['shopee_product_group_id' => $group->id, 'product_id' => $pid]);
        }

        ShopeeProductLink::create(['product_id' => $member, 'shopee_item_id' => 1001, 'sku' => 'MEM-1', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        ShopeeProductLink::create(['product_id' => $stranger, 'shopee_item_id' => 1002, 'sku' => 'STR-1', 'live_status' => 'NORMAL', 'live_checked_at' => now()]);
        ShopeeProductLink::create(['product_id' => $memberUnlisted, 'shopee_item_id' => 1003, 'sku' => 'MEM-2', 'live_status' => 'UNLIST', 'live_checked_at' => now()]);

        $manager = $this->manager();
        $html = $this->actingAs($manager)
            ->get(route('ext.shopee.product-groups.products', $group->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('<span>All</span>', $html);
        $this->assertStringNotContainsString('x-segment-group__k', $html, 'the catalogue framing went with #201: the strip is the store\'s own list, no group labels');
        $this->assertMatchesRegularExpression('/>Live<\/span>\s*<span class="x-segment__count">1</', $html,
            'the Live count must be member-scoped: 1, not 2 - the stranger\'s NORMAL row is not this group\'s business');
        $this->assertStringContainsString('shopee_tab=unlisted', $html);

        $tabbed = $this->actingAs($manager)
            ->get(route('ext.shopee.product-groups.products', ['id' => $group->id, 'shopee_tab' => 'unlisted']))
            ->assertOk()->getContent();
        $this->assertStringContainsString('Member unlisted', $tabbed);
        $this->assertStringNotContainsString('Member live', $tabbed);
        $this->assertStringNotContainsString('Stranger live', $tabbed);
    }
}
