<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ReadRouteDoesNotWriteTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT = 4242;
    private const ITEM = '778899001';

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function tierUser(string $label, array $permissions): User
    {
        $group = UserGroup::create(['name' => $label.' '.uniqid('', true)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $permissions)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedAdoptableRow(): int
    {
        $pfx = (string) config('catalog.prefix');

        DB::table($pfx.'product')->insert([
            'product_id' => self::PRODUCT,
            'sku' => 'ADOPT-ME',
            'model' => 'ADOPT-ME',
            'quantity' => 5,
            'price' => 100,
            'status' => 1,
            'date_added' => now(),
            'date_modified' => now(),
        ]);

        DB::table($pfx.'product_description')->insert([
            'product_id' => self::PRODUCT,
            'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Adoptable product',
            'description' => '',
            'meta_title' => 'Adoptable product',
            'meta_description' => '',
            'meta_keyword' => '',
        ]);

        $groupId = DB::table('shopee_product_groups')->insertGetId([
            'shopee_setting_id' => \Extensions\shopee\Models\ShopeeSetting::query()->orderBy('id')->value('id')
                ?? \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store'])->id,
            'name' => 'Adoption',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $groupId,
            'product_id' => self::PRODUCT,
            'shopee_item_id' => null,
            'sync_status' => 'pending',
        ]);

        DB::table('shopee_product_links')->insert([
            'shopee_setting_id' => \Extensions\shopee\Models\ShopeeSetting::query()->orderBy('id')->value('id')
                ?? \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store'])->id,
            'product_id' => self::PRODUCT,
            'shopee_item_id' => self::ITEM,
            'sku' => 'ADOPT-ME',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $groupId;
    }

    private function pivot(int $groupId): ?object
    {
        return DB::table('shopee_product_group_products')
            ->where('shopee_product_group_id', $groupId)
            ->where('product_id', self::PRODUCT)
            ->first();
    }

    public function test_the_read_tier_does_not_mutate_rows_by_loading_the_page(): void
    {
        $groupId = $this->seedAdoptableRow();

        $this->actingAs($this->tierUser('Shopee Viewer', ['view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'view_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.products', ['id' => $groupId]))
            ->assertOk();

        $row = $this->pivot($groupId);

        $this->assertNull($row->shopee_item_id,
            'A view-only operator wrote an item id to the database by loading a read route.');
        $this->assertSame('pending', $row->sync_status,
            'A view-only operator changed a row status by loading a read route.');
    }

    public function test_the_manage_tier_does_not_write_on_load_either(): void
    {
        $groupId = $this->seedAdoptableRow();

        $r = $this->actingAs($this->tierUser('Shopee Manager', ['view_shopee/dashboard', 'view_shopee/settings', 'view_shopee/category', 'view_shopee/category_attribute', 'view_shopee/logistics', 'view_shopee/product_group', 'view_shopee/product', 'manage_shopee/settings', 'manage_shopee/category', 'manage_shopee/category_attribute', 'manage_shopee/logistics', 'manage_shopee/product_group', 'manage_shopee/product', 'manage_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.products', ['id' => $groupId]));

        $r->assertOk();
        $r->assertSee(self::ITEM);

        $row = $this->pivot($groupId);
        $this->assertNull($row->shopee_item_id,
            'Loading the page wrote a pivot copy of a product fact the display no longer needs.');
        $this->assertSame('pending', $row->sync_status);
    }
}
