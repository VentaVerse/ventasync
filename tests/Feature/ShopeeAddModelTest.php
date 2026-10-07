<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeListing;
use Extensions\shopee\Models\ShopeeProductLink;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ShopeeAddModelTest extends TestCase
{
    use RefreshDatabase;

    public array $postResponses = [];

    public array $getResponses = [];

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

        ShopeeSetting::query()->create(['mode' => 'live', 'partner_id' => '1', 'partner_key' => 'k', 'shop_id' => '2', 'access_token' => 't', 'refresh_token' => 'r']);

        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                $this->test->sentPosts[] = ['path' => $path, 'body' => $body];
                $answer = $this->test->postResponses[$path] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($body);
                }

                return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => []]];
            }
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                $answer = $this->test->getResponses[$path] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($extraQuery);
                }

                return $answer ?? ['ok' => false, 'status' => 500, 'body' => ['error' => 'error', 'message' => 'unexpected GET ' . $path]];
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Add-model group ' . uniqid()]);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_shopee/product', 'manage_shopee/product', 'view_shopee/product_group', 'manage_shopee/product_group'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function packProduct(array $packs): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'PK', 'sku' => 'PK', 'quantity' => 30, 'price' => 100,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Pack product', 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        $optionId = (int) DB::table($pfx . 'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
        DB::table($pfx . 'option_description')->insert([
            'option_id' => $optionId, 'language_id' => $langId, 'name' => 'Pack size',
        ]);
        $productOptionId = (int) DB::table($pfx . 'product_option')->insertGetId([
            'product_id' => $productId, 'option_id' => $optionId, 'value' => '', 'required' => 0,
        ]);

        foreach ($packs as $i => [$name, $sku, $qty, $price]) {
            $valueId = (int) DB::table($pfx . 'option_value')->insertGetId([
                'option_id' => $optionId, 'image' => '', 'sort_order' => $i,
            ]);
            DB::table($pfx . 'option_value_description')->insert([
                'option_value_id' => $valueId, 'language_id' => $langId,
                'option_id' => $optionId, 'name' => $name,
            ]);
            $povId = (int) DB::table($pfx . 'product_option_value')->insertGetId([
                'product_option_id' => $productOptionId, 'product_id' => $productId,
                'option_id' => $optionId, 'option_value_id' => $valueId,
                'sku' => $sku, 'quantity' => $qty, 'subtract' => 1,
                'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
                'weight' => 0, 'weight_prefix' => '+',
                'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0,
                'absolute_cost' => 0, 'cost_prefix' => '+', 'absolute_price' => $price,
            ]);
            $comboId = (int) DB::table('product_option_combinations')->insertGetId([
                'product_id' => $productId, 'sku' => $sku, 'quantity' => $qty,
                'absolute_price' => $price, 'image' => null, 'status' => 1,
                'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_option_combination_values')->insert([
                'combination_id' => $comboId, 'product_option_value_id' => $povId,
            ]);
        }

        return $productId;
    }

    private function linkAndRule(int $productId): void
    {
        ShopeeProductLink::create(['product_id' => $productId, 'shopee_item_id' => 800, 'shopee_model_id' => 81, 'sku' => 'PK-5']);
        ShopeeListing::create(['product_id' => $productId, 'shopee_category_id' => 100139, 'markup_percent' => 0, 'markup_fixed' => 0]);
    }

    private function fakeShopeeHolds(): void
    {
        $calls = 0;
        $this->getResponses['/api/v2/product/get_model_list'] = function () use (&$calls) {
            $calls++;
            if ($calls === 1) {
                return ['ok' => true, 'status' => 200, 'body' => ['response' => [
                    'tier_variation' => [['name' => 'Pack size', 'option_list' => [['option' => '5 pcs']]]],
                    'model' => [['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0], 'price_info' => [['original_price' => 500]]]],
                ]]];
            }

            return ['ok' => true, 'status' => 200, 'body' => ['response' => [
                'tier_variation' => [['name' => 'Pack size', 'option_list' => [['option' => '5 pcs'], ['option' => '10 pcs']]]],
                'model' => [
                    ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                    ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
                ],
            ]]];
        };
    }

    public function test_a_new_variation_joins_the_live_listing_in_one_push(): void
    {
        $pid = $this->packProduct([['5 pcs', 'PK-5', 10, 500], ['10 pcs', 'PK-10', 8, 950]]);
        $this->linkAndRule($pid);
        $this->fakeShopeeHolds();

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_missing_variations', $pid))
            ->assertRedirect();

        $flash = (string) session('status');
        $this->assertStringContainsString('1 variation added to Shopee', $flash);
        $this->assertStringContainsString('1 new tier option', $flash);

        $tier = collect($this->sentPosts)->firstWhere('path', '/api/v2/product/update_tier_variation');
        $this->assertNotNull($tier);
        $this->assertSame(['5 pcs', '10 pcs'], array_column($tier['body']['tier_variation'][0]['option_list'], 'option'));

        $add = collect($this->sentPosts)->firstWhere('path', '/api/v2/product/add_model');
        $this->assertNotNull($add);
        $this->assertSame('PK-10', $add['body']['model_list'][0]['model_sku']);
        $this->assertSame([1], $add['body']['model_list'][0]['tier_index']);
        $this->assertSame(950.0, (float) $add['body']['model_list'][0]['original_price']);

        $models = ShopeeProductLink::query()->where('product_id', $pid)
            ->pluck('shopee_model_id')->map(fn ($v) => (int) $v)->sort()->values()->all();
        $this->assertSame([81, 82], $models);
    }

    public function test_nothing_missing_writes_nothing(): void
    {
        $pid = $this->packProduct([['5 pcs', 'PK-5', 10, 500]]);
        $this->linkAndRule($pid);
        $this->fakeShopeeHolds();

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_missing_variations', $pid))
            ->assertRedirect();

        $this->assertStringContainsString('already on Shopee', (string) session('status'));
        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/api/v2/product/update_tier_variation'));
        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/api/v2/product/add_model'));
    }

    public function test_a_price_spread_across_the_whole_item_is_refused_at_the_door(): void
    {
        $pid = $this->packProduct([['5 pcs', 'PK-5', 10, 500], ['100 pcs', 'PK-100', 3, 9000]]);
        $this->linkAndRule($pid);
        $this->fakeShopeeHolds();

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_missing_variations', $pid))
            ->assertRedirect();

        $this->assertStringContainsString('more than 5 times', (string) session('error'));
        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/api/v2/product/update_tier_variation'));
        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/api/v2/product/add_model'));
    }

    private function variationProduct(array $options, array $combos): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => 'VR', 'sku' => 'VR', 'quantity' => 30, 'price' => 100,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => 'Variation product', 'description' => 'Sold by the pack.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        $povIds = [];
        foreach ($options as $optionName => $values) {
            $optionId = (int) DB::table($pfx . 'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
            DB::table($pfx . 'option_description')->insert([
                'option_id' => $optionId, 'language_id' => $langId, 'name' => $optionName,
            ]);
            $productOptionId = (int) DB::table($pfx . 'product_option')->insertGetId([
                'product_id' => $productId, 'option_id' => $optionId, 'value' => '', 'required' => 0,
            ]);
            foreach ($values as $i => $valueName) {
                $valueId = (int) DB::table($pfx . 'option_value')->insertGetId([
                    'option_id' => $optionId, 'image' => '', 'sort_order' => $i,
                ]);
                DB::table($pfx . 'option_value_description')->insert([
                    'option_value_id' => $valueId, 'language_id' => $langId,
                    'option_id' => $optionId, 'name' => $valueName,
                ]);
                $povIds[$optionName][$valueName] = (int) DB::table($pfx . 'product_option_value')->insertGetId([
                    'product_option_id' => $productOptionId, 'product_id' => $productId,
                    'option_id' => $optionId, 'option_value_id' => $valueId,
                    'sku' => '', 'quantity' => 5, 'subtract' => 1,
                    'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
                    'weight' => 0, 'weight_prefix' => '+',
                    'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0,
                    'absolute_cost' => 0, 'cost_prefix' => '+', 'absolute_price' => 100,
                ]);
            }
        }

        $optionNames = array_keys($options);
        $n = 0;
        foreach ($combos as $sku => $picked) {
            $comboId = (int) DB::table('product_option_combinations')->insertGetId([
                'product_id' => $productId, 'sku' => $sku, 'quantity' => 5,
                'absolute_price' => 100, 'image' => null, 'status' => 1,
                'sort_order' => $n++, 'created_at' => now(), 'updated_at' => now(),
            ]);
            foreach ($picked as $k => $valueName) {
                DB::table('product_option_combination_values')->insert([
                    'combination_id' => $comboId, 'product_option_value_id' => $povIds[$optionNames[$k]][$valueName],
                ]);
            }
        }

        return $productId;
    }

    private function linkForUpdate(int $productId): void
    {
        ShopeeProductLink::create(['product_id' => $productId, 'shopee_item_id' => 800, 'shopee_model_id' => 81, 'sku' => 'PK-5']);
        ShopeeListing::create(['product_id' => $productId, 'shopee_category_id' => 100139, 'logistic_ids' => [8003]]);
    }

    private function itemHolds(array $response): void
    {
        $this->getResponses['/api/v2/product/get_model_list'] = ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => $response]];
    }

    private function postIndex(string $path): ?int
    {
        foreach ($this->sentPosts as $i => $post) {
            if ($post['path'] === $path) {
                return $i;
            }
        }

        return null;
    }

    public function test_an_update_renames_a_custom_type_and_its_values_to_the_catalogs(): void
    {
        $pid = $this->variationProduct(['Pack size' => ['5 pcs', '10 pcs']], ['PK-5' => ['5 pcs'], 'PK-10' => ['10 pcs']]);
        $this->linkForUpdate($pid);
        $this->itemHolds([
            'tier_variation' => [['name' => 'Size', 'option_list' => [
                ['option' => 'Ten', 'image' => ['image_id' => 'img-ten']],
                ['option' => 'Five'],
            ]]],
            'model' => [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [1]],
                ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [0]],
            ],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('status');

        $tier = collect($this->sentPosts)->firstWhere('path', '/api/v2/product/update_tier_variation');
        $this->assertNotNull($tier, 'The rename must reach update_tier_variation.');
        $this->assertSame(800, (int) $tier['body']['item_id']);
        $this->assertSame('Pack size', $tier['body']['tier_variation'][0]['name']);
        $this->assertSame(['10 pcs', '5 pcs'], array_column($tier['body']['tier_variation'][0]['option_list'], 'option'));
        $this->assertSame('img-ten', $tier['body']['tier_variation'][0]['option_list'][0]['image']['image_id'], 'An option keeps its picture.');
        $this->assertSame([
            ['model_id' => 81, 'tier_index' => [1]],
            ['model_id' => 82, 'tier_index' => [0]],
        ], $tier['body']['model_list']);

        foreach (['/api/v2/product/add_model', '/api/v2/product/delete_model', '/api/v2/product/init_tier_variation'] as $path) {
            $this->assertNull($this->postIndex($path), $path . ' must not run for a rename.');
        }
        $this->assertNotNull($this->postIndex('/api/v2/product/update_item'));
        $this->assertLessThan($this->postIndex('/api/v2/product/update_item'), $this->postIndex('/api/v2/product/update_tier_variation'));
        $this->assertNull(ShopeeListing::query()->where('product_id', $pid)->value('last_push_error'));
    }

    public function test_a_category_defined_type_keeps_the_stores_name(): void
    {
        $pid = $this->variationProduct(
            ['Colour' => ['Red', 'Blue'], 'Pack size' => ['Small', 'Large']],
            ['CL-RS' => ['Red', 'Small'], 'CL-RL' => ['Red', 'Large'], 'CL-BS' => ['Blue', 'Small'], 'CL-BL' => ['Blue', 'Large']]
        );
        $this->linkForUpdate($pid);
        $standard = [
            'variation_id' => 101054, 'variation_group_id' => 849982774362112, 'variation_name' => 'Color',
            'variation_option_list' => [
                ['variation_option_id' => 6242, 'variation_option_name' => 'red'],
                ['variation_option_id' => 6246, 'variation_option_name' => 'blue'],
            ],
        ];
        $this->itemHolds([
            'tier_variation' => [
                ['name' => 'Color', 'option_list' => [['option' => 'red'], ['option' => 'blue']]],
                ['name' => 'Taille', 'option_list' => [['option' => 'S'], ['option' => 'L']]],
            ],
            'standardise_tier_variation' => [
                $standard,
                ['variation_id' => 0, 'variation_group_id' => 0, 'variation_name' => 'Taille', 'variation_option_list' => [
                    ['variation_option_id' => 0, 'variation_option_name' => 'S'],
                    ['variation_option_id' => 0, 'variation_option_name' => 'L'],
                ]],
            ],
            'model' => [
                ['model_id' => 91, 'model_sku' => 'CL-RS', 'tier_index' => [0, 0]],
                ['model_id' => 92, 'model_sku' => 'CL-RL', 'tier_index' => [0, 1]],
                ['model_id' => 93, 'model_sku' => 'CL-BS', 'tier_index' => [1, 0]],
                ['model_id' => 94, 'model_sku' => 'CL-BL', 'tier_index' => [1, 1]],
            ],
        ]);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Packs', 'shopee_setting_id' => (int) ShopeeSetting::query()->value('id'),
            'shopee_category_id' => 100139, 'logistic_ids' => [8003],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.updateProduct', $group->id), ['ids' => [$pid]])
            ->assertRedirect();

        $tier = collect($this->sentPosts)->firstWhere('path', '/api/v2/product/update_tier_variation');
        $this->assertNotNull($tier, 'The seller\'s type still takes the catalog\'s names.');
        $this->assertArrayNotHasKey('tier_variation', $tier['body'], 'The item speaks the standardise shape, so only that goes.');
        $sent = $tier['body']['standardise_tier_variation'];
        $this->assertSame($standard, $sent[0], 'The category\'s type goes back exactly as Shopee holds it.');
        $this->assertSame(0, $sent[1]['variation_id']);
        $this->assertSame('Pack size', $sent[1]['variation_name']);
        $this->assertSame(['Small', 'Large'], array_column($sent[1]['variation_option_list'], 'variation_option_name'));
        $this->assertSame([[0, 0], [0, 1], [1, 0], [1, 1]], array_column($tier['body']['model_list'], 'tier_index'));
        $this->assertNotNull($this->postIndex('/api/v2/product/update_item'));
    }

    public function test_no_rename_call_when_the_names_already_match(): void
    {
        $pid = $this->variationProduct(['Pack size' => ['5 pcs', '10 pcs']], ['PK-5' => ['5 pcs'], 'PK-10' => ['10 pcs']]);
        $this->linkForUpdate($pid);
        $this->itemHolds([
            'tier_variation' => [['name' => 'Pack size', 'option_list' => [['option' => '5 pcs'], ['option' => '10 pcs']]]],
            'model' => [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
            ],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('status');

        $this->assertNull($this->postIndex('/api/v2/product/update_tier_variation'));
        $this->assertNotNull($this->postIndex('/api/v2/product/update_item'));

        $this->sentPosts = [];
        $this->itemHolds([
            'standardise_tier_variation' => [['variation_id' => 305033, 'variation_name' => 'Size', 'variation_option_list' => [
                ['variation_option_id' => 24472486, 'variation_option_name' => 'Five'],
                ['variation_option_id' => 24472485, 'variation_option_name' => 'Ten'],
            ]]],
            'model' => [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
            ],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('status');

        $this->assertNull($this->postIndex('/api/v2/product/update_tier_variation'), 'A type the category defines is never renamed.');
        $this->assertNotNull($this->postIndex('/api/v2/product/update_item'));
    }

    public function test_a_rename_that_cannot_be_paired_is_skipped_and_the_update_goes(): void
    {
        $pid = $this->variationProduct(['Pack size' => ['5 pcs', '10 pcs']], ['PK-5' => ['5 pcs'], 'PK-10' => ['10 pcs']]);
        $this->linkForUpdate($pid);
        ShopeeListing::query()->where('product_id', $pid)->update(['last_push_error' => 'An older failure.', 'last_push_failed_at' => now()]);
        $this->itemHolds([
            'tier_variation' => [['name' => 'Size', 'option_list' => [['option' => 'Five'], ['option' => 'Ten']]]],
            'model' => [
                ['model_id' => 81, 'model_sku' => 'OLD-5', 'tier_index' => [0]],
                ['model_id' => 82, 'model_sku' => 'OLD-10', 'tier_index' => [1]],
            ],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringStartsWith('Update pushed.', $status);
        $this->assertStringContainsString('Variation names were not changed: none of the item\'s variations on Shopee carries a SKU the catalog holds.', $status);
        $this->assertNull($this->postIndex('/api/v2/product/update_tier_variation'));
        $this->assertNotNull($this->postIndex('/api/v2/product/update_item'), 'A skipped rename never holds the update back.');
        $this->assertNull(ShopeeListing::query()->where('product_id', $pid)->value('last_push_error'), 'The update succeeded, so the success is recorded.');

        $this->sentPosts = [];
        $this->getResponses['/api/v2/product/get_model_list'] = ['ok' => false, 'status' => 500, 'body' => ['error' => 'error_server', 'message' => 'Something wrong.']];

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('status');

        $this->assertStringContainsString('Variation names were not changed: Shopee did not say what variations the item has', (string) session('status'));
        $this->assertNull($this->postIndex('/api/v2/product/update_tier_variation'));
        $this->assertNotNull($this->postIndex('/api/v2/product/update_item'));
    }

    private function threePacksOnTheItem(): int
    {
        $pid = $this->variationProduct(
            ['Pack size' => ['5 pcs', '10 pcs', '20 pcs']],
            ['PK-5' => ['5 pcs'], 'PK-10' => ['10 pcs'], 'PK-20' => ['20 pcs']]
        );
        $this->linkForUpdate($pid);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 800, 'shopee_model_id' => 82, 'sku' => 'PK-10']);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 800, 'shopee_model_id' => 83, 'sku' => 'PK-20']);

        return $pid;
    }

    private function packTier(): array
    {
        return [['name' => 'Pack size', 'option_list' => [['option' => '5 pcs'], ['option' => '10 pcs'], ['option' => '20 pcs']]]];
    }

    private function storeId(): int
    {
        return (int) ShopeeSetting::defaultStore()->id;
    }

    private function sentTo(string $path): array
    {
        return array_values(array_map(fn ($p) => $p['body'], array_filter($this->sentPosts, fn ($p) => $p['path'] === $path)));
    }

    private function rememberedSkus(int $pid): array
    {
        $skus = (array) json_decode((string) DB::table(\App\Integrations\Listings\ListingVariations::STORE_SKUS)
            ->where('channel', 'shopee')->where('product_id', $pid)->value('skus'), true);
        sort($skus);

        return $skus;
    }

    public function test_a_switched_off_variation_has_its_model_deleted_and_nothing_else(): void
    {
        $pid = $this->threePacksOnTheItem();
        \App\Integrations\Listings\ListingVariations::save('shopee', $this->storeId(), $pid, ['PK-5', 'PK-20']);
        $this->itemHolds([
            'tier_variation' => $this->packTier(),
            'model' => [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
                ['model_id' => 83, 'model_sku' => 'PK-20', 'tier_index' => [2]],
            ],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('status');

        $this->assertSame([['item_id' => 800, 'model_id' => 82]], $this->sentTo('/api/v2/product/delete_model'), 'Only the switched-off model is deleted.');
        foreach (['/api/v2/product/add_model', '/api/v2/product/update_tier_variation', '/api/v2/product/init_tier_variation'] as $path) {
            $this->assertSame([], $this->sentTo($path), $path . ' must not touch the other models.');
        }
        $this->assertNotNull($this->postIndex('/api/v2/product/update_item'), 'The rest of the update still goes.');

        $status = (string) session('status');
        $this->assertStringStartsWith('Update pushed.', $status);
        $this->assertStringContainsString('Removed from Shopee: 10 pcs (PK-10).', $status);

        $this->assertSame([81, 83], ShopeeProductLink::query()->where('product_id', $pid)->orderBy('shopee_model_id')
            ->pluck('shopee_model_id')->map(fn ($v) => (int) $v)->all(), 'The deleted model takes its link with it.');
        $this->assertSame(['PK-20', 'PK-5'], $this->rememberedSkus($pid), 'The item is remembered holding what is left.');
        $this->assertNull(ShopeeListing::query()->where('product_id', $pid)->value('last_push_error'));
    }

    public function test_switching_it_back_on_adds_it_through_the_add_path(): void
    {
        $pid = $this->threePacksOnTheItem();
        ShopeeProductLink::query()->where('shopee_model_id', 82)->delete();
        $reads = 0;
        $this->getResponses['/api/v2/product/get_model_list'] = function () use (&$reads) {
            $reads++;
            $models = [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0], 'price_info' => [['original_price' => 100]]],
                ['model_id' => 83, 'model_sku' => 'PK-20', 'tier_index' => [2], 'price_info' => [['original_price' => 100]]],
            ];
            if ($reads > 1) {
                $models[] = ['model_id' => 84, 'model_sku' => 'PK-10', 'tier_index' => [1]];
            }

            return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['tier_variation' => $this->packTier(), 'model' => $models]]];
        };

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('status');

        $added = $this->sentTo('/api/v2/product/add_model');
        $this->assertCount(1, $added);
        $this->assertSame(800, (int) $added[0]['item_id']);
        $this->assertSame('PK-10', $added[0]['model_list'][0]['model_sku']);
        $this->assertSame([1], $added[0]['model_list'][0]['tier_index'], 'It returns on the option it already had.');
        $this->assertSame([], $this->sentTo('/api/v2/product/update_tier_variation'));
        $this->assertSame([], $this->sentTo('/api/v2/product/delete_model'));
        $this->assertSame(2, $reads, 'One read for the update, one after the add to learn the new model.');

        $this->assertStringContainsString('Added to Shopee: 10 pcs (PK-10).', (string) session('status'));
        $this->assertTrue(ShopeeProductLink::query()->where('product_id', $pid)->where('shopee_model_id', 84)->exists());
        $this->assertSame(['PK-10', 'PK-20', 'PK-5'], $this->rememberedSkus($pid));
    }

    public function test_a_refused_delete_is_the_listings_error(): void
    {
        $pid = $this->threePacksOnTheItem();
        \App\Integrations\Listings\ListingVariations::save('shopee', $this->storeId(), $pid, ['PK-5', 'PK-20']);
        $this->itemHolds([
            'tier_variation' => $this->packTier(),
            'model' => [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
                ['model_id' => 83, 'model_sku' => 'PK-20', 'tier_index' => [2]],
            ],
        ]);
        $this->postResponses['/api/v2/product/delete_model'] = ['ok' => true, 'status' => 200, 'body' => [
            'error' => 'error_model_remove_model_in_promotion', 'message' => 'Model cannot be deleted when item/model is under promotion or groupbuy.',
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('error');

        $error = (string) ShopeeListing::query()->where('product_id', $pid)->value('last_push_error');
        $this->assertStringStartsWith('Not removed from Shopee: 10 pcs (PK-10) (', $error);
        $this->assertStringContainsString('promotion', $error, 'Shopee\'s reason is kept.');
        $this->assertStringContainsString('Update pushed.', (string) session('error'));
        $this->assertTrue(ShopeeProductLink::query()->where('shopee_model_id', 82)->exists(), 'A model still live keeps its link.');
        $this->assertSame([], $this->sentTo('/api/v2/product/add_model'));
    }

    public function test_the_last_remaining_model_is_never_deleted(): void
    {
        $pid = $this->variationProduct(['Pack size' => ['5 pcs', '10 pcs']], ['PK-5' => ['5 pcs'], 'PK-10' => ['10 pcs']]);
        ShopeeProductLink::create(['product_id' => $pid, 'shopee_item_id' => 800, 'shopee_model_id' => 82, 'sku' => 'PK-10']);
        ShopeeListing::create(['product_id' => $pid, 'shopee_category_id' => 100139, 'logistic_ids' => [8003]]);
        \App\Integrations\Listings\ListingVariations::save('shopee', $this->storeId(), $pid, ['PK-5']);
        $this->itemHolds([
            'tier_variation' => [['name' => 'Pack size', 'option_list' => [['option' => '5 pcs'], ['option' => '10 pcs']]]],
            'model' => [['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1], 'price_info' => [['original_price' => 100]]]],
        ]);
        $this->postResponses['/api/v2/product/add_model'] = ['ok' => true, 'status' => 200, 'body' => [
            'error' => 'error_param', 'message' => 'Model tier_index err.',
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('error');

        $this->assertSame([], $this->sentTo('/api/v2/product/delete_model'), 'The last model stays while nothing replaced it.');
        $error = (string) ShopeeListing::query()->where('product_id', $pid)->value('last_push_error');
        $this->assertStringContainsString('Not added to Shopee: 5 pcs (PK-5)', $error);
        $this->assertStringContainsString('Not removed from Shopee: 10 pcs (PK-10) (a Shopee item keeps at least one variation', $error);

        $this->sentPosts = [];
        unset($this->postResponses['/api/v2/product/add_model']);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.listings.push_update', $pid))
            ->assertSessionHas('status');

        $this->assertSame([['item_id' => 800, 'model_id' => 82]], $this->sentTo('/api/v2/product/delete_model'));
        $this->assertLessThan($this->postIndex('/api/v2/product/delete_model'), $this->postIndex('/api/v2/product/add_model'));
        $status = (string) session('status');
        $this->assertStringContainsString('Removed from Shopee: 10 pcs (PK-10).', $status);
        $this->assertStringContainsString('Added to Shopee: 5 pcs (PK-5).', $status);
        $this->assertNull(ShopeeListing::query()->where('product_id', $pid)->value('last_push_error'));
    }

    public function test_every_variation_off_is_refused_and_nothing_is_deleted(): void
    {
        $pid = $this->threePacksOnTheItem();
        \App\Integrations\Listings\ListingVariations::save('shopee', $this->storeId(), $pid, []);
        $this->itemHolds([
            'tier_variation' => $this->packTier(),
            'model' => [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
                ['model_id' => 83, 'model_sku' => 'PK-20', 'tier_index' => [2]],
            ],
        ]);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Packs', 'shopee_setting_id' => $this->storeId(),
            'shopee_category_id' => 100139, 'logistic_ids' => [8003],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.updateProduct', $group->id), ['ids' => [$pid]])
            ->assertRedirect();

        $this->assertSame([], $this->sentTo('/api/v2/product/delete_model'));
        $this->assertSame(
            \App\Integrations\Listings\ListingVariations::noneSoldMessage('Shopee'),
            ShopeeListing::query()->where('product_id', $pid)->value('last_push_error')
        );
    }

    public function test_the_group_update_deletes_a_switched_off_variation(): void
    {
        $pid = $this->threePacksOnTheItem();
        \App\Integrations\Listings\ListingVariations::save('shopee', $this->storeId(), $pid, ['PK-5', 'PK-10']);
        $this->itemHolds([
            'tier_variation' => $this->packTier(),
            'model' => [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
                ['model_id' => 83, 'model_sku' => 'PK-20', 'tier_index' => [2]],
            ],
        ]);
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Packs', 'shopee_setting_id' => $this->storeId(),
            'shopee_category_id' => 100139, 'logistic_ids' => [8003],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.updateProduct', $group->id), ['ids' => [$pid]])
            ->assertSessionHas('status');

        $this->assertSame([['item_id' => 800, 'model_id' => 83]], $this->sentTo('/api/v2/product/delete_model'));
        $this->assertStringContainsString("#{$pid}: Removed from Shopee: 20 pcs (PK-20).", (string) session('status'));
        $this->assertFalse(ShopeeProductLink::query()->where('shopee_model_id', 83)->exists());
    }

    public function test_a_refused_rename_call_warns_and_the_update_goes(): void
    {
        $pid = $this->variationProduct(['Pack size' => ['5 pcs', '10 pcs']], ['PK-5' => ['5 pcs'], 'PK-10' => ['10 pcs']]);
        $this->linkForUpdate($pid);
        $this->itemHolds([
            'tier_variation' => [['name' => 'Size', 'option_list' => [['option' => 'Five'], ['option' => 'Ten']]]],
            'model' => [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
            ],
        ]);
        $this->postResponses['/api/v2/product/update_tier_variation'] = ['ok' => true, 'status' => 200, 'body' => [
            'error' => 'error_in_item_promotion_remove_model', 'message' => 'Can\'t change tier_variation when item is under promotion.',
        ]];
        $group = \Extensions\shopee\Models\ShopeeProductGroup::create([
            'name' => 'Packs', 'shopee_setting_id' => (int) ShopeeSetting::query()->value('id'),
            'shopee_category_id' => 100139, 'logistic_ids' => [8003],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.product-groups.updateProduct', $group->id), ['ids' => [$pid]])
            ->assertSessionHas('status');

        $status = (string) session('status');
        $this->assertStringStartsWith('Update: 1 updated', $status);
        $this->assertStringContainsString("#{$pid}: Variation names were not changed: Shopee refused them", $status);
        $this->assertSame(1, collect($this->sentPosts)->where('path', '/api/v2/product/update_tier_variation')->count(), 'One attempt, never a second half.');
        $this->assertNotNull($this->postIndex('/api/v2/product/update_item'));
        $this->assertLessThan($this->postIndex('/api/v2/product/update_item'), $this->postIndex('/api/v2/product/update_tier_variation'));
    }

    public function test_the_selection_bars_send_updates_a_product_already_on_shopee(): void
    {
        $pid = $this->variationProduct(['Pack size' => ['5 pcs', '10 pcs']], ['PK-5' => ['5 pcs'], 'PK-10' => ['10 pcs']]);
        $this->linkForUpdate($pid);
        $this->itemHolds([
            'tier_variation' => [['name' => 'Pack size', 'option_list' => [['option' => '5 pcs'], ['option' => '10 pcs']]]],
            'model' => [
                ['model_id' => 81, 'model_sku' => 'PK-5', 'tier_index' => [0]],
                ['model_id' => 82, 'model_sku' => 'PK-10', 'tier_index' => [1]],
            ],
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.products.bulk_push'), ['product_ids' => [$pid]])
            ->assertSessionMissing('error');

        $this->assertNotNull($this->postIndex('/api/v2/product/update_item'), 'the live item is updated');
        $this->assertNull($this->postIndex('/api/v2/product/add_item'), 'nothing new is created on Shopee');
    }
}
