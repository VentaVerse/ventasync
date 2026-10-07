<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LazadaListingLiveTest extends TestCase
{
    use RefreshDatabase;

    public array $getResponses = [];

    public array $sentPosts = [];

    public array $postResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        LazadaSetting::query()->create([
            'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r',
        ]);

        $test = $this;
        $fake = new class($test) extends LazadaClient {
            public function __construct(private $test) {}
            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $this->test->sentPosts[] = ['path' => $apiPath, 'params' => $params];

                $answer = $this->test->postResponses[$apiPath] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($params);
                }

                return $answer ?? ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];
            }
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                $answer = $this->test->getResponses[$apiPath] ?? null;
                if ($answer instanceof \Closure) {
                    return $answer($params);
                }

                return $answer
                    ?? ['ok' => false, 'status' => 500, 'body' => ['code' => '500', 'message' => 'unexpected GET ' . $apiPath]];
            }
            public function sign(string $apiPath, array $params, string $appSecret): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(LazadaClient::class, $fake);
    }

    private int $groupSeq = 0;

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada live group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', [
                'manage_lazada/product', 'view_lazada/product',
                'manage_lazada/product_group', 'view_lazada/product_group',
            ])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId,
            'name' => $name, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    private function fakeProductsGet(array $idsByFilter, array $statusById = []): void
    {
        $this->getResponses['/products/get'] = function (array $params) use ($idsByFilter, $statusById) {
            if (!empty($params['sku_seller_list'])) {
                $products = [];
                foreach ($statusById as $id => $status) {
                    $products[] = ['item_id' => $id, 'status' => $status];
                }

                return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
                    'total_products' => count($products), 'products' => $products,
                ]]];
            }

            $ids = $idsByFilter[$params['filter'] ?? ''] ?? [];
            $products = array_map(fn ($i) => ['item_id' => $i, 'status' => 'x'], $ids);

            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
                'total_products' => count($ids),
                'products' => ((int) ($params['limit'] ?? 1)) === 1 ? array_slice($products, 0, 1) : $products,
            ]]];
        };
    }

    private int $optSeq = 700;

    private function addOption(int $pid, string $name, array $values, array $skus = []): array
    {
        $pfx = (string) config('catalog.prefix');
        $lang = (int) config('catalog.default_language_id');
        $optionId = ++$this->optSeq;
        DB::table($pfx . 'option')->insert(['option_id' => $optionId, 'type' => 'select', 'sort_order' => 0]);
        DB::table($pfx . 'option_description')->insert(['option_id' => $optionId, 'language_id' => $lang, 'name' => $name]);
        $productOptionId = DB::table($pfx . 'product_option')->insertGetId(['product_id' => $pid, 'option_id' => $optionId, 'value' => '', 'required' => 1]);

        $out = [];
        foreach (array_values($values) as $i => $value) {
            $valueId = $optionId * 10 + $i;
            DB::table($pfx . 'option_value')->insert(['option_value_id' => $valueId, 'option_id' => $optionId, 'image' => '', 'sort_order' => $i]);
            DB::table($pfx . 'option_value_description')->insert(['option_value_id' => $valueId, 'language_id' => $lang, 'option_id' => $optionId, 'name' => $value]);
            $out[$value] = (int) DB::table($pfx . 'product_option_value')->insertGetId([
                'product_option_id' => $productOptionId, 'product_id' => $pid, 'option_id' => $optionId, 'option_value_id' => $valueId,
                'sku' => $skus[$value] ?? '', 'quantity' => 3, 'subtract' => 1, 'price' => 0, 'price_prefix' => '+', 'absolute_price' => 100,
                'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0, 'absolute_cost' => 0, 'cost_prefix' => '+',
                'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
            ]);
        }

        return $out;
    }

    private function productWithPicture(string $sku): int
    {
        $pid = $this->seedProduct('Qable IC50', $sku);
        DB::table((string) config('catalog.prefix') . 'product')->where('product_id', $pid)->update(['image' => 'catalog/qable.png']);

        return $pid;
    }

    private function listingOn(int $pid, ?string $itemId): LazadaProduct
    {
        return LazadaProduct::query()->create([
            'product_id' => $pid, 'lazada_setting_id' => (int) LazadaSetting::defaultStore()->id,
            'lazada_item_id' => $itemId, 'primary_category_id' => $itemId !== null ? 9257 : null,
        ]);
    }

    private function skusSent(LazadaProduct $listing): array
    {
        [$payload] = app(\Extensions\lazada\Services\Lazada\LazadaPushPayload::class)
            ->buildLazadaProductCreatePayload($listing, LazadaSetting::defaultStore()->decrypted(), app(LazadaClient::class));

        return $payload['Request']['Product']['Skus']['Sku'];
    }

    private function liveItemSkus(array $skus): void
    {
        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 2712258176, 'skus' => $skus,
        ]]];
    }

    public function test_an_update_sends_the_variation_type_the_item_already_has(): void
    {
        $pid = $this->productWithPicture('QB-IC50');
        $this->addOption($pid, 'Length', ['1ft', '3ft'], ['1ft' => 'QB-IC50-1', '3ft' => 'QB-IC50-3']);
        $this->liveItemSkus([
            ['SkuId' => 1, 'SellerSku' => 'QB-IC50-1', 'saleProp' => ['Length' => '1ft']],
            ['SkuId' => 2, 'SellerSku' => 'QB-IC50-3', 'saleProp' => ['Length' => '3ft']],
        ]);

        $skus = $this->skusSent($this->listingOn($pid, '2712258176'));

        $this->assertSame(['1ft', '3ft'], array_column($skus, 'Length'));
        foreach ($skus as $sku) {
            $this->assertArrayNotHasKey('color_family', $sku, 'an update must never add a variation type the item does not have');
        }
    }

    public function test_an_item_with_two_variation_types_keeps_both_in_their_order(): void
    {
        $pid = $this->productWithPicture('QB-TWO');
        $length = $this->addOption($pid, 'Length', ['1ft', '3ft']);
        $colour = $this->addOption($pid, 'Colour', ['Black']);
        foreach (['1ft', '3ft'] as $i => $value) {
            $comboId = DB::table('product_option_combinations')->insertGetId([
                'product_id' => $pid, 'sku' => 'QB-TWO-' . $value, 'status' => 1, 'quantity' => 5,
                'absolute_price' => 100, 'absolute_cost' => 0, 'cost_amount' => 0, 'cost_additional' => 0,
                'subtract' => 1, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_option_combination_values')->insert([
                ['combination_id' => $comboId, 'product_option_value_id' => $length[$value]],
                ['combination_id' => $comboId, 'product_option_value_id' => $colour['Black']],
            ]);
        }
        $this->liveItemSkus([
            ['SkuId' => 1, 'SellerSku' => 'QB-TWO-1ft', 'saleProp' => ['Length' => '1ft', 'Color' => 'Black']],
            ['SkuId' => 2, 'SellerSku' => 'QB-TWO-3ft', 'saleProp' => ['Length' => '3ft', 'Color' => 'Black']],
        ]);

        $skus = $this->skusSent($this->listingOn($pid, '2712258176'));

        $this->assertSame([['1ft', 'Black'], ['3ft', 'Black']], array_map(fn ($s) => [$s['Length'] ?? null, $s['Color'] ?? null], $skus));
        $this->assertArrayNotHasKey('color_family', $skus[0]);
    }

    public function test_a_new_item_still_takes_its_variation_type_from_the_category_or_the_name(): void
    {
        $pid = $this->productWithPicture('QB-NEW');
        $this->addOption($pid, 'Color', ['Black', 'Red'], ['Black' => 'QB-NEW-B', 'Red' => 'QB-NEW-R']);
        $asked = 0;
        $this->getResponses['/product/item/get'] = function () use (&$asked) {
            $asked++;

            return ['ok' => false, 'status' => 500, 'body' => ['code' => '500', 'message' => 'must not be asked']];
        };

        $skus = $this->skusSent($this->listingOn($pid, null));

        $this->assertSame(['Black', 'Red'], array_column($skus, 'color_family'));
        $this->assertSame(0, $asked, 'an item not on Lazada yet has nothing to read');
    }

    public function test_an_update_lazada_will_not_describe_is_refused_rather_than_guessed(): void
    {
        $pid = $this->productWithPicture('QB-QUIET');
        $this->addOption($pid, 'Length', ['1ft', '3ft'], ['1ft' => 'QB-Q-1', '3ft' => 'QB-Q-3']);

        try {
            $this->skusSent($this->listingOn($pid, '2712258176'));
            $this->fail('the update must be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString('did not say which variation types', implode(' ', $e->errors()['variants'] ?? []));
        }
        $this->assertEmpty($this->sentPosts, 'nothing is sent');
    }

    private function payloadSent(LazadaProduct $listing): array
    {
        return app(\Extensions\lazada\Services\Lazada\LazadaPushPayload::class)
            ->buildLazadaProductCreatePayload($listing, LazadaSetting::defaultStore()->decrypted(), app(LazadaClient::class));
    }

    private function liveItem(array $skus, ?array $variation = null): void
    {
        $data = ['item_id' => 2712258176, 'skus' => $skus];
        if ($variation !== null) {
            $data['variation'] = $variation;
        }
        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => $data]];
    }

    private function twoOptionProduct(string $sku, string $first, array $firstValues, string $second, string $secondValue): int
    {
        $pid = $this->productWithPicture($sku);
        $a = $this->addOption($pid, $first, $firstValues);
        $b = $this->addOption($pid, $second, [$secondValue]);
        foreach ($firstValues as $i => $value) {
            $comboId = DB::table('product_option_combinations')->insertGetId([
                'product_id' => $pid, 'sku' => $sku . '-' . $value, 'status' => 1, 'quantity' => 5,
                'absolute_price' => 100, 'absolute_cost' => 0, 'cost_amount' => 0, 'cost_additional' => 0,
                'subtract' => 1, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_option_combination_values')->insert([
                ['combination_id' => $comboId, 'product_option_value_id' => $a[$value]],
                ['combination_id' => $comboId, 'product_option_value_id' => $b[$secondValue]],
            ]);
        }

        return $pid;
    }

    public function test_swapped_variation_types_pair_by_name(): void
    {
        $pid = $this->twoOptionProduct('QB-SWAP', 'cable length', ['1ft', '3ft'], 'Color', 'Black');
        $this->liveItemSkus([
            ['SkuId' => 1, 'SellerSku' => 'QB-SWAP-1ft', 'saleProp' => ['Color' => 'Black', 'Cable_Length' => '1ft']],
            ['SkuId' => 2, 'SellerSku' => 'QB-SWAP-3ft', 'saleProp' => ['Color' => 'Black', 'Cable_Length' => '3ft']],
        ]);

        $skus = $this->skusSent($this->listingOn($pid, '2712258176'));

        $this->assertSame([['1ft', 'Black'], ['3ft', 'Black']], array_map(fn ($s) => [$s['Cable_Length'] ?? null, $s['Color'] ?? null], $skus));
    }

    public function test_orders_the_names_cannot_settle_are_refused_with_nothing_sent(): void
    {
        $pid = $this->twoOptionProduct('QB-KNOT', 'Size', ['S', 'M'], 'Colour', 'Black');
        $this->liveItemSkus([
            ['SkuId' => 1, 'SellerSku' => 'QB-KNOT-S', 'saleProp' => ['Color' => 'Black', 'Size' => 'S']],
            ['SkuId' => 2, 'SellerSku' => 'QB-KNOT-M', 'saleProp' => ['Color' => 'Black', 'Size' => 'M']],
        ]);

        try {
            $this->skusSent($this->listingOn($pid, '2712258176'));
            $this->fail('the update must be refused');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $said = implode(' ', $e->errors()['variants'] ?? []);
            $this->assertStringContainsString('in the order Color, Size', $said);
            $this->assertStringContainsString('your product has Size, Colour', $said);
        }
        $this->assertEmpty($this->sentPosts, 'nothing is sent');
    }

    public function test_a_single_type_item_is_not_paired_by_name(): void
    {
        $pid = $this->productWithPicture('QB-ONE');
        $this->addOption($pid, 'Cable Length', ['1ft', '3ft'], ['1ft' => 'QB-ONE-1', '3ft' => 'QB-ONE-3']);
        $this->liveItemSkus([
            ['SkuId' => 1, 'SellerSku' => 'QB-ONE-1', 'saleProp' => ['Length' => '1ft']],
            ['SkuId' => 2, 'SellerSku' => 'QB-ONE-3', 'saleProp' => ['Length' => '3ft']],
        ]);

        [$payload] = $this->payloadSent($this->listingOn($pid, '2712258176'));
        $skus = $payload['Request']['Product']['Skus']['Sku'];

        $this->assertSame(['1ft', '3ft'], array_column($skus, 'Length'));
        $this->assertArrayNotHasKey('Cable Length', $skus[0]);
        $this->assertArrayNotHasKey('variation', $payload['Request']['Product'], 'no variation object without one in the read');
    }

    public function test_an_update_leaves_package_fields_off_when_the_variations_already_differ(): void
    {
        $pid = $this->productWithPicture('QB-PKG');
        $this->addOption($pid, 'Length', ['1ft', '3ft'], ['1ft' => 'QB-PKG-1', '3ft' => 'QB-PKG-3']);
        $this->liveItemSkus([
            ['SkuId' => 1, 'SellerSku' => 'QB-PKG-1', 'saleProp' => ['Length' => '1ft'], 'package_weight' => '0.10', 'package_length' => '10.00', 'package_width' => '10.00', 'package_height' => '4.00'],
            ['SkuId' => 2, 'SellerSku' => 'QB-PKG-3', 'saleProp' => ['Length' => '3ft'], 'package_weight' => '0.30', 'package_length' => '10.00', 'package_width' => '10.00', 'package_height' => '4.00'],
        ]);

        $skus = $this->skusSent($this->listingOn($pid, '2712258176'));

        foreach ($skus as $sku) {
            foreach (['package_weight', 'package_length', 'package_width', 'package_height'] as $field) {
                $this->assertArrayNotHasKey($field, $sku, 'Lazada keeps each variation its own ' . $field);
            }
        }
    }

    public function test_an_update_sends_our_package_fields_when_the_variations_agree(): void
    {
        $pid = $this->productWithPicture('QB-PKGEQ');
        $this->addOption($pid, 'Length', ['1ft', '3ft'], ['1ft' => 'QB-PKGEQ-1', '3ft' => 'QB-PKGEQ-3']);
        $this->liveItemSkus([
            ['SkuId' => 1, 'SellerSku' => 'QB-PKGEQ-1', 'saleProp' => ['Length' => '1ft'], 'package_weight' => '0.20', 'package_length' => '10.00'],
            ['SkuId' => 2, 'SellerSku' => 'QB-PKGEQ-3', 'saleProp' => ['Length' => '3ft'], 'package_weight' => '0.2', 'package_length' => '10'],
        ]);

        $skus = $this->skusSent($this->listingOn($pid, '2712258176'));

        foreach ($skus as $sku) {
            $this->assertEquals(0.5, (float) $sku['package_weight'], "the catalog's weight goes up");
            $this->assertEquals(10.0, (float) $sku['package_length']);
            $this->assertEquals(10.0, (float) $sku['package_width']);
            $this->assertEquals(5.0, (float) $sku['package_height']);
        }
    }

    public function test_a_create_still_sends_the_package_fields(): void
    {
        $pid = $this->productWithPicture('QB-PKGNEW');
        $this->addOption($pid, 'Color', ['Black', 'Red'], ['Black' => 'QB-PKGNEW-B', 'Red' => 'QB-PKGNEW-R']);

        $skus = $this->skusSent($this->listingOn($pid, null));

        foreach ($skus as $sku) {
            $this->assertEquals(0.5, (float) $sku['package_weight']);
            $this->assertEquals(5.0, (float) $sku['package_height']);
        }
    }

    private function cableLengthProduct(string $sku): int
    {
        $pid = $this->productWithPicture($sku);
        $this->addOption($pid, 'Cable Length', ['1ft', '3ft'], ['1ft' => $sku . '-1', '3ft' => $sku . '-3']);

        return $pid;
    }

    private function lengthSkus(string $sku, string $key = 'Length'): array
    {
        return [
            ['SkuId' => 1, 'SellerSku' => $sku . '-1', 'saleProp' => [$key => '1ft']],
            ['SkuId' => 2, 'SellerSku' => $sku . '-3', 'saleProp' => [$key => '3ft']],
        ];
    }

    public function test_a_custom_variation_type_is_renamed_to_the_catalog_name_and_the_skus_keep_the_live_key(): void
    {
        $pid = $this->cableLengthProduct('QB-REN');
        $this->liveItem($this->lengthSkus('QB-REN'), [
            'variation1' => ['has_image' => 'false', 'name' => 'Length', 'options' => [], 'label' => 'Length', 'customize' => 'true'],
        ]);

        [$payload, $preview] = $this->payloadSent($this->listingOn($pid, '2712258176'));
        $product = $payload['Request']['Product'];

        $this->assertSame(['Variation1' => ['name' => 'Cable Length', 'has_image' => 'false', 'customize' => true]], $product['variation']);
        $this->assertSame(['1ft', '3ft'], array_column($product['Skus']['Sku'], 'Length'), 'the SKUs keep the live key');
        $this->assertArrayNotHasKey('Cable Length', $product['Skus']['Sku'][0]);
        $this->assertSame([['from' => 'Length', 'to' => 'Cable Length']], $preview['variation_renames']);
    }

    public function test_a_standard_variation_type_is_never_renamed(): void
    {
        $pid = $this->cableLengthProduct('QB-STD');
        $this->liveItem($this->lengthSkus('QB-STD'), [
            'variation1' => ['has_image' => 'false', 'name' => 'Length', 'options' => [], 'customize' => 'false'],
        ]);
        [$payload, $preview] = $this->payloadSent($this->listingOn($pid, '2712258176'));
        $this->assertArrayNotHasKey('variation', $payload['Request']['Product']);
        $this->assertSame([], $preview['variation_renames']);

        $colour = $this->productWithPicture('QB-CF');
        $this->addOption($colour, 'Colour', ['Black', 'Red'], ['Black' => 'QB-CF-B', 'Red' => 'QB-CF-R']);
        $this->liveItem([
            ['SkuId' => 1, 'SellerSku' => 'QB-CF-B', 'saleProp' => ['color_family' => 'Black']],
            ['SkuId' => 2, 'SellerSku' => 'QB-CF-R', 'saleProp' => ['color_family' => 'Red']],
        ], ['variation1' => ['has_image' => 'true', 'name' => 'color_family', 'options' => [], 'customize' => 'true']]);
        [$payload] = $this->payloadSent($this->listingOn($colour, '2712258177'));
        $this->assertArrayNotHasKey('variation', $payload['Request']['Product']);
        $this->assertSame(['Black', 'Red'], array_column($payload['Request']['Product']['Skus']['Sku'], 'color_family'));
    }

    private function pushRenameThroughTheButton(string $sku, array $after): int
    {
        $pid = $this->cableLengthProduct($sku);
        $this->listingOn($pid, '2712258176');
        \Extensions\lazada\Models\LazadaCategoryTemplate::create([
            'region' => 'ph', 'primary_category_id' => 9257,
            'template_body' => ['data' => ['attributes' => []]], 'fetched_at' => now(),
        ]);
        $this->postResponses['/image/migrate'] = ['ok' => true, 'status' => 200, 'body' => [
            'code' => '0', 'data' => ['image' => ['url' => 'https://sg-live.slatic.net/p/fake.jpg']],
        ]];
        $before = ['item_id' => 2712258176, 'skus' => $this->lengthSkus($sku), 'variation' => [
            'variation1' => ['has_image' => 'false', 'name' => 'Length', 'options' => [], 'customize' => 'true'],
        ]];
        $reads = 0;
        $this->getResponses['/product/item/get'] = function () use (&$reads, $before, $after) {
            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => $reads++ === 0 ? $before : $after]];
        };

        $this->actingAs($this->manager())->post(route('ext.lazada.products.upload', $pid))->assertRedirect();

        $update = collect($this->sentPosts)->firstWhere('path', '/product/update');
        $this->assertNotNull($update, 'the update reaches Lazada');
        $sent = json_decode($update['params']['payload'], true)['Request']['Product'];
        $this->assertSame('Cable Length', $sent['variation']['Variation1']['name']);
        $this->assertSame(2, $reads, 'one read before the update, one after');

        return $pid;
    }

    public function test_a_read_back_showing_both_names_records_the_error(): void
    {
        $pid = $this->pushRenameThroughTheButton('QB-BOTH', ['item_id' => 2712258176, 'skus' => [
            ['SkuId' => 1, 'SellerSku' => 'QB-BOTH-1', 'saleProp' => ['Length' => '1ft', 'Cable Length' => '1ft']],
            ['SkuId' => 2, 'SellerSku' => 'QB-BOTH-3', 'saleProp' => ['Length' => '3ft', 'Cable Length' => '3ft']],
        ], 'variation' => [
            'variation1' => ['has_image' => 'false', 'name' => 'Length', 'options' => [], 'customize' => 'true'],
            'variation2' => ['has_image' => 'false', 'name' => 'Cable Length', 'options' => [], 'customize' => 'true'],
        ]]);

        $states = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class);
        $this->assertSame('Lazada kept Length and added Cable Length. Remove one in Seller Center.', $states->errors([$pid])[$pid]);
    }

    public function test_a_rename_that_cannot_be_decided_is_skipped_and_the_update_still_goes(): void
    {
        $pid = $this->cableLengthProduct('QB-BLIND');
        $this->listingOn($pid, '2712258176');
        \Extensions\lazada\Models\LazadaCategoryTemplate::create([
            'region' => 'ph', 'primary_category_id' => 9257,
            'template_body' => ['data' => ['attributes' => []]], 'fetched_at' => now(),
        ]);
        $this->postResponses['/image/migrate'] = ['ok' => true, 'status' => 200, 'body' => [
            'code' => '0', 'data' => ['image' => ['url' => 'https://sg-live.slatic.net/p/fake.jpg']],
        ]];
        $this->liveItem($this->lengthSkus('QB-BLIND'));

        $this->actingAs($this->manager())->post(route('ext.lazada.products.upload', $pid))
            ->assertRedirect()
            ->assertSessionHas('status', 'Update pushed. Variation names were not changed: Lazada did not say which variation types on this item are your own.');

        $update = collect($this->sentPosts)->firstWhere('path', '/product/update');
        $this->assertNotNull($update, 'the update still reaches Lazada');
        $sent = json_decode($update['params']['payload'], true)['Request']['Product'];
        $this->assertArrayNotHasKey('variation', $sent);
        $this->assertSame(['1ft', '3ft'], array_column($sent['Skus']['Sku'], 'Length'));
        $this->assertNull(app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->errors([$pid])[$pid]);
    }

    public function test_a_read_back_showing_only_the_old_name_records_nothing(): void
    {
        $pid = $this->pushRenameThroughTheButton('QB-OLD', ['item_id' => 2712258176, 'skus' => $this->lengthSkus('QB-OLD'), 'variation' => [
            'variation1' => ['has_image' => 'false', 'name' => 'Length', 'options' => [], 'customize' => 'true'],
        ]]);

        $states = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class);
        $this->assertNull($states->errors([$pid])[$pid]);
    }

    public function test_an_option_name_that_names_no_type_gets_no_guessed_key(): void
    {
        $attributes = app(\Extensions\lazada\Services\Lazada\LazadaAttributes::class);

        $this->assertSame([], $attributes->guessLazadaVariantKeysFromOptionName(''));
        $this->assertSame([], $attributes->guessLazadaVariantKeysFromOptionName('Finish'));
        $this->assertSame(['color_family'], $attributes->guessLazadaVariantKeysFromOptionName('Color'));
        $this->assertSame(['length'], $attributes->guessLazadaVariantKeysFromOptionName('Cable length'));
    }

    public function test_the_listing_keeps_its_own_words_and_the_push_sends_them(): void
    {
        $productId = $this->seedProduct('Catalog name', 'LZT-WORDS');
        $listing = LazadaProduct::query()->create(['product_id' => $productId]);

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $productId))->assertOk();
        $page->assertSee('Content on this channel');
        $page->assertSee('value="Catalog name"', false);

        $this->actingAs($this->manager())
            ->put(route('ext.lazada.products.update', $productId), [
                'item_name' => 'Lazada name', 'description' => 'Lazada words',
                'primary_category_id' => 300400, 'brand_id' => 5150,
            ])->assertSessionHasNoErrors();

        $listing->refresh();
        $this->assertSame('Lazada name', $listing->item_name);
        $this->assertSame('Lazada words', $listing->description);

        $pfx = (string) config('catalog.prefix');
        $this->assertSame('Catalog name', DB::table($pfx . 'product_description')->where('product_id', $productId)->value('name'));

        $this->actingAs($this->manager())
            ->put(route('ext.lazada.products.update', $productId), [
                'item_name' => '', 'description' => 'Lazada words',
                'primary_category_id' => 300400, 'brand_id' => 5150,
            ])->assertSessionHasErrors('item_name');
        $this->assertSame('Lazada name', $listing->fresh()->item_name);

        $this->actingAs($this->manager())
            ->put(route('ext.lazada.products.update', $productId), [
                'item_name' => 'Catalog name', 'description' => '',
                'primary_category_id' => 300400, 'brand_id' => 5150,
            ])->assertSessionHasNoErrors();
        $listing->refresh();
        $this->assertSame('Catalog name', $listing->item_name, 'the listing keeps what was saved, even when it matches the catalog');
        $this->assertNull($listing->description);
    }

    public function test_one_save_keeps_the_variation_price_and_never_a_sku_or_stock(): void
    {
        $productId = $this->seedProduct('Variant product', 'LZT-VAR');
        $listing = LazadaProduct::query()->create(['product_id' => $productId]);
        $pfx = (string) config('catalog.prefix');
        $povId = DB::table($pfx . 'product_option_value')->insertGetId([
            'product_option_id' => 1, 'product_id' => $productId, 'option_id' => 1, 'option_value_id' => 1,
            'quantity' => 3, 'subtract' => 1, 'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
            'weight' => 0, 'weight_prefix' => '+', 'sku' => 'LZT-VAR-RED',
        ]);

        $this->actingAs($this->manager())
            ->put(route('ext.lazada.products.update', $productId), [
                'primary_category_id' => 300400, 'brand_id' => 5150,
                'variants' => [$povId => ['seller_sku' => 'LZ-RED', 'price' => '199.5', 'quantity' => '7']],
            ])->assertSessionHasNoErrors();

        $variant = \Extensions\lazada\Models\LazadaProductVariant::query()
            ->where('lazada_product_id', $listing->id)->where('product_option_value_id', $povId)->first();
        $this->assertNotNull($variant, 'the listing form saves the variation price');
        $this->assertEquals(199.5, (float) $variant->price);
        $this->assertNull($variant->seller_sku, 'a typed SKU is not saved');
        $this->assertNull($variant->quantity, 'a typed stock count is not saved');
    }

    public function test_a_listing_price_is_the_start_the_price_rule_is_added_to(): void
    {
        $pid = $this->productWithPicture('LZ-OWNPRICE');
        $listing = $this->listingOn($pid, null);
        $listing->forceFill(['price' => 500, 'markup_percent' => 10])->save();

        $skus = $this->skusSent($listing->fresh());
        $this->assertCount(1, $skus);
        $this->assertEquals(550.0, (float) $skus[0]['price'], 'the listing price with 10 percent added');

        $listing->forceFill(['lazada_item_id' => '9001'])->save();
        \Extensions\lazada\Models\LazadaProductVariant::create(['lazada_product_id' => $listing->id, 'seller_sku' => 'LZ-OWNPRICE', 'sku_id' => 77]);
        $listings = LazadaProduct::query()->whereKey($listing->id)->get();
        $setting = LazadaSetting::defaultStore()->decrypted();
        app(\Extensions\lazada\Services\Lazada\LazadaStockPricePush::class)->push(
            'price', $setting, LazadaSetting::activeCredentials($setting), $listings,
            (string) config('catalog.prefix'), \Extensions\lazada\Commands\LazadaPushPrice::priceRule($listings)
        );

        $sent = collect($this->sentPosts)->firstWhere('path', '/product/price_quantity/update');
        $this->assertNotNull($sent, 'the price reaches Lazada');
        $this->assertStringContainsString('<Price>550.00</Price>', (string) $sent['params']['payload']);
    }

    public function test_a_blank_listing_price_starts_from_the_catalog_price(): void
    {
        $pid = $this->productWithPicture('LZ-CATPRICE');
        $listing = $this->listingOn($pid, null);
        $listing->forceFill(['price' => null, 'markup_percent' => 10])->save();

        $skus = $this->skusSent($listing->fresh());

        $this->assertEquals(110.0, (float) $skus[0]['price'], 'the catalog price of 100 with 10 percent added');
    }

    public function test_the_page_has_no_price_field_for_a_product_with_variations_and_no_sku_or_stock_input(): void
    {
        $pid = $this->productWithPicture('LZ-PAGEVAR');
        $povs = $this->addOption($pid, 'Color', ['Black', 'Red'], ['Black' => 'LZ-PAGEVAR-B', 'Red' => 'LZ-PAGEVAR-R']);
        LazadaProduct::query()->create(['product_id' => $pid]);

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $pid))->assertOk();
        $page->assertSee('Price rule');
        $page->assertDontSee('name="price"', false);
        $page->assertSee('name="variants[' . $povs['Red'] . '][price]"', false);
        $page->assertDontSee('[seller_sku]', false);
        $page->assertDontSee('[quantity]', false);
        $page->assertSee('name="package_length"', false);

        $simple = $this->seedProduct('Simple page product', 'LZ-PAGESIMPLE');
        LazadaProduct::query()->create(['product_id' => $simple]);
        $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $simple))->assertOk()
            ->assertSee('name="price"', false);
    }

    public function test_save_keeps_the_listing_price_and_parcel_and_no_price_for_a_product_with_variations(): void
    {
        $pid = $this->seedProduct('Saved parcel product', 'LZ-SAVE');
        $listing = LazadaProduct::query()->create(['product_id' => $pid]);

        $this->actingAs($this->manager())
            ->put(route('ext.lazada.products.update', $pid), [
                'primary_category_id' => 300400, 'brand_id' => 5150,
                'price' => '500', 'weight' => '1.2', 'package_length' => '30', 'package_width' => '', 'package_height' => '',
            ])->assertSessionHasNoErrors();

        $listing->refresh();
        $this->assertEquals(500.0, $listing->price);
        $this->assertEquals(1.2, $listing->weight);
        $this->assertSame(30, $listing->package_length);
        $this->assertNull($listing->package_width, 'a blank size follows the catalog');
        $this->assertNull($listing->package_height);

        $this->actingAs($this->manager())
            ->put(route('ext.lazada.products.update', $pid), [
                'primary_category_id' => 300400, 'brand_id' => 5150, 'weight' => '0', 'package_width' => '0',
            ])->assertSessionHasErrors(['weight', 'package_width']);

        $varied = $this->seedProduct('Saved variation product', 'LZ-SAVEVAR');
        $this->addOption($varied, 'Color', ['Black'], ['Black' => 'LZ-SAVEVAR-B']);
        $variedListing = LazadaProduct::query()->create(['product_id' => $varied]);
        $this->actingAs($this->manager())
            ->put(route('ext.lazada.products.update', $varied), [
                'primary_category_id' => 300400, 'brand_id' => 5150, 'price' => '500',
            ])->assertSessionHasNoErrors();
        $this->assertNull($variedListing->fresh()->price, 'a product with variations keeps no price of its own');
    }

    public function test_the_listing_parcel_is_sent_and_a_blank_field_follows_the_catalog(): void
    {
        $pid = $this->productWithPicture('LZ-PARCEL');
        $listing = $this->listingOn($pid, null);
        $listing->forceFill(['weight' => 1.25, 'package_length' => 30])->save();

        $skus = $this->skusSent($listing->fresh());

        $this->assertEquals(1.25, (float) $skus[0]['package_weight'], "the listing's own weight");
        $this->assertEquals(30.0, (float) $skus[0]['package_length'], "the listing's own length");
        $this->assertEquals(10.0, (float) $skus[0]['package_width'], "the catalog's width");
        $this->assertEquals(5.0, (float) $skus[0]['package_height'], "the catalog's height");
    }

    public function test_a_stored_sku_or_stock_override_is_never_sent(): void
    {
        $pid = $this->productWithPicture('LZ-OVR');
        $povs = $this->addOption($pid, 'Color', ['Black', 'Red'], ['Black' => 'LZ-OVR-B', 'Red' => 'LZ-OVR-R']);
        $listing = $this->listingOn($pid, null);
        \Extensions\lazada\Models\LazadaProductVariant::create([
            'lazada_product_id' => $listing->id, 'product_option_value_id' => $povs['Red'],
            'seller_sku' => 'LZ-TYPED', 'quantity' => 99, 'price' => 150,
        ]);

        $skus = collect($this->skusSent($listing))->keyBy('SellerSku');

        $this->assertEqualsCanonicalizing(['LZ-OVR-B', 'LZ-OVR-R'], $skus->keys()->all(), 'the catalog SKUs go up');
        $this->assertSame(3, (int) $skus['LZ-OVR-R']['quantity'], "the catalog's stock goes up");
        $this->assertEquals(150.0, (float) $skus['LZ-OVR-R']['price'], "the variation's own price still applies");
        $this->assertEquals(100.0, (float) $skus['LZ-OVR-B']['price']);
    }

    public function test_the_row_menu_push_opens_the_listing_page_and_the_page_carries_the_push(): void
    {
        $productId = $this->seedProduct('One push product', 'LZT-ONEPUSH');
        $listing = LazadaProduct::query()->create(['product_id' => $productId]);
        $this->fakeProductsGet(['live' => [], 'inactive' => [], 'pending' => [], 'rejected' => [], 'sold-out' => [], 'deleted' => []]);

        $index = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'))->assertOk();
        $index->assertSee('Send to Lazada');
        $index->assertDontSee('Upload to Lazada');

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk();
        $page->assertSee('Save and push to Lazada');
    }

    public function test_the_page_reads_the_mirror_and_asks_lazada_for_nothing(): void
    {
        $productId = $this->seedProduct('Mirrored product', 'LZT-MIR');
        LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '111',
            'live_status' => 'inactive', 'live_checked_at' => '2026-08-28 08:30:00',
        ]);

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));

        $r->assertOk();
        $r->assertSee('Add products');
        $r->assertSee('Pending QC');
        $r->assertSee('as of 08:30, Aug 28');
        $r->assertSee('Refresh from Lazada', false);
        $r->assertSee('Refresh from Lazada');
        $r->assertSee('lazada_tab=active', false);
        $r->assertSee('Activate on Lazada');
        $this->assertSame([], $this->sentPosts);
    }

    public function test_an_uploaded_row_never_checked_is_filled_on_first_view_and_only_once(): void
    {
        $productId = $this->seedProduct('Unchecked product', 'LZT-UNC');
        LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '111']);
        $calls = 0;
        $this->getResponses['/product/item/get'] = function () use (&$calls) {
            $calls++;
            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
                'item_id' => 111, 'status' => 'pending', 'attributes' => ['name' => 'Unchecked product'],
                'skus' => [['SellerSku' => 'LZT-UNC', 'Status' => 'pending']],
            ]]];
        };

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));
        $r->assertOk();
        $r->assertSee('Pending QC');
        $r->assertDontSee('Not checked yet');
        $this->assertSame(1, $calls);
        $this->assertSame('pending', LazadaProduct::query()->where('lazada_item_id', '111')->value('live_status'));

        $this->actingAs($this->manager())->get(route('ext.lazada.products.index'))->assertOk();
        $this->assertSame(1, $calls, 'the second view reads the mirror and asks nothing');
    }

    public function test_an_uploaded_row_lazada_will_not_answer_for_reads_not_checked_yet(): void
    {
        $productId = $this->seedProduct('Unchecked product', 'LZT-UNC');
        LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '111']);

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));

        $r->assertOk();
        $r->assertSee('Not checked yet');
        $r->assertSee('not refreshed yet');
    }

    public function test_a_tab_narrows_the_table_by_the_mirror(): void
    {
        $inTab = $this->seedProduct('Active on Lazada product', 'LZT-IN');
        LazadaProduct::query()->create(['product_id' => $inTab, 'lazada_item_id' => '111', 'live_status' => 'active']);
        $outOfTab = $this->seedProduct('Inactive elsewhere product', 'LZT-OUT');
        LazadaProduct::query()->create(['product_id' => $outOfTab, 'lazada_item_id' => '333', 'live_status' => 'inactive']);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.index', ['lazada_tab' => 'active']));

        $r->assertOk();
        $r->assertSee('Active on Lazada product');
        $r->assertDontSee('Inactive elsewhere product');
        $r->assertSee('Deactivate on Lazada');
    }

    public function test_refresh_walks_the_shop_and_writes_every_rows_status(): void
    {
        $a = $this->seedProduct('Refreshed product', 'LZT-A');
        LazadaProduct::query()->create(['product_id' => $a, 'lazada_item_id' => '111']);
        $ghost = $this->seedProduct('Refresh residue product', 'LZT-GHOST');
        LazadaProduct::query()->create(['product_id' => $ghost, 'lazada_item_id' => '424242']);
        $this->getResponses['/products/get'] = function (array $params) {
            $products = [['item_id' => 111, 'status' => 'active', 'skus' => [['SellerSku' => 'LZT-A']]], ['item_id' => 222, 'status' => 'inactive', 'skus' => [['SellerSku' => 'LZT-OTHER']]]];
            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['total_products' => count($products), 'products' => $products]]];
        };

        $r = $this->actingAs($this->manager())->post(route('ext.lazada.products.refresh_status'));

        $r->assertSessionHas('status');
        $this->assertStringContainsString('1 active', (string) session('status'));
        $this->assertStringContainsString('1 not found on Lazada', (string) session('status'));
        $this->assertSame('active', LazadaProduct::where('lazada_item_id', '111')->value('live_status'));
        $this->assertSame('missing', LazadaProduct::where('lazada_item_id', '424242')->value('live_status'));
        $this->assertNotNull(LazadaProduct::where('lazada_item_id', '111')->value('live_checked_at'));

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));
        $page->assertSee('Not found on Lazada');
        $page->assertDontSee('x-badge__label">Listed<', false);
    }

    public function test_refresh_reads_the_attribute_sheets_its_listings_need(): void
    {
        LazadaProduct::query()->create(['product_id' => $this->seedProduct('Unread sheet product', 'LZT-S'), 'primary_category_id' => 7001]);
        LazadaProduct::query()->create(['product_id' => $this->seedProduct('Read sheet product', 'LZT-R'), 'primary_category_id' => 7002]);
        \Extensions\lazada\Models\LazadaCategoryTemplate::query()->create(['region' => 'ph', 'primary_category_id' => 7002, 'template_body' => ['code' => '0', 'data' => []], 'fetched_at' => now()]);
        $this->getResponses['/products/get'] = fn () => ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['total_products' => 0, 'products' => []]]];
        $asked = [];
        $this->getResponses['/category/attributes/get'] = function (array $params) use (&$asked) {
            $asked[] = (string) $params['primary_category_id'];

            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [['name' => 'color_family', 'label' => 'Color', 'is_mandatory' => 0]]]];
        };

        $this->actingAs($this->manager())->post(route('ext.lazada.products.refresh_status'))->assertSessionHas('status');

        $this->assertStringContainsString('Read 1 attribute sheet.', (string) session('status'));
        $this->assertSame(['7001'], $asked, 'only the unread sheet is asked for');
        $this->assertTrue(\Extensions\lazada\Models\LazadaCategoryTemplate::where('region', 'ph')->where('primary_category_id', 7001)->exists());
    }

    public function test_refresh_leaves_the_mirror_alone_when_lazada_does_not_answer(): void
    {
        $productId = $this->seedProduct('Still visible product', 'LZT-KEEP');
        LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '111', 'live_status' => 'active']);

        $r = $this->actingAs($this->manager())->post(route('ext.lazada.products.refresh_status'));

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Lazada did not answer', (string) session('error'));
        $this->assertSame('active', LazadaProduct::where('lazada_item_id', '111')->value('live_status'));

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));
        $page->assertOk();
        $page->assertSee('Still visible product');
    }

    public function test_a_toggle_writes_through_to_the_mirror(): void
    {
        $productId = $this->seedProduct('Write-through product', 'LZT-WT');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077', 'live_status' => 'active']);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->product_id), ['action' => 'deactivate']);

        $this->assertSame('inactive', $listing->fresh()->live_status);
    }

    public function test_bulk_toggle_deactivates_the_selection_and_names_what_it_skipped(): void
    {
        $a = $this->seedProduct('Bulk A', 'LZT-BA');
        $la = LazadaProduct::query()->create(['product_id' => $a, 'lazada_item_id' => '111', 'live_status' => 'active']);
        $b = $this->seedProduct('Bulk B', 'LZT-BB');
        $lb = LazadaProduct::query()->create(['product_id' => $b, 'lazada_item_id' => '222', 'live_status' => 'active']);
        $c = $this->seedProduct('Bulk C, not on Lazada', 'LZT-BC');
        $lc = LazadaProduct::query()->create(['product_id' => $c]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.bulk_toggle'), ['action' => 'deactivate', 'product_ids' => [$la->product_id, $lb->product_id, $lc->product_id]]);

        $sent = collect($this->sentPosts)->where('path', '/product/deactivate');
        $this->assertCount(2, $sent, 'Lazada deactivates one ItemId per call');
        $ids = $sent->map(fn ($s) => (int) json_decode($s['params']['payload'], true)['Request']['Product']['ItemId'])->all();
        $this->assertEqualsCanonicalizing([111, 222], $ids);

        $r->assertSessionHas('status');
        $flash = (string) session('status');
        $this->assertStringContainsString('Deactivated 2 items on Lazada', $flash);
        $this->assertStringContainsString('1 skipped, not on Lazada', $flash);
        $this->assertSame('inactive', $la->fresh()->live_status);
        $this->assertSame('inactive', $lb->fresh()->live_status);
    }

    public function test_bulk_toggle_with_nothing_on_lazada_refuses_plainly(): void
    {
        $c = $this->seedProduct('Bulk C, not on Lazada', 'LZT-BC');
        $lc = LazadaProduct::query()->create(['product_id' => $c]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.products.bulk_toggle'), ['action' => 'activate', 'product_ids' => [$lc->product_id]])
            ->assertSessionHas('error');
        $this->assertSame([], $this->sentPosts);
    }

    public function test_a_group_page_tells_the_products_truth_not_its_own_memory(): void
    {
        $productId = $this->seedProduct('Truthful group product', 'LZT-TRUTH');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '990077',
            'last_pushed_at' => now()->subMinutes(5), 'last_push_source' => 'listing',
        ]);
        $group = \Extensions\lazada\Models\LazadaProductGroup::create([
            'name' => 'Truth group', 'lazada_category_id' => 12345,
        ]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'product_id' => $productId,
            'lazada_product_id' => $listing->id,
            'sync_status' => 'error', 'push_error' => 'Special characters are not allowed.',
            'last_pushed_at' => now()->subMinutes(37),
        ]);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.product-groups.products', $group->id));

        $r->assertOk();
        $r->assertSee('990077');
        $r->assertSee('Listed');
        $r->assertDontSee('>Failed<', false);
        $r->assertDontSee('<tr class="cc-err-row">', false);
        $r->assertDontSee('Special characters');
    }

    public function test_a_group_push_sends_the_listings_own_values_and_fills_its_blanks_from_the_group(): void
    {
        $productId = $this->seedProduct('Group-dressed product', 'GRP-DRESS');
        $pfx = (string) config('catalog.prefix');
        DB::table($pfx . 'product')->where('product_id', $productId)->update(['image' => 'catalog/x.png']);

        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'primary_category_id' => 111,
        ]);
        $listing->forceFill(['markup_fixed' => 5])->save();
        \Extensions\lazada\Models\LazadaProductAttribute::create([
            'lazada_product_id' => $listing->id, 'attribute_key' => 'material', 'value' => 'Maple',
        ]);

        $group = \Extensions\lazada\Models\LazadaProductGroup::create([
            'name' => 'Dressing group', 'lazada_category_id' => 222,
            'markup_fixed' => 20,
        ]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'product_id' => $productId,
            'lazada_product_id' => $listing->id, 'sync_status' => 'pending',
        ]);
        \Extensions\lazada\Models\LazadaProductGroupAttribute::create([
            'lazada_product_group_id' => $group->id, 'attribute_key' => 'material', 'value' => 'Rosewood',
        ]);
        \Extensions\lazada\Models\LazadaProductGroupAttribute::create([
            'lazada_product_group_id' => $group->id, 'attribute_key' => 'warranty_type', 'value' => 'No Warranty',
        ]);
        \Extensions\lazada\Models\LazadaCategoryTemplate::create([
            'region' => 'ph', 'primary_category_id' => 111,
            'template_body' => ['data' => ['attributes' => []]], 'fetched_at' => now(),
        ]);

        $this->postResponses['/image/migrate'] = ['ok' => true, 'status' => 200, 'body' => [
            'code' => '0', 'data' => ['image' => ['url' => 'https://sg-live.slatic.net/p/fake.jpg']],
        ]];

        $states = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class);
        $states->recordOutcome($productId, 'Upload refused: an earlier refusal');
        $this->assertNotNull($states->errors([$productId])[$productId]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.product-groups.push', $group->id), ['ids' => [$productId]])
            ->assertRedirect();

        $this->assertNull($states->errors([$productId])[$productId], 'a successful group push clears the line');

        $create = collect($this->sentPosts)->firstWhere('path', '/product/create');
        $this->assertNotNull($create, 'the push must reach /product/create');
        $payload = (string) ($create['params']['payload'] ?? '');
        $this->assertStringContainsString('Maple', $payload, "the listing's own answer wins over the group's");
        $this->assertStringNotContainsString('Rosewood', $payload);
        $this->assertStringContainsString('No Warranty', $payload, 'the group fills the answer the listing leaves blank');
        $this->assertStringContainsString('111', $payload, "the listing's own category is sent");

        $listing->refresh();
        $this->assertSame(111, (int) $listing->primary_category_id, "the listing's own category is untouched");
        $this->assertSame(5.0, (float) $listing->markup_fixed);
        $this->assertSame('Maple', \Extensions\lazada\Models\LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)->where('attribute_key', 'material')->value('value'),
            "the listing's own attribute value is untouched");
        $this->assertFalse(\Extensions\lazada\Models\LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)->where('attribute_key', 'warranty_type')->exists(),
            "the group's answer is sent, never written into the listing");
    }

    private function descriptionPicture(string $path): string
    {
        $canvas = imagecreatetruecolor(12, 12);
        ob_start();
        imagepng($canvas);
        \Illuminate\Support\Facades\Storage::disk('public')->put($path, (string) ob_get_clean());

        return asset('storage/' . $path);
    }

    private function onAPublicCatalog(): void
    {
        \Illuminate\Support\Facades\Storage::fake('public');
        config(['catalog.public_url' => 'https://shop.example.com']);
    }

    private function productDescribedAs(string $sku, string $html): int
    {
        $pid = $this->colourProduct($sku);
        $this->liveColours($sku);
        DB::table((string) config('catalog.prefix') . 'product_description')->where('product_id', $pid)->update(['description' => $html]);
        LazadaProduct::query()->where('product_id', $pid)->update(['description' => $html]);

        return $pid;
    }

    private function migrateAnswers(array $refused = []): void
    {
        $this->postResponses['/image/migrate'] = function (array $params) use ($refused) {
            preg_match('#<Url>(.*)</Url>#', (string) ($params['payload'] ?? ''), $m);
            $url = html_entity_decode($m[1] ?? '', ENT_XML1);
            foreach ($refused as $name) {
                if (str_contains($url, $name)) {
                    return ['ok' => true, 'status' => 200, 'body' => ['code' => '302', 'message' => 'Not supported URL']];
                }
            }

            return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['image' => [
                'url' => 'https://sg-live.slatic.net/p/' . basename((string) parse_url($url, PHP_URL_PATH)),
            ]]]];
        };
    }

    private function migratedUrls(): array
    {
        return collect($this->sentPosts)->where('path', '/image/migrate')
            ->map(fn ($p) => preg_match('#<Url>(.*)</Url>#', (string) $p['params']['payload'], $m) ? html_entity_decode($m[1], ENT_XML1) : '')
            ->values()->all();
    }

    private function updateAttributes(int $nth = 0): array
    {
        $update = collect($this->sentPosts)->where('path', '/product/update')->values()->get($nth);
        $this->assertNotNull($update, 'the update reaches Lazada');

        return json_decode($update['params']['payload'], true)['Request']['Product']['Attributes'];
    }

    public function test_a_description_picture_is_sent_with_its_lazada_link(): void
    {
        $this->onAPublicCatalog();
        $src = $this->descriptionPicture('catalog/desc/pic.png');
        $pid = $this->productDescribedAs('DESC-PIC', '<p>Soft cable.</p><img src="' . $src . '" alt="Cable">');
        $this->migrateAnswers();

        $this->pushListing($pid)->assertSessionHas('status', 'Update pushed.');

        $this->assertContains('https://shop.example.com/storage/catalog/desc/pic.png', $this->migratedUrls(),
            'Lazada fetches the picture from the public catalog address, not the one the editor wrote');
        $attributes = $this->updateAttributes();
        $this->assertStringContainsString('<img src="https://sg-live.slatic.net/p/pic.png" alt="Cable">', $attributes['description']);
        $this->assertStringNotContainsString('/storage/', json_encode($attributes, JSON_UNESCAPED_SLASHES), 'no attribute carries our own address');
    }

    public function test_a_description_picture_migrated_once_is_reused_on_the_next_push(): void
    {
        $this->onAPublicCatalog();
        $src = $this->descriptionPicture('catalog/desc/pic.png');
        $pid = $this->productDescribedAs('DESC-AGAIN', '<p>Soft cable.</p><img src="' . $src . '">');
        $this->migrateAnswers();

        $this->pushListing($pid);
        $this->pushListing($pid);

        $asked = array_filter($this->migratedUrls(), fn ($url) => str_contains($url, 'desc/pic.png'));
        $this->assertCount(1, $asked, 'the second push reuses the link instead of migrating again');
        $this->assertStringContainsString('<img src="https://sg-live.slatic.net/p/pic.png">', $this->updateAttributes(1)['description']);
        $this->assertSame(1, \Extensions\lazada\Models\LazadaImageLink::query()
            ->where('region', 'ph')->where('original_url', 'https://shop.example.com/storage/catalog/desc/pic.png')->count());
    }

    public function test_a_description_picture_lazada_refuses_is_left_out_and_the_push_says_so(): void
    {
        $this->onAPublicCatalog();
        $refused = $this->descriptionPicture('catalog/desc/refused.png');
        $kept = $this->descriptionPicture('catalog/desc/kept.png');
        $pid = $this->productDescribedAs('DESC-OUT', '<p>Soft cable.</p><img src="' . $refused . '"><img src="' . $kept . '">');
        $this->migrateAnswers(['refused.png']);

        $this->pushListing($pid)
            ->assertSessionHas('warning', 'Update pushed. 1 picture was left out of the description.')
            ->assertSessionMissing('error');

        $attributes = $this->updateAttributes();
        $this->assertSame('<p>Soft cable.</p><img src="https://sg-live.slatic.net/p/kept.png">', $attributes['description']);
        $this->assertStringNotContainsString('refused.png', json_encode($attributes), 'the refused picture is not sent with our address');
        $this->assertNull(app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->errors([$pid])[$pid],
            'a picture left out is a warning, never the listing\'s error line');
    }

    public function test_an_entity_encoded_description_picture_is_found_and_migrated(): void
    {
        $this->onAPublicCatalog();
        $this->descriptionPicture('catalog/desc/pic.png');
        $pid = $this->productDescribedAs('DESC-ENC', '&lt;p&gt;Soft cable.&lt;/p&gt;&lt;img src=&quot;catalog/desc/pic.png&quot;&gt;');
        $this->migrateAnswers();

        $this->pushListing($pid)->assertSessionHas('status', 'Update pushed.');

        $this->assertContains('https://shop.example.com/storage/catalog/desc/pic.png', $this->migratedUrls());
        $this->assertStringContainsString('<img src="https://sg-live.slatic.net/p/pic.png"', $this->updateAttributes()['description']);
    }

    public function test_a_group_push_summary_warns_when_a_description_picture_is_left_out(): void
    {
        $this->onAPublicCatalog();
        $refused = $this->descriptionPicture('catalog/desc/refused.png');
        $pid = $this->productDescribedAs('DESC-GRP', '<p>Soft cable.</p><img src="' . $refused . '">');
        $this->migrateAnswers(['refused.png']);

        $listing = LazadaProduct::query()->where('product_id', $pid)->firstOrFail();
        $group = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Cables', 'lazada_category_id' => 9257]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'product_id' => $pid,
            'lazada_product_id' => $listing->id, 'sync_status' => 'pending',
        ]);
        $states = app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class);
        $states->recordOutcome($pid, 'Upload refused: an earlier refusal');

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.product-groups.push', $group->id), ['ids' => [$pid]])
            ->assertRedirect()
            ->assertSessionHas('warning', fn ($message) => str_contains((string) $message, "#{$pid}: 1 picture was left out of the description."));

        $this->assertStringNotContainsString('refused.png', json_encode($this->updateAttributes()));
        $this->assertNull($states->errors([$pid])[$pid], 'the push went, so the line is cleared, not set to the warning');
    }

    public function test_readiness_names_gaps_on_the_index(): void
    {
        $productId = $this->seedProduct('Unready lazada product', 'LZT-UNREADY');
        $listing = LazadaProduct::query()->create(['product_id' => $productId]);
        \Extensions\lazada\Models\LazadaCategory::create(['category_id' => 222, 'name' => 'Pedals', 'leaf' => true, 'var' => false, 'parent_id' => null, 'level' => 0]);

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.products.index'));
        $r->assertOk();
        $r->assertSee('cc-err-row--warn', false);
        $r->assertSee('Needs details:');
        $r->assertSee('a Lazada category');
        $r->assertDontSee('bulk/upload');
    }

    public function test_the_toggle_speaks_lazadas_two_verbs(): void
    {
        $productId = $this->seedProduct('Toggle product', 'LZT-TOG');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077']);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->product_id), ['action' => 'deactivate']);
        $sent = collect($this->sentPosts)->firstWhere('path', '/product/deactivate');
        $this->assertNotNull($sent, 'Deactivate must reach /product/deactivate.');
        $payload = json_decode($sent['params']['payload'], true);
        $this->assertSame(990077, (int) $payload['Request']['Product']['ItemId']);
        $r->assertSessionHas('status');

        $this->sentPosts = [];
        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 990077,
            'attributes' => ['name' => 'Toggle me', 'brand' => 'No Brand'],
            'skus' => [['SkuId' => 555111, 'SellerSku' => 'LZT-TOG', 'quantity' => 3, 'price' => 110]],
        ]]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->product_id), ['action' => 'activate']);
        $sent = collect($this->sentPosts)->firstWhere('path', '/product/update');
        $this->assertNotNull($sent, 'Activate must reach /product/update.');
        $payload = json_decode($sent['params']['payload'], true);
        $this->assertSame('Toggle me', $payload['Request']['Product']['Attributes']['name'], 'Attributes travel or Lazada answers E001');
        $this->assertSame(555111, (int) $payload['Request']['Product']['Skus']['Sku'][0]['SkuId']);
        $this->assertSame('active', $payload['Request']['Product']['Skus']['Sku'][0]['Status']);
    }

    public function test_the_edit_page_opens_with_the_items_live_state(): void
    {
        $productId = $this->seedProduct('Live edit product', 'LZT-LIVE');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077']);

        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 990077, 'status' => 'active', 'subStatus' => '',
            'attributes' => ['name' => 'Live edit product'],
            'skus' => [[
                'SellerSku' => 'LZT-LIVE', 'price' => 450.0, 'special_price' => 0,
                'quantity' => 7, 'Status' => 'active',
            ]],
        ]]];

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.edit', $listing->product_id));

        $r->assertOk();
        $r->assertDontSee('On Lazada right now');
        $r->assertSee('LZT-LIVE');
        $this->assertSame('active', LazadaProduct::query()->where('lazada_item_id', '990077')->value('live_status'));
    }

    public function test_the_edit_page_opens_on_the_record_when_lazada_does_not_answer(): void
    {
        $productId = $this->seedProduct('Unreachable product', 'LZT-DOWN');
        $listing = LazadaProduct::query()->create(['product_id' => $productId, 'lazada_item_id' => '990077']);

        $r = $this->actingAs($this->manager())
            ->get(route('ext.lazada.products.edit', $listing->product_id));

        $r->assertOk();
        $r->assertDontSee('Lazada did not answer');
        $this->assertNull(LazadaProduct::query()->where('lazada_item_id', '990077')->value('live_status'));
    }

    public function test_activate_carries_the_listings_own_attributes_back_to_lazada(): void
    {
        $productId = $this->seedProduct('Valeton GP-200', 'GP200');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '1830026816', 'live_status' => 'inactive',
        ]);

        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 1830026816,
            'attributes' => [
                'name' => 'Valeton GP-200 as Lazada holds it',
                'description' => '<p>Live copy</p>',
                'brand' => 'Valeton',
                'warranty_type' => 'Local supplier warranty',
                'short_description' => '',
            ],
            'skus' => [[
                'SkuId' => 7785126563, 'SellerSku' => 'GP200-BLACK',
                'quantity' => 7, 'price' => 21990,
                'Url' => 'https://www.lazada.com.ph/products/x.html', 'ShopSku' => '123_PH_456',
            ]],
        ]]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->product_id), ['action' => 'activate'])
            ->assertRedirect();

        $update = collect($this->sentPosts)->firstWhere('path', '/product/update');
        $this->assertNotNull($update, 'activate goes through /product/update');

        $payload = json_decode($update['params']['payload'], true);
        $product = $payload['Request']['Product'];

        $this->assertArrayHasKey('Attributes', $product);
        $this->assertSame('Valeton GP-200 as Lazada holds it', $product['Attributes']['name']);
        $this->assertSame('Valeton', $product['Attributes']['brand']);
        $this->assertArrayNotHasKey('short_description', $product['Attributes'], 'empty values are not sent back');

        $sku = $product['Skus']['Sku'][0];
        $this->assertSame('active', $sku['Status']);
        $this->assertSame(7785126563, $sku['SkuId']);
        $this->assertSame('GP200-BLACK', $sku['SellerSku']);
        $this->assertSame(7, $sku['Quantity']);
        $this->assertSame(21990, $sku['price']);
        $this->assertArrayNotHasKey('Url', $sku, 'read-only fields are not sent back');
        $this->assertArrayNotHasKey('ShopSku', $sku);

        $this->assertSame('active', $listing->fresh()->live_status);
    }

    public function test_activate_refuses_rather_than_sending_a_product_lazada_did_not_confirm(): void
    {
        $productId = $this->seedProduct('Ghost', 'GHOST');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '999', 'live_status' => 'inactive',
        ]);

        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => [
            'code' => '0', 'data' => ['item_id' => 999, 'attributes' => [], 'skus' => []],
        ]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->product_id), ['action' => 'activate'])
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertNull(collect($this->sentPosts)->firstWhere('path', '/product/update'), 'nothing is sent when the read came back empty');
        $this->assertSame('inactive', $listing->fresh()->live_status, 'the mirror is not moved on a refusal');
    }

    public function test_deactivate_still_sends_only_the_item_id(): void
    {
        $productId = $this->seedProduct('Valeton GP-5', 'GP5');
        $listing = LazadaProduct::query()->create([
            'product_id' => $productId, 'lazada_item_id' => '222', 'live_status' => 'active',
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $listing->product_id), ['action' => 'deactivate'])
            ->assertRedirect();

        $call = collect($this->sentPosts)->firstWhere('path', '/product/deactivate');
        $this->assertNotNull($call);
        $this->assertSame(
            ['Request' => ['Product' => ['ItemId' => 222]]],
            json_decode($call['params']['payload'], true)
        );
        $this->assertSame('inactive', $listing->fresh()->live_status);
    }


    private function storeId(): int
    {
        return (int) LazadaSetting::defaultStore()->id;
    }

    private function colourProduct(string $sku): int
    {
        $pid = $this->productWithPicture($sku);
        $this->addOption($pid, 'Color', ['Red', 'Blue', 'Green'], ['Red' => $sku . '-R', 'Blue' => $sku . '-B', 'Green' => $sku . '-G']);
        $this->listingOn($pid, '2712258176');
        \Extensions\lazada\Models\LazadaCategoryTemplate::create([
            'region' => 'ph', 'primary_category_id' => 9257,
            'template_body' => ['data' => ['attributes' => []]], 'fetched_at' => now(),
        ]);
        $this->postResponses['/image/migrate'] = ['ok' => true, 'status' => 200, 'body' => [
            'code' => '0', 'data' => ['image' => ['url' => 'https://sg-live.slatic.net/p/fake.jpg']],
        ]];

        return $pid;
    }

    private function liveColours(string $sku, array $statuses = [], string $itemStatus = 'active'): void
    {
        $skus = [];
        $id = 0;
        foreach (['R' => 'Red', 'B' => 'Blue', 'G' => 'Green'] as $suffix => $name) {
            $skus[] = ['SkuId' => ++$id, 'SellerSku' => $sku . '-' . $suffix, 'Status' => $statuses[$suffix] ?? 'active', 'saleProp' => ['color_family' => $name]];
        }
        $this->getResponses['/product/item/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            'item_id' => 2712258176, 'status' => $itemStatus, 'attributes' => ['name' => 'Qable IC50', 'brand' => 'No Brand'], 'skus' => $skus,
        ]]];
    }

    private function sellHere(int $pid, array $onSkus): void
    {
        \App\Integrations\Listings\ListingVariations::save('lazada', $this->storeId(), $pid, $onSkus);
    }

    private function pushListing(int $pid): \Illuminate\Testing\TestResponse
    {
        return $this->actingAs($this->manager())->post(route('ext.lazada.products.upload', $pid))->assertRedirect();
    }

    private function updateSkus(): array
    {
        $update = collect($this->sentPosts)->firstWhere('path', '/product/update');
        $this->assertNotNull($update, 'the update reaches Lazada');

        return collect(json_decode($update['params']['payload'], true)['Request']['Product']['Skus']['Sku'])->keyBy('SellerSku')->all();
    }

    private function deactivations(): array
    {
        return collect($this->sentPosts)->where('path', '/product/deactivate')
            ->map(fn ($p) => json_decode($p['params']['payload'], true))->values()->all();
    }

    public function test_a_variation_switched_off_for_the_store_is_deactivated_by_its_sku_alone(): void
    {
        $pid = $this->colourProduct('SW-OFF');
        $this->liveColours('SW-OFF');
        $this->sellHere($pid, ['SW-OFF-B', 'SW-OFF-G']);

        $this->pushListing($pid)->assertSessionHas('status', 'Update pushed. Switched off on Lazada: Red (SW-OFF-R).');

        $this->assertSame(
            [['Request' => ['Product' => ['ItemId' => 2712258176, 'Skus' => ['SkuId' => 1, 'SellerSku' => 'SW-OFF-R']]]]],
            $this->deactivations(),
            'one call, carrying only the switched-off SKU'
        );
        $skus = $this->updateSkus();
        $this->assertSame(['SW-OFF-B', 'SW-OFF-G'], array_keys($skus));
        foreach ($skus as $row) {
            $this->assertArrayNotHasKey('Status', $row, 'the variations still sold are left as they are');
        }
        $this->assertNull(app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->errors([$pid])[$pid]);
    }

    public function test_a_variation_switched_back_on_is_sent_active_in_the_update(): void
    {
        $pid = $this->colourProduct('SW-BACK');
        $this->sellHere($pid, ['SW-BACK-B', 'SW-BACK-G']);
        $this->sellHere($pid, ['SW-BACK-R', 'SW-BACK-B', 'SW-BACK-G']);
        $this->liveColours('SW-BACK', ['R' => 'inactive']);

        $this->pushListing($pid)->assertSessionHas('status', 'Update pushed. Switched back on: Red (SW-BACK-R).');

        $skus = $this->updateSkus();
        $this->assertSame('active', $skus['SW-BACK-R']['Status']);
        $this->assertArrayNotHasKey('Status', $skus['SW-BACK-B']);
        $this->assertArrayNotHasKey('Status', $skus['SW-BACK-G']);
        $this->assertSame([], $this->deactivations());
    }

    public function test_a_sku_already_inactive_on_lazada_is_not_deactivated_again(): void
    {
        $pid = $this->colourProduct('SW-DONE');
        $this->sellHere($pid, ['SW-DONE-B', 'SW-DONE-G']);
        $this->liveColours('SW-DONE', ['R' => 'inactive']);

        $this->pushListing($pid)->assertSessionHas('status', 'Update pushed.');

        $this->assertSame([], $this->deactivations());
        $this->assertArrayNotHasKey('SW-DONE-R', $this->updateSkus());
    }

    public function test_a_refused_deactivate_is_the_listings_error(): void
    {
        $pid = $this->colourProduct('SW-NO');
        $this->sellHere($pid, ['SW-NO-B', 'SW-NO-G']);
        $this->liveColours('SW-NO');
        $this->postResponses['/product/deactivate'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 'E0006', 'message' => 'Unexpected internal error']];

        $line = 'Lazada refused to switch off Red (SW-NO-R): Unexpected internal error (code E0006).';
        $this->pushListing($pid)->assertSessionHas('error', 'Update pushed. ' . $line);

        $this->assertSame($line, app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->errors([$pid])[$pid]);
    }

    public function test_lazada_saying_the_sku_is_already_inactive_counts_as_done(): void
    {
        $pid = $this->colourProduct('SW-E4');
        $this->sellHere($pid, ['SW-E4-B', 'SW-E4-G']);
        $this->liveColours('SW-E4');
        $this->postResponses['/product/deactivate'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 'E0004', 'message' => 'Product Status not online']];

        $this->pushListing($pid)->assertSessionHas('status', 'Update pushed. Switched off on Lazada: Red (SW-E4-R).');

        $this->assertNull(app(\Extensions\lazada\Services\Lazada\LazadaListingStates::class)->errors([$pid])[$pid]);
    }

    public function test_an_item_switched_off_as_a_whole_is_not_switched_back_on_by_a_push(): void
    {
        $pid = $this->colourProduct('SW-ITEM');
        $this->liveColours('SW-ITEM', ['R' => 'inactive', 'B' => 'inactive', 'G' => 'inactive'], 'inactive');

        $this->pushListing($pid)->assertSessionHas('status', 'Update pushed.');

        foreach ($this->updateSkus() as $row) {
            $this->assertArrayNotHasKey('Status', $row);
        }
    }

    public function test_a_group_update_deactivates_a_variation_switched_off_for_the_store(): void
    {
        $pid = $this->colourProduct('SW-GRP');
        $this->sellHere($pid, ['SW-GRP-B', 'SW-GRP-G']);
        $this->liveColours('SW-GRP');
        $listing = LazadaProduct::query()->where('product_id', $pid)->firstOrFail();
        $group = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Switch group', 'lazada_category_id' => 9257]);
        \Extensions\lazada\Models\LazadaProductGroupProduct::create([
            'lazada_product_group_id' => $group->id, 'product_id' => $pid,
            'lazada_product_id' => $listing->id, 'sync_status' => 'pending',
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.product-groups.updateProduct', $group->id), ['ids' => [$pid]])
            ->assertRedirect();

        $this->assertStringContainsString('Switched off on Lazada: Red (SW-GRP-R).', (string) session('status'));
        $this->assertSame([['Request' => ['Product' => ['ItemId' => 2712258176, 'Skus' => ['SkuId' => 1, 'SellerSku' => 'SW-GRP-R']]]]], $this->deactivations());
        $this->assertSame(['SW-GRP-B', 'SW-GRP-G'], array_keys($this->updateSkus()));
    }

    public function test_activating_the_item_leaves_a_variation_switched_off_for_the_store_off(): void
    {
        $pid = $this->colourProduct('SW-TOG');
        $this->sellHere($pid, ['SW-TOG-B', 'SW-TOG-G']);
        $this->liveColours('SW-TOG', ['R' => 'inactive', 'B' => 'inactive', 'G' => 'inactive'], 'inactive');

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.listings.toggle', $pid), ['action' => 'activate'])
            ->assertRedirect()
            ->assertSessionHas('status');

        $skus = $this->updateSkus();
        $this->assertSame(['SW-TOG-B', 'SW-TOG-G'], array_keys($skus));
        $this->assertSame(['active', 'active'], array_column($skus, 'Status'));
    }

    public function test_the_follow_migration_clears_only_copies_equal_to_the_group(): void
    {
        $copied = $this->seedProduct('Copied listing', 'LZ-COPY');
        $own = $this->seedProduct('Own listing', 'LZ-OWN');
        $group = \Extensions\lazada\Models\LazadaProductGroup::create(['name' => 'Copy group', 'lazada_category_id' => 222, 'markup_fixed' => 20]);
        \Extensions\lazada\Models\LazadaProductGroupAttribute::create(['lazada_product_group_id' => $group->id, 'attribute_key' => 'material', 'value' => 'Rosewood']);

        $a = LazadaProduct::query()->create(['product_id' => $copied, 'primary_category_id' => 222]);
        $a->forceFill(['markup_fixed' => 20])->save();
        \Extensions\lazada\Models\LazadaProductAttribute::create(['lazada_product_id' => $a->id, 'attribute_key' => 'material', 'value' => 'Rosewood']);
        \Extensions\lazada\Models\LazadaProductAttribute::create(['lazada_product_id' => $a->id, 'attribute_key' => 'color_family', 'value' => 'Red']);
        $b = LazadaProduct::query()->create(['product_id' => $own, 'primary_category_id' => 333]);
        $b->forceFill(['markup_fixed' => 7])->save();
        foreach ([[$copied, $a], [$own, $b]] as [$pid, $row]) {
            \Extensions\lazada\Models\LazadaProductGroupProduct::create(['lazada_product_group_id' => $group->id, 'product_id' => $pid, 'lazada_product_id' => $row->id, 'sync_status' => 'pushed']);
        }

        (require base_path('database/migrations/2026_09_16_110000_lazada_listings_follow_their_group.php'))->up();

        $a->refresh();
        $b->refresh();
        $this->assertNull($a->primary_category_id);
        $this->assertNull($a->markup_fixed);
        $this->assertFalse(\Extensions\lazada\Models\LazadaProductAttribute::query()->where('lazada_product_id', $a->id)->where('attribute_key', 'material')->exists());
        $this->assertTrue(\Extensions\lazada\Models\LazadaProductAttribute::query()->where('lazada_product_id', $a->id)->where('attribute_key', 'color_family')->exists(), 'an answer the group does not give stays');
        $this->assertSame(333, (int) $b->primary_category_id, 'a value the operator set stays');
        $this->assertSame(7.0, (float) $b->markup_fixed);
    }
}
