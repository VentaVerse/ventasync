<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\TikTok\Models\TikTokProductGroup;
use Extensions\TikTok\Models\TikTokProductGroupProduct;
use Extensions\TikTok\Models\TikTokStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TikTokOneTruthTest extends TestCase
{
    use RefreshDatabase;

    private TikTokStore $tiktokStore;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\TikTok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');

        $this->tiktokStore = TikTokStore::create(['mode' => 'live', 'store_name' => 'Main store']);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok truth watchers']);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'manage_tiktok/product_group', 'view_tiktok/product_group',
            ])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(): int
    {
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table('products')->insertGetId([
            'model' => 'TT-1', 'sku' => 'TT-SKU', 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'brand_id' => 0,
            'date_added' => now()->subDays(30), 'date_modified' => now()->subDays(30), 'date_available' => now()->subDays(30),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'TikTok truth product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_a_group_page_tells_the_products_truth_beyond_its_own_attempt(): void
    {
        $productId = $this->seedProduct();

        $groupB = TikTokProductGroup::create(['name' => 'Truth group B', 'tiktok_category_id' => '900001']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $groupB->id, 'product_id' => $productId,
            'sync_status' => 'error', 'push_error' => 'Category attribute missing.',
            'last_pushed_at' => now()->subMinutes(37),
        ]);

        \Extensions\TikTok\Models\TikTokListing::create([
            'product_id' => $productId, 'tiktok_product_id' => 'tt-990077',
            'last_pushed_at' => now()->subMinutes(5),
            'last_push_source' => 'group:Truth group A',
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.tiktok.product-groups.products', ['store' => $this->tiktokStore->id, 'id' => $groupB->id]));

        $r->assertOk();
        $r->assertSee('tt-990077');
        $r->assertSee('Listed');
        $r->assertDontSee('>Failed<', false);
        $r->assertDontSee('cc-col-ready', false);
    }
}
