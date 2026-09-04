<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
use Extensions\tiktok\Models\TikTokSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * TikTok's one-truth port (2026-08-25). TikTok has no listings table -
 * the group pivot rows are the only record - so whether a product is ON
 * TikTok is DERIVED across every group's rows: any pivot holding the id
 * answers for the product, latest push first, and the provenance names
 * which group's push made it true. This group's own failed attempt is
 * told as exactly that, beside the truth, never instead of it.
 */
class TiktokOneTruthTest extends TestCase
{
    use RefreshDatabase;

    /** The store every store-scoped TikTok route in this file names. */
    private TikTokSetting $tiktokStore;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        // Multi-store (the owner 2026-09-05): every TikTok page is store-scoped, so
        // a store must exist for ResolveTikTokStore to bind the {store} segment.
        $this->tiktokStore = TikTokSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
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
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'TT-1', 'sku' => 'TT-SKU', 'quantity' => 5, 'price' => 300,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'manufacturer_id' => 0,
            'date_added' => now()->subDays(30), 'date_modified' => now()->subDays(30), 'date_available' => now()->subDays(30),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'TikTok truth product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_a_group_page_tells_the_products_truth_beyond_its_own_attempt(): void
    {
        /* Reshaped 2026-09-04 (one product, one group): the dual-membership
           scenario this pinned is illegal now, but the truth it guarded
           survives - the group page must show the PRODUCT's real listed
           state even when the group's own last attempt failed. Here the
           listing record carries the truth (pushed from another door,
           provenance recorded), while the one group holds a failed try. */
        $productId = $this->seedProduct();

        $groupB = TikTokProductGroup::create(['name' => 'Truth group B', 'tiktok_category_id' => 'cat-1']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $groupB->id, 'product_id' => $productId,
            'sync_status' => 'error', 'push_error' => 'Category attribute missing.',
            'last_pushed_at' => now()->subMinutes(37),
        ]);

        \Extensions\tiktok\Models\TikTokListing::create([
            'product_id' => $productId, 'tiktok_product_id' => 'tt-990077',
            'last_pushed_at' => now()->subMinutes(5),
            'last_push_source' => 'group:Truth group A',
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.tiktok.product-groups.products', ['store' => $this->tiktokStore->id, 'id' => $groupB->id]));

        $r->assertOk();
        // Group B shows the PRODUCT's truth: listed, with the real id and provenance.
        $r->assertSee('tt-990077');
        $r->assertSee('Listed');
        $r->assertSee('from the Truth group A product group');
        $r->assertDontSee('>Failed<', false);
        // And its own attempt, scoped in words.
        $r->assertSee("This product group's last push", false);
        $r->assertSee('Category attribute missing');
    }
}
