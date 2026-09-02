<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeProductGroupAttribute;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * Presets on the bulk-push review (Phase F, #184): the workbench does
 * what the group page's Send did, from the listings page. A group is the
 * PRESET STORE - loading one prefills the shared fields and its
 * attribute defaults ride the push (each listing's own answers still
 * win), and the settings just reviewed can be saved back as a preset by
 * name. Born here, applied here, never a page to go work in - the owner's
 * back-and-forth is the defect this exists to remove.
 */
class ShopeePresetPushTest extends TestCase
{
    use RefreshDatabase;

    /** @var array<int, array{path: string, body: array}> */
    public array $sentPosts = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create(['mode' => 'production', 'partner_id' => '1', 'partner_key' => 'k', 'shop_id' => '2', 'access_token' => 't', 'refresh_token' => 'r']);

        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->sentPosts[] = ['path' => $path, 'body' => $body];

                return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['item_id' => 990088]]];
            }
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => []]];
            }
            public function signShop(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
            {
                return 'test-sign';
            }
            public function baseUrl(string $mode): string
            {
                return 'https://shopee.test';
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);

        Http::fake([
            'shopee.test/*' => Http::response(['response' => ['image_info' => ['image_id' => 'img-001']]], 200),
        ]);

        @mkdir(public_path('image/catalog'), 0775, true);
        file_put_contents(public_path('image/catalog/__preset-test.png'), 'png');
    }

    protected function tearDown(): void
    {
        @unlink(public_path('image/catalog/__preset-test.png'));
        parent::tearDown();
    }

    protected function manager(): User
    {
        $group = UserGroup::create(['name' => 'Preset pushers ' . uniqid()]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    protected function seedProduct(): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => 'PR-1', 'sku' => 'PR-1', 'quantity' => 4, 'price' => 300,
            'status' => 1, 'image' => 'catalog/__preset-test.png', 'weight' => 1, 'length' => 5, 'width' => 5, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Preset product', 'description' => 'A description.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    protected function presetWithAttrs(): ShopeeProductGroup
    {
        $preset = ShopeeProductGroup::create([
            'name' => 'Everyday preset', 'shopee_category_id' => 100139,
            'logistic_ids' => [41003], 'markup_percent' => 10, 'markup_fixed' => 0,
        ]);
        ShopeeProductGroupAttribute::create(['shopee_product_group_id' => $preset->id, 'attribute_key' => 'Material', 'value' => 'Maple']);
        ShopeeProductGroupAttribute::create(['shopee_product_group_id' => $preset->id, 'attribute_key' => 'Origin', 'value' => 'Philippines']);

        return $preset;
    }

    /** Loading a preset prefills the review's shared fields - a GET reload, no script. */
    public function test_loading_a_preset_prefills_the_shared_fields(): void
    {
        $pid = $this->seedProduct();
        $preset = $this->presetWithAttrs();

        $page = $this->actingAs($this->manager())
            ->get(route('ext.shopee.products.bulk_push_form', ['product_ids' => [$pid], 'preset' => $preset->id]))
            ->assertOk();

        $page->assertSee('Loaded "Everyday preset"', false);
        $page->assertSee('value="100139"', false);
        $page->assertSee('value="10', false);
    }

    /** The preset's attribute defaults ride the push; each listing's own answer wins. */
    public function test_preset_attributes_ride_the_push_and_the_listings_own_win(): void
    {
        $pid = $this->seedProduct();
        $preset = $this->presetWithAttrs();
        ShopeeCategoryTemplate::create([
            'category_id' => 100139, 'region' => 'production',
            'attributes' => ['attribute_list' => [
                ['attribute_id' => 901, 'display_attribute_name' => 'Material'],
                ['attribute_id' => 902, 'display_attribute_name' => 'Origin'],
            ]],
            'fetched_at' => now(),
        ]);
        ShopeeListing::create([
            'product_id' => $pid, 'shopee_category_id' => 100139, 'logistic_ids' => [41003],
            'markup_percent' => 0, 'markup_fixed' => 0,
            'attribute_values' => ['Material' => 'Rosewood'],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.bulk_push'), [
                'product_ids' => [$pid],
                'preset_group_id' => $preset->id,
            ])->assertRedirect();

        $add = collect($this->sentPosts)->firstWhere('path', '/api/v2/product/add_item');
        $this->assertNotNull($add, 'the push must reach add_item');
        $attrs = collect($add['body']['attribute_list'] ?? []);
        $byId = $attrs->keyBy('attribute_id');
        $this->assertSame('Rosewood', $byId[901]['attribute_value_list'][0]['original_value_name'] ?? null,
            "the listing's own answer wins over the preset's");
        $this->assertSame('Philippines', $byId[902]['attribute_value_list'][0]['original_value_name'] ?? null,
            'the preset fills the gap the listing left');
    }

    /** The reviewed settings can be saved back as a preset by name. */
    public function test_the_shared_settings_save_back_as_a_preset(): void
    {
        $pid = $this->seedProduct();
        // Readiness demands a READ category sheet before any push.
        ShopeeCategoryTemplate::create([
            'category_id' => 100139, 'region' => 'production',
            'attributes' => ['attribute_list' => []], 'fetched_at' => now(),
        ]);
        ShopeeListing::create([
            'product_id' => $pid, 'shopee_category_id' => 100139, 'logistic_ids' => [41003],
            'markup_percent' => 0, 'markup_fixed' => 0,
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.bulk_push'), [
                'product_ids' => [$pid],
                'shared_category_id' => 100139,
                'shared_logistic_ids' => [41003],
                'shared_markup_percent' => 12,
                'preset_save_name' => 'Weekend sale',
            ])->assertRedirect();

        $this->assertStringContainsString("saved as preset 'Weekend sale'", (string) session('status'));
        $saved = ShopeeProductGroup::query()->where('name', 'Weekend sale')->first();
        $this->assertNotNull($saved);
        $this->assertSame(100139, (int) $saved->shopee_category_id);
        $this->assertSame(12.0, (float) $saved->markup_percent);
    }
}
