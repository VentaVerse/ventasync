<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokProductGroupProduct;
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

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
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

    public function test_a_group_page_tells_the_products_truth_derived_across_groups(): void
    {
        $productId = $this->seedProduct();

        // Group A pushed it successfully...
        $groupA = TikTokProductGroup::create(['name' => 'Truth group A', 'tiktok_category_id' => 'cat-1']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $groupA->id, 'product_id' => $productId,
            'tiktok_product_id' => 'tt-990077', 'sync_status' => 'pushed',
            'last_pushed_at' => now()->subMinutes(5),
        ]);

        // ...and group B's own attempt failed.
        $groupB = TikTokProductGroup::create(['name' => 'Truth group B', 'tiktok_category_id' => 'cat-1']);
        TikTokProductGroupProduct::create([
            'tiktok_product_group_id' => $groupB->id, 'product_id' => $productId,
            'sync_status' => 'error', 'push_error' => 'Category attribute missing.',
            'last_pushed_at' => now()->subMinutes(37),
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.tiktok.product-groups.products', $groupB->id));

        $r->assertOk();
        // Group B shows the PRODUCT's truth: listed, with A's id and provenance.
        $r->assertSee('tt-990077');
        $r->assertSee('Listed');
        $r->assertSee('from the Truth group A group');
        $r->assertDontSee('>Failed<', false);
        // And its own attempt, scoped in words.
        $r->assertSee("This group's last push", false);
        $r->assertSee('Category attribute missing');
    }
}
