<?php

namespace Tests\Feature;

use App\Channels\Listings\Templates\TemplateFill;
use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaListingAttribute;
use Extensions\Lazada\Models\LazadaListingTemplate;
use Extensions\Lazada\Models\LazadaListingTemplateAttribute;
use Extensions\Lazada\Models\LazadaStore;
use Extensions\Lazada\Services\Lazada\LazadaTemplateFields;
use Extensions\Shopee\Models\ShopeeCategoryTemplate;
use Extensions\Shopee\Models\ShopeeListing;
use Extensions\Shopee\Models\ShopeeListingTemplate;
use Extensions\Shopee\Models\ShopeeListingTemplateAttribute;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListingTemplateFillTest extends TestCase
{
    use RefreshDatabase;

    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        foreach (['shopee', 'lazada'] as $id) {
            $manager->install($id);
            $manager->enable($id);
        }
        $this->app->register(\Extensions\Shopee\ShopeeExtension::class);
        $this->app->register(\Extensions\Lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalog');

        ShopeeStore::query()->create(['partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002, 'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production']);
        $this->app->instance(ShopeeClient::class, new class extends ShopeeClient {
            public function __construct() {}
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                return ['ok' => false, 'status' => 500, 'body' => ['message' => 'not faked']];
            }
        });

        ShopeeCategoryTemplate::query()->create([
            'category_id' => 106755,
            'region' => 'ph',
            'attributes' => [[
                'attribute_id' => 1000, 'original_attribute_name' => 'Brand Origin',
                'is_mandatory' => true, 'input_type' => 'DROP_DOWN',
                'attribute_value_list' => [['value_id' => 71, 'original_value_name' => 'China'], ['value_id' => 72, 'original_value_name' => 'Japan']],
            ]],
        ]);
    }

    private function user(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Fill ' . (++$this->seq)]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function product(): int
    {
        $pid = DB::table('products')->insertGetId([
            'sku' => 'FILL-1', 'quantity' => 5, 'price' => 300, 'status' => 1, 'image' => '',
            'weight' => 1, 'length' => 10, 'width' => 10, 'height' => 10, 'brand_id' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Fill test pedal', 'description' => 'Words.', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    private function shopeeSetup(): array
    {
        $pid = $this->product();
        ShopeeListing::query()->create(['product_id' => $pid, 'shopee_category_id' => 106755, 'logistic_ids' => [41003], 'attribute_values' => ['1000' => 'China']]);
        $template = ShopeeListingTemplate::create(['store_id' => (int) ShopeeStore::defaultStore()->id, 'name' => 'Pedals, 10% up', 'shopee_category_id' => 106755, 'markup_percent' => 10]);
        ShopeeListingTemplateAttribute::create(['listing_template_id' => $template->id, 'attribute_key' => '1000', 'value' => 'Japan']);

        return [$pid, $template];
    }

    public function test_fill_shows_the_templates_values_on_the_page_and_saves_nothing(): void
    {
        [$pid, $template] = $this->shopeeSetup();
        $manager = $this->user(['view_shopee/product', 'manage_shopee/product']);

        $page = $this->actingAs($manager)->get(route('ext.shopee.listings.edit', ['productId' => $pid, 'fill' => $template->id]))->assertOk();
        $page->assertSee('Fill from template')
            ->assertSee('Price rule and 1 category attribute filled from "Pedals, 10% up". Not saved yet.', false)
            ->assertSee('data-guard-filled', false)
            ->assertSee('<form id="lt-fill-form" method="GET"', false);
        $this->assertMatchesRegularExpression('~name="markup_percent"[^>]*value="10(\.0+)?"~', $page->getContent());

        $listing = ShopeeListing::query()->where('product_id', $pid)->firstOrFail();
        $this->assertNull($listing->markup_percent);
        $this->assertSame(['1000' => 'China'], $listing->attribute_values);
    }

    public function test_without_a_choice_the_page_offers_the_templates_and_fills_nothing(): void
    {
        [$pid] = $this->shopeeSetup();

        $this->actingAs($this->user(['view_shopee/product', 'manage_shopee/product']))
            ->get(route('ext.shopee.listings.edit', ['productId' => $pid]))->assertOk()
            ->assertSee('Fill from template')->assertSee('Pedals, 10% up')
            ->assertDontSee('Not saved yet.')->assertDontSee('data-guard-filled', false);
    }

    public function test_a_reader_and_another_stores_template_fill_nothing(): void
    {
        [$pid, $template] = $this->shopeeSetup();
        $outlet = ShopeeStore::query()->create(['partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 3003, 'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production', 'store_name' => 'Outlet']);
        $foreign = ShopeeListingTemplate::create(['store_id' => $outlet->id, 'name' => 'Outlet rule', 'markup_percent' => 50]);

        $this->actingAs($this->user(['view_shopee/product']))
            ->get(route('ext.shopee.listings.edit', ['productId' => $pid, 'fill' => $template->id]))->assertOk()
            ->assertDontSee('Fill from template')->assertDontSee('Not saved yet.');

        $this->actingAs($this->user(['view_shopee/product', 'manage_shopee/product']))
            ->get(route('ext.shopee.listings.edit', ['productId' => $pid, 'fill' => $foreign->id]))->assertOk()
            ->assertDontSee('Not saved yet.')->assertDontSee('Outlet rule');
    }

    public function test_a_template_that_matches_the_listing_says_it_has_nothing_to_fill(): void
    {
        [$pid] = $this->shopeeSetup();
        $same = ShopeeListingTemplate::create(['store_id' => (int) ShopeeStore::defaultStore()->id, 'name' => 'Same category', 'shopee_category_id' => 106755]);

        $this->actingAs($this->user(['view_shopee/product', 'manage_shopee/product']))
            ->get(route('ext.shopee.listings.edit', ['productId' => $pid, 'fill' => $same->id]))->assertOk()
            ->assertSee('"Same category" has nothing to fill.', false)
            ->assertDontSee('data-guard-filled', false);
    }

    public function test_lazada_attributes_are_filled_over_the_listings_own_rows_without_writing_them(): void
    {
        $store = LazadaStore::query()->create(['store_name' => 'Main', 'region' => 'PH', 'app_key' => 'k', 'app_secret' => 's']);
        $pid = $this->product();
        $listing = LazadaListing::query()->withoutGlobalScopes()->create(['store_id' => $store->id, 'product_id' => $pid, 'primary_category_id' => 10001]);
        LazadaListingAttribute::query()->create(['lazada_listing_id' => $listing->id, 'attribute_key' => 'warranty_type', 'value' => 'No warranty']);
        $template = LazadaListingTemplate::create(['store_id' => $store->id, 'name' => 'Pedals', 'markup_fixed' => 25]);
        LazadaListingTemplateAttribute::create(['listing_template_id' => $template->id, 'attribute_key' => 'warranty_type', 'value' => 'Local seller warranty']);
        LazadaListingTemplateAttribute::create(['listing_template_id' => $template->id, 'attribute_key' => 'color_family', 'value' => 'Black']);

        $fill = TemplateFill::onto(new LazadaTemplateFields($store->id), $listing, LazadaListingTemplate::class, $store->id, (string) $template->id);

        $this->assertSame(['Price rule', '2 category attributes'], $fill['filled']);
        $this->assertSame('Local seller warranty', $fill['attributes']['warranty_type']);
        $this->assertSame('Black', $fill['attributes']['color_family']);
        $this->assertEquals(25, $listing->markup_fixed);
        $this->assertNull($listing->fresh()->markup_fixed);
        $this->assertSame('No warranty', LazadaListingAttribute::query()->where('lazada_listing_id', $listing->id)->value('value'));
    }
}
