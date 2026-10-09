<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Shopee\Models\ShopeeListingLink;
use Extensions\Shopee\Models\ShopeeStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class DisabledProductsStayOutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada', 'tiktok'] as $ext) {
            $manager->install($ext);
            $manager->enable($ext);
        }
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\Lazada\LazadaExtension::class);
        $this->app->register(\Extensions\TikTok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');

        ShopeeStore::create(['mode' => 'live', 'store_name' => 'Main store']);
    }

    private function operator(): User
    {
        $group = UserGroup::create(['name' => 'Disabled-row desk']);
        $group->permissions()->attach(Permission::query()
            ->where(fn ($q) => $q->where('key', 'like', '%shopee%')->orWhere('key', 'like', '%lazada%')->orWhere('key', 'like', '%tiktok%'))
            ->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $sku, string $name, int $status): int
    {
        $productId = DB::table('products')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 300,
            'status' => $status, 'image' => '', 'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10,
            'brand_id' => 0, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    public function test_the_default_shopee_view_hides_disabled_unlisted_and_keeps_disabled_listed(): void
    {
        $hidden = $this->seedProduct('DIS-HIDE', 'Disabled and nowhere', 0);
        \Extensions\Shopee\Models\ShopeeListing::create(['product_id' => $hidden]);
        $listedDisabled = $this->seedProduct('DIS-KEEP', 'Disabled but still on Shopee', 0);
        ShopeeListingLink::create(['product_id' => $listedDisabled, 'shopee_item_id' => 800, 'live_status' => 'NORMAL']);
        $plain = $this->seedProduct('EN-1', 'Enabled and plain', 1);
        \Extensions\Shopee\Models\ShopeeListing::create(['product_id' => $plain]);

        $user = $this->operator();
        $page = $this->actingAs($user)->get(route('ext.shopee.products.index'))->assertOk()->getContent();

        $this->assertStringNotContainsString('Disabled and nowhere', $page,
            'a disabled product the channel does not hold is noise the pushes skip anyway');
        $this->assertStringContainsString('Disabled but still on Shopee', $page,
            'a disabled product still listed needs eyes - it wants unlisting, not hiding');
        $this->assertStringContainsString('Enabled and plain', $page);

        $filtered = $this->actingAs($user)->get(route('ext.shopee.products.index') . '?erp_status=disabled')->assertOk()->getContent();
        $this->assertStringContainsString('Disabled and nowhere', $filtered,
            'the Disabled filter still shows the hidden ones on purpose');
    }

    public function test_lazada_and_tiktok_follow_the_same_rule(): void
    {
        $this->seedProduct('DIS-LZ', 'Disabled lazada nowhere', 0);
        $this->seedProduct('DIS-TT', 'Disabled tiktok nowhere', 0);
        $user = $this->operator();

        $lazadaStore = \Extensions\Lazada\Models\LazadaStore::create(['mode' => 'live', 'store_name' => 'Main store']);
        $tiktokStore = \Extensions\TikTok\Models\TikTokStore::create(['mode' => 'live', 'store_name' => 'Main store']);

        $lz = $this->actingAs($user)->get(route('ext.lazada.products.index', ['store' => $lazadaStore->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Disabled lazada nowhere', $lz);

        $tt = $this->actingAs($user)->get(route('ext.tiktok.products.index', ['store' => $tiktokStore->id]))->assertOk()->getContent();
        $this->assertStringNotContainsString('Disabled tiktok nowhere', $tt);
    }
}
