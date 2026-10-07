<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeLogistic;
use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class PushReviewModalTest extends TestCase
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
        ShopeeCategory::create(['category_id' => 100200, 'parent_id' => null, 'name' => 'Manual Brewers', 'level' => 0, 'leaf' => true]);
        ShopeeCategoryTemplate::query()->firstOrCreate(['category_id' => 100200], ['attributes' => []]);
        ShopeeLogistic::create(['logistics_channel_id' => 8003, 'logistics_channel_name' => 'J&T Express', 'enabled' => true]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Review modal managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku, float $price = 300.0): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => $price,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => $langId,
            'name' => $name, 'description' => 'Catalog words.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    public function test_the_single_review_states_what_goes_up_in_names_and_arithmetic(): void
    {
        $pid = $this->seedProduct('Ceramic Dripper', 'CD-1', 300.0);
        ShopeeListing::create([
            'product_id' => $pid, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'markup_fixed' => 70,
        ]);

        $json = $this->actingAs($this->manager())
            ->getJson(route('ext.shopee.products.push_review', $pid))
            ->assertOk()->json();

        $this->assertTrue($json['ready']);
        $this->assertSame('370.00', $json['price']);
        $this->assertSame('300.00', $json['core_price']);
        $this->assertStringContainsString('70.00 over catalog', $json['rule']);
        $this->assertSame('Manual Brewers', $json['category'], 'the category goes by NAME, not id');
        $this->assertSame(['J&T Express'], $json['couriers'], 'couriers go by name too');
        $this->assertSame('Ceramic Dripper', $json['title'], 'no override yet: the catalog name is what would go');
    }

    public function test_the_review_shows_the_description_the_push_will_send_templates_included(): void
    {
        $pid = $this->seedProduct('Ceramic Dripper', 'CD-2');
        $storeId = (int) ShopeeSetting::query()->value('id');
        $prefix = \App\Models\DescriptionTemplate::query()->create(['integration' => 'shopee', 'store_id' => $storeId, 'name' => 'Returns', 'body' => 'Returns within 7 days.']);
        ShopeeListing::create([
            'product_id' => $pid, 'shopee_setting_id' => $storeId, 'shopee_category_id' => 100200,
            'logistic_ids' => [8003], 'description_prefix_id' => $prefix->id,
        ]);

        $json = $this->actingAs($this->manager())
            ->getJson(route('ext.shopee.products.push_review', $pid))
            ->assertOk()->json();

        $this->assertSame("Returns within 7 days.\n\nCatalog words.", $json['description'],
            'The review promises exactly what the push sends: the template above the catalog words.');
    }

    public function test_a_modal_edited_title_is_saved_as_the_override_before_the_push(): void
    {
        $pid = $this->seedProduct('Original name', 'ON-1');
        ShopeeListing::create(['product_id' => $pid, 'shopee_category_id' => 100200, 'logistic_ids' => [8003]]);

        $this->actingAs($this->manager())->post(route('ext.shopee.products.push_direct', $pid), [
            'title' => 'Snappier Shopee name',
            'description' => 'Shopee-only words.',
        ]);

        $listing = ShopeeListing::query()->where('product_id', $pid)->firstOrFail();
        $this->assertSame('Snappier Shopee name', $listing->item_name);
        $this->assertSame('Shopee-only words.', $listing->description);
    }
}
