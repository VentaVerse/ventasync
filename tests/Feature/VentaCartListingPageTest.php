<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\DescriptionTemplate;
use App\Models\User;
use Extensions\ventacart\Models\VentaCartListing;
use Extensions\ventacart\Models\VentaCartProductGroup;
use Extensions\ventacart\Models\VentaCartProductGroupProduct;
use Extensions\ventacart\Models\VentaCartProductLink;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\VentaCartExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class VentaCartListingPageTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://one.ventacart.test';

    private int $seq = 0;

    private array $users = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('ventacart');
        $manager->enable('ventacart');

        $this->app->register(VentaCartExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        Http::preventStrayRequests();
    }

    private function userWith(array $keys): User
    {
        $name = 'VentaCart listing ' . implode('-', $keys);
        if (! isset($this->users[$name])) {
            $group = UserGroup::create(['name' => $name . ' ' . (++$this->seq)]);
            $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());
            $this->users[$name] = User::factory()->create(['user_group_id' => $group->id]);
        }

        return $this->users[$name];
    }

    private function manager(): User
    {
        return $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group']);
    }

    private function viewer(): User
    {
        return $this->userWith(['view_ventacart/listing', 'view_ventacart/product_group']);
    }

    private function store(bool $enabled = true): VentaCartSetting
    {
        return VentaCartSetting::create([
            'store_name' => 'Gear Depot',
            'base_url' => self::BASE,
            'api_token' => 'ventacart-token-one',
            'enabled' => $enabled,
        ]);
    }

    private function product(string $sku = 'GP-200', float $price = 1000): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = (int) DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'price' => $price, 'quantity' => 7, 'status' => 1,
            'image' => '', 'weight' => 0, 'length' => 0, 'width' => 0, 'height' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Guitar Pedal 200', 'description' => 'The catalogue description.',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    private function storeProduct(int $id = 5001, bool $active = true, array $extra = []): array
    {
        return array_merge([
            'id' => $id, 'sku' => 'GP-200', 'name' => 'Guitar Pedal 200 on the store', 'price' => 1150.0,
            'quantity' => 6, 'status' => $active, 'images' => [['id' => 1, 'path' => 'a.jpg']],
            'variants' => [
                ['id' => 1, 'sku' => 'GP-200-RED', 'price' => 1150.0, 'quantity' => 4, 'is_active' => true, 'option_values' => [['id' => 1, 'value' => 'Red']]],
            ],
        ], $extra);
    }

    public function test_a_refused_stock_push_is_recorded_on_the_listing_not_a_server_error(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);
        Http::fake([self::BASE . '/*' => Http::response(['error' => 'Product not found'], 404)]);

        $this->actingAs($this->manager())
            ->from('/dashboard')
            ->post(route('ext.ventacart.listings.push_stock', [$store->id, $pid]))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('error');

        $listing = VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->first();
        $this->assertNotNull($listing);
        $this->assertSame('Stock push failed: Product not found', $listing->last_push_error);
        $this->assertNotNull($listing->last_push_failed_at);
    }

    public function test_a_failed_push_then_a_clean_stock_push_clears_the_error_line(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);
        $refuse = true;
        Http::fake(function ($request) use (&$refuse) {
            if ($request->method() === 'GET') {
                return Http::response($this->storeProduct(5001));
            }

            return $refuse
                ? Http::response(['error' => 'Title is too long'], 422)
                : Http::response(['id' => 5001, 'sku' => 'GP-200', 'quantity' => 7]);
        });

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('error');
        $error = (string) VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('last_push_error');
        $this->assertStringContainsString('Title is too long', $error);
        $this->assertStringContainsString('Title is too long', (string) \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store->id)->errors([$pid])[$pid]);

        $refuse = false;
        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push_stock', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNull(VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('last_push_error'));
        $this->assertNull(\Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store->id)->errors([$pid])[$pid]);
        $this->actingAs($this->manager())
            ->get(route('ext.ventacart.listings.index', $store->id))
            ->assertOk()
            ->assertDontSee('Title is too long');
    }

    public function test_a_push_error_survives_a_successful_link_check_and_the_next_stock_push_clears_it(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'live_status' => 'active', 'live_checked_at' => now()]);
        VentaCartListing::where('product_id', $pid)->first()->forceFill(['last_push_error' => 'Price push failed: Price below floor', 'last_push_failed_at' => now()])->save();
        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response($this->storeProduct(5001)),
            self::BASE . '/api/v1/products*' => Http::response(['data' => [$this->storeProduct(5001)], 'last_page' => 1]),
        ]);
        $line = fn () => \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store->id)->errors([$pid])[$pid];

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.check', $store->id), ['product_ids' => [$pid]])
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame('Price push failed: Price below floor', $line());

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.refresh_status', $store->id))
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertSame('Price push failed: Price below floor', $line());

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push_stock', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('status');
        $this->assertNull($line());
    }

    public function test_a_successful_push_clears_an_earlier_push_error(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid]);
        VentaCartListing::where('product_id', $pid)->first()->forceFill(['last_push_error' => 'Push failed: Product not found', 'last_push_failed_at' => now()])->save();
        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::sequence()
                ->push($this->storeProduct(5001))
                ->push(['id' => 5001, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNull(VentaCartListing::where('product_id', $pid)->value('last_push_error'));
        $this->assertNull(\Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store->id)->errors([$pid])[$pid]);
    }

    public function test_unlinking_clears_the_error_line(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Pedals']);
        VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'error', 'push_error' => 'Old refusal', 'last_pushed_at' => now()->subDay()]);
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'live_status' => 'active', 'live_checked_at' => now()]);
        VentaCartListing::where('product_id', $pid)->first()->forceFill(['last_push_error' => 'Stock push failed: Product not found', 'last_push_failed_at' => now()])->save();
        $this->assertNotNull(\Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store->id)->errors([$pid])[$pid]);
        Http::fake();

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.products.unlink', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertNull(VentaCartListing::where('product_id', $pid)->value('last_push_error'));
        $this->assertNull(VentaCartListing::where('product_id', $pid)->value('last_push_failed_at'));
        $pivot = VentaCartProductGroupProduct::where('product_id', $pid)->first();
        $this->assertNull($pivot->push_error);
        $this->assertSame('pending', $pivot->sync_status);
        $this->assertNull(\Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store->id)->errors([$pid])[$pid]);
        Http::assertNothingSent();
    }

    private function productWithVariations(): array
    {
        $store = $this->store();
        $pid = $this->product('TEE');
        foreach ([['TEE-S', 1], ['TEE-M', 2], ['TEE-L', 3]] as [$sku, $sort]) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $pid, 'sku' => $sku, 'quantity' => 4, 'absolute_price' => 1000, 'status' => 1, 'sort_order' => $sort,
            ]);
        }
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'TEE']);
        DB::table(\App\Integrations\Listings\ListingVariations::TABLE)->insert(['channel' => 'ventacart', 'store_id' => $store->id, 'product_id' => $pid, 'sku' => 'tee-m']);
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Tees']);
        VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid]);

        Http::fake([
            self::BASE . '/api/v1/variants/TEE-S' => Http::response(['ok' => true], 200),
            self::BASE . '/api/v1/variants/TEE-L' => Http::response(['error' => 'Variant not found'], 404),
            self::BASE . '/*' => Http::response(['ok' => true], 200),
        ]);

        return [$store, $pid, $group];
    }

    private function assertOnlyTheSoldVariantsWentAndTheGapIsNamed(VentaCartSetting $store, int $pid): void
    {
        Http::assertNotSent(fn ($request) => str_ends_with($request->url(), '/variants/TEE-M'));
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/variants/TEE-S'));

        $this->assertNull(VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('last_push_error'),
            'a variation the store lacks is not a failed push');
        $this->assertNotSame('error', DB::table('ventacart_product_group_products')->where('product_id', $pid)->value('sync_status'));
        $this->assertSame(['TEE-L'], array_column(\App\Integrations\Listings\ListingVariations::missing('ventacart', $store->id, [$pid])[$pid] ?? [], 'sku'));
    }

    public function test_the_scheduled_stock_push_sends_only_what_the_store_sells_and_names_what_it_lacks(): void
    {
        [$store, $pid] = $this->productWithVariations();

        $this->artisan('ventacart:push-stock', ['--store' => $store->id])->assertExitCode(0);

        $this->assertOnlyTheSoldVariantsWentAndTheGapIsNamed($store, $pid);
        $this->assertNotNull($store->fresh()->last_stock_push_at);
    }

    public function test_the_group_pages_push_stock_sends_only_what_the_store_sells_and_names_what_it_lacks(): void
    {
        [$store, $pid, $group] = $this->productWithVariations();
        $pusher = $this->userWith(['view_ventacart/product_group', 'manage_ventacart/product_group']);

        $this->actingAs($pusher)
            ->post(route('ext.ventacart.product-groups.push-stock', [$store->id, $group->id]), ['ids' => [$pid]])
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertOnlyTheSoldVariantsWentAndTheGapIsNamed($store, $pid);
    }

    public function test_a_ventacart_group_picks_the_stores_own_category_and_the_listing_reports_it(): void
    {
        $store = $this->store();
        $pid = $this->product();
        \Extensions\ventacart\Models\VentaCartCategory::create([
            'ventacart_setting_id' => $store->id, 'ventacart_category_id' => 42, 'name' => 'Pedals', 'parent_id' => 0,
        ]);
        $manager = $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group', 'manage_ventacart/product_group']);

        $this->actingAs($manager)
            ->get(route('ext.ventacart.product-groups.create', $store->id))
            ->assertOk()->assertSee('Category on Gear Depot')->assertSee('Pedals');

        $this->actingAs($manager)
            ->post(route('ext.ventacart.product-groups.store', $store->id), ['name' => 'Pedal group', 'ventacart_category_id' => 42, 'markup_percent' => 10])
            ->assertSessionHasNoErrors();
        $group = \Extensions\ventacart\Models\VentaCartProductGroup::query()->where('name', 'Pedal group')->first();
        $this->assertSame(42, (int) $group->ventacart_category_id);
        $this->assertSame([], $group->products()->pluck('product_id')->all());

        \Extensions\ventacart\Models\VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid]);
        $page = $this->actingAs($manager)->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))->assertOk();
        $page->assertSee('Category on Gear Depot');
        $page->assertSee('Pedals');
        $page->assertSee('Pedal group');

        $this->actingAs($manager)
            ->from(route('ext.ventacart.product-groups.create', $store->id))
            ->post(route('ext.ventacart.product-groups.store', $store->id), ['name' => 'Ghost', 'ventacart_category_id' => 999])
            ->assertSessionHasErrors('ventacart_category_id');
    }

    public function test_the_page_reads_the_store_on_load_and_writes_the_mirror(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response($this->storeProduct())]);

        $html = $this->actingAs($this->viewer())
            ->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('On Gear Depot right now', $html);
        $this->assertStringNotContainsString('Guitar Pedal 200 on the store', $html);
        $this->assertStringContainsString('GP-200-RED', $html);
        $this->assertStringContainsString('>Listed<', $html);
        $this->assertStringNotContainsString('Unlist on Gear Depot', $html);
        $this->assertStringNotContainsString('Save listing', $html);

        $listing = VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->first();
        $this->assertNotNull($listing);
        $this->assertSame(VentaCartListing::STATUS_ACTIVE, $listing->live_status);
        $this->assertSame(1150.0, $listing->live_price);
        $this->assertSame(6, $listing->live_quantity);
    }

    public function test_a_product_the_store_no_longer_has_reads_as_missing_and_drops_nothing_by_itself(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404)]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('no longer has a product with this SKU', $html);
        $this->assertSame(VentaCartListing::STATUS_MISSING, VentaCartListing::where('product_id', $pid)->value('live_status'));
    }

    public function test_a_store_that_does_not_answer_is_not_replaced_by_stale_data(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'live_status' => 'active', 'live_price' => 999, 'live_checked_at' => now()->subHour()]);

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'boom'], 500)]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('live state is simply unknown', $html);
        $this->assertStringNotContainsString('Read from Gear Depot as this page loaded', $html);
        $this->assertSame(999.0, VentaCartListing::where('product_id', $pid)->first()->live_price);
    }

    public function test_an_unlinked_product_the_store_already_holds_is_linked_on_sight(): void
    {
        $store = $this->store();
        $pid = $this->product();

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response($this->storeProduct(7777))]);

        $this->actingAs($this->viewer())->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))->assertOk();

        $this->assertSame(7777, (int) VentaCartProductLink::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('ventacart_product_id'));
    }

    public function test_the_chosen_templates_frame_the_description_sanitised(): void
    {
        $store = $this->store();
        $pid = $this->product();
        Http::fake([self::BASE . '/*' => Http::response(['error' => 'Product not found'], 404)]);

        $prefix = DescriptionTemplate::create([
            'integration' => 'ventacart', 'store_id' => $store->id, 'name' => 'Returns',
            'body' => '<p>Returns accepted within 30 days.</p>',
        ]);
        $suffix = DescriptionTemplate::create([
            'integration' => 'ventacart', 'store_id' => $store->id, 'name' => 'Warranty',
            'body' => '<p>One year warranty.</p><script>alert("xss-script")</script><img src="javascript:alert(\'xss-src\')" onerror="alert(\'xss-onerror\')">',
        ]);
        $foreign = DescriptionTemplate::create([
            'integration' => 'ventacart', 'store_id' => $store->id + 1000, 'name' => 'Elsewhere', 'body' => 'Another store policy',
        ]);
        VentaCartListing::create([
            'ventacart_setting_id' => $store->id, 'product_id' => $pid,
            'description_prefix_id' => $prefix->id, 'description_suffix_id' => $suffix->id,
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->getContent();

        $this->assertMatchesRegularExpression('#data-dp-block="prefix"\s*><p>Returns accepted within 30 days\.</p></div>#', $html);
        $this->assertStringContainsString('<template data-dt-preview="' . $prefix->id . '"><p>Returns accepted within 30 days.</p></template>', $html);
        $this->assertStringContainsString('One year warranty.', $html);
        $this->assertStringNotContainsString('xss-script', $html);
        $this->assertStringNotContainsString('xss-src', $html);
        $this->assertStringNotContainsString('xss-onerror', $html);
        $this->assertStringNotContainsString('data-dt-preview="' . $foreign->id . '"', $html);
        $this->assertStringNotContainsString('Another store policy', $html);
    }

    public function test_saving_keeps_the_listings_words_and_a_new_listing_starts_as_a_copy(): void
    {
        $store = $this->store();
        $pid = $this->product();

        $this->actingAs($this->manager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), [
                'name' => 'Pedal, store wording', 'description' => '   ', 'markup_percent' => '10', 'markup_fixed' => '',
            ])
            ->assertRedirect(route('ext.ventacart.listings.edit', [$store->id, $pid]));

        $listing = VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->firstOrFail();
        $this->assertSame('Pedal, store wording', $listing->name);
        $this->assertSame('The catalogue description.', $listing->description, 'a new listing starts with the catalog\'s words');
        $this->assertSame(10.0, $listing->markup_percent);
        $this->assertNull($listing->markup_fixed);
        $this->assertSame(1100.0, $listing->priceFor(1000));

        $this->actingAs($this->manager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['name' => 'Pedal, store wording', 'description' => '<p><br></p>', 'description_edited' => '1'])
            ->assertRedirect();
        $this->assertNull($listing->fresh()->description);

        $pfx = (string) config('catalog.prefix');
        $this->assertSame('Guitar Pedal 200', DB::table($pfx . 'product_description')->where('product_id', $pid)->value('name'));
    }

    public function test_a_catalog_edit_shows_a_catalog_change_that_save_and_ignore_clear(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid]);
        Http::fake([self::BASE . '/*' => Http::response(['error' => 'Product not found'], 404)]);

        $pfx = (string) config('catalog.prefix');
        $minutes = 0;
        $editCatalog = function (array $words) use ($pfx, $pid, &$minutes) {
            DB::table($pfx . 'product_description')->where('product_id', $pid)->update($words);
            DB::table($pfx . 'product')->where('product_id', $pid)->update(['date_modified' => now()->addMinutes(++$minutes)]);
        };
        $listing = fn () => VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->firstOrFail();

        $editCatalog(['description' => '<p>Newer catalog words.</p>']);
        $this->assertSame('The catalogue description.', $listing()->description, 'the listing keeps its own copy');
        $this->assertSame(['description'], $listing()->catalogChange());
        $this->assertSame([$pid], \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for($store->id)->productIdsIn('drift'));

        $this->actingAs($this->manager())->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->assertSee('data-lcx-open', false)
            ->assertSee('data-lcx-panel', false)
            ->assertSee('Newer catalog words.');

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.catalog_change_ignore', [$store->id, $pid]), ['back' => '/channels'])
            ->assertRedirect('/channels')
            ->assertSessionHas('status', 'Catalog change ignored.');
        $this->assertSame([], $listing()->catalogChange());

        $editCatalog(['name' => 'Guitar Pedal 300']);
        $this->assertSame(['title', 'description'], $listing()->catalogChange(), 'the button comes back when the catalog moves again; Ignore kept the listing\'s own description');

        $this->actingAs($this->manager())->post(route('ext.ventacart.listings.catalog_change_save', [$store->id, $pid]), [
            'listing' => ['title' => 'Guitar Pedal 300', 'description' => '<p>Newer catalog words.</p>', 'description_edited' => '1'],
        ])->assertRedirect(route('ext.ventacart.listings.edit', [$store->id, $pid]))->assertSessionHas('status', 'Saved.');

        $this->assertSame('Guitar Pedal 300', $listing()->name);
        $this->assertSame('<p>Newer catalog words.</p>', $listing()->description);
        $this->assertSame([], $listing()->catalogChange());

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.catalog_change_ignore', [$store->id, $this->product('GP-300')]))
            ->assertNotFound();
    }

    public function test_a_fees_only_pull_never_rewrites_an_order_line(): void
    {
        $pfx = (string) config('catalog.prefix');
        $store = $this->store();
        $pid = $this->product();

        $oid = (int) DB::table($pfx . 'order')->insertGetId([
            'invoice_prefix' => 'INV-', 'store_id' => 0, 'store_name' => 'Test store', 'store_url' => '',
            'customer_id' => 0, 'customer_group_id' => 0, 'firstname' => 'A', 'lastname' => 'B', 'email' => 'a@b.test',
            'telephone' => '', 'fax' => '', 'custom_field' => '',
            'payment_firstname' => '', 'payment_lastname' => '', 'payment_company' => '', 'payment_address_1' => '', 'payment_address_2' => '',
            'payment_city' => '', 'payment_postcode' => '', 'payment_country' => '', 'payment_country_id' => 0, 'payment_zone' => '', 'payment_zone_id' => 0,
            'payment_address_format' => '', 'payment_custom_field' => '', 'payment_method' => '', 'payment_code' => '',
            'shipping_firstname' => '', 'shipping_lastname' => '', 'shipping_company' => '', 'shipping_address_1' => '', 'shipping_address_2' => '',
            'shipping_city' => '', 'shipping_postcode' => '', 'shipping_country' => '', 'shipping_country_id' => 0, 'shipping_zone' => '', 'shipping_zone_id' => 0,
            'shipping_address_format' => '', 'shipping_custom_field' => '', 'shipping_method' => '', 'shipping_code' => '',
            'comment' => '', 'affiliate_id' => 0, 'commission' => 0, 'marketing_id' => 0, 'tracking' => '',
            'total' => 1000, 'order_status_id' => 5, 'language_id' => (int) config('catalog.default_language_id'),
            'currency_id' => 1, 'currency_code' => 'PHP', 'currency_value' => 1,
            'ip' => '', 'forwarded_ip' => '', 'user_agent' => '', 'accept_language' => '',
            'courier_id' => 0, 'tracking_number' => '', 'oe_import' => 0,
            'marketplace_source' => 'ventacart:' . $store->id, 'marketplace_order_id' => '77',
            'shipping_cost' => 0, 'payment_cost' => 0, 'extra_cost' => 0,
            'date_added' => '2026-06-15 10:00:00', 'date_modified' => '2026-06-15 10:00:00',
        ]);
        DB::table($pfx . 'order_product')->insert([
            'order_id' => $oid, 'product_id' => $pid, 'name' => 'Guitar Pedal 200', 'model' => 'GP-200',
            'quantity' => 1, 'price' => 1000, 'total' => 1000, 'tax' => 0, 'reward' => 0,
            'cost' => 111.11,
        ]);
        DB::table('ventacart_orders')->insert([
            'ventacart_setting_id' => $store->id, 'ventacart_order_id' => 77, 'status' => 'delivered',
            'total' => 1000, 'raw' => '{}', 'catalog_order_id' => $oid,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $order = [
            'id' => 77, 'order_number' => 77, 'status' => 'delivered',
            'created_at' => '2026-06-15T10:00:00Z', 'updated_at' => '2026-06-15T10:00:00Z',
            'totals' => ['total' => 1000, 'subtotal' => 1000, 'shipping' => 0],
            'fees' => ['payment_fee' => 25.5, 'shipping_cost' => 60, 'net_after_fees' => 914.5],
            'items' => [['sku' => 'GP-200', 'quantity' => 1, 'price' => 1000, 'total' => 1000]],
        ];
        Http::fake([
            self::BASE . '/api/v1/order-statuses*' => Http::response(['data' => []]),
            self::BASE . '/api/v1/orders/77' => Http::response($order),
            self::BASE . '/api/v1/orders*' => Http::response(['data' => [$order], 'meta' => ['last_page' => 1]]),
        ]);

        (new \Extensions\ventacart\Services\VentaCart\VentaCartOrderSync(
            new \Extensions\ventacart\Services\VentaCart\VentaCartClient($store), $store
        ))->setFeesOnly()->setSkipStockAdjust()->pull(since: '2026-06-01', full: true, maxPages: 1);


        $this->assertSame(111.11, round((float) DB::table($pfx . 'order_product')->where('order_id', $oid)->value('cost'), 2),
            'A fees run re-stamped the cost of goods from the catalogue. That moves every profit figure in the system.');
        $this->assertSame(1, DB::table($pfx . 'order_product')->where('order_id', $oid)->count());

        $rows = DB::table($pfx . 'order_total')->where('order_id', $oid)
            ->whereIn('code', ['marketplace_payment_fee', 'marketplace_shipping_fee'])->pluck('value', 'code');
        $this->assertSame(-25.5, round((float) ($rows['marketplace_payment_fee'] ?? 0), 2));
        $this->assertSame(-60.0, round((float) ($rows['marketplace_shipping_fee'] ?? 0), 2));
    }

    public function test_the_storefronts_tracking_page_reaches_the_order(): void
    {
        $store = $this->store();
        $this->product();
        $pfx = (string) config('catalog.prefix');
        $order = [
            'id' => 88, 'order_number' => 88, 'status' => 'shipped',
            'created_at' => '2026-06-15T10:00:00Z', 'updated_at' => '2026-06-15T10:00:00Z',
            'totals' => ['total' => 1000, 'subtotal' => 1000, 'shipping' => 0],
            'tracking_number' => 'QX12345', 'tracking_link' => 'https://app.gogoxpress.com/track/QX12345',
            'items' => [['sku' => 'GP-200', 'quantity' => 1, 'price' => 1000, 'total' => 1000]],
        ];
        $pull = function (array $order) use ($store) {
            Http::fake([
                self::BASE . '/api/v1/order-statuses*' => Http::response(['data' => []]),
                self::BASE . '/api/v1/orders/88' => Http::response($order),
                self::BASE . '/api/v1/orders*' => Http::response(['data' => [$order], 'meta' => ['last_page' => 1]]),
            ]);
            (new \Extensions\ventacart\Services\VentaCart\VentaCartOrderSync(
                new \Extensions\ventacart\Services\VentaCart\VentaCartClient($store), $store
            ))->setSkipStockAdjust()->pull(since: '2026-06-01', full: true, maxPages: 1);
        };

        $pull($order);
        $url = fn () => DB::table($pfx . 'order')->where('marketplace_source', 'ventacart:' . $store->id)->where('marketplace_order_id', '88')->value('tracking_url');
        $this->assertSame('https://app.gogoxpress.com/track/QX12345', $url());

        unset($order['tracking_link']);
        $pull($order);
        $this->assertSame('https://app.gogoxpress.com/track/QX12345', $url(), 'a payload without the field keeps the stored page');
    }

    public function test_the_fees_tie_back_to_the_storefronts_net_after_fees(): void
    {
        $store = $this->store();
        $raw = [
            'id' => 91,
            'totals' => ['subtotal' => 12000, 'shipping' => 180, 'total' => 11990],
            'fees' => ['payment_fee' => 374.70, 'shipping_cost' => 90.00, 'net_after_fees' => 11525.30],
        ];
        DB::table('ventacart_orders')->insert([
            'ventacart_setting_id' => $store->id, 'ventacart_order_id' => 91, 'status' => 'delivered',
            'total' => 11990, 'raw' => json_encode($raw), 'catalog_order_id' => 4242,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $buckets = (new \Extensions\ventacart\VentaCartExtension($this->app))->feeBucketsForOrder(4242);

        $this->assertNotNull($buckets);
        $this->assertSame(374.70, round($buckets['payment'], 2));
        $this->assertSame(90.00, round($buckets['shipping'], 2), "The delivery fee is the COURIER's charge (fees.shipping_cost), not the buyer's 180.");
        $this->assertSame(0.0, round($buckets['commission'], 2), 'A storefront takes no commission from its own owner.');

        $ours = round(11990 - $buckets['payment'] - $buckets['shipping'], 2);
        $this->assertSame(11525.30, $ours,
            'What the ERP subtracts does not come to the storefront\'s own net figure, so it is subtracting the wrong fields.');
    }

    public function test_an_order_whose_fees_are_not_settled_yet_writes_nothing(): void
    {
        $store = $this->store();
        DB::table('ventacart_orders')->insert([
            'ventacart_setting_id' => $store->id, 'ventacart_order_id' => 92, 'status' => 'pending',
            'total' => 500, 'catalog_order_id' => 4243,
            'raw' => json_encode(['id' => 92, 'fees' => ['payment_fee' => null, 'shipping_cost' => null, 'net_after_fees' => null]]),
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertNull((new \Extensions\ventacart\VentaCartExtension($this->app))->feeBucketsForOrder(4243),
            'An order whose fees are not settled yet must not be stamped with zeroes: it would read as free.');

        DB::table('ventacart_orders')->where('ventacart_order_id', 92)->update([
            'raw' => json_encode(['id' => 92, 'fees' => ['payment_fee' => 12.5, 'shipping_cost' => null]]),
        ]);
        $half = (new \Extensions\ventacart\VentaCartExtension($this->app))->feeBucketsForOrder(4243);
        $this->assertSame(12.5, round($half['payment'], 2));
        $this->assertSame(0.0, round($half['shipping'], 2), 'An unbooked parcel has no courier charge to report yet.');
    }

    public function test_the_pull_walks_every_page_the_storefront_reports(): void
    {
        $store = $this->store();

        $order = fn (int $id) => [
            'id' => $id, 'order_number' => $id, 'status' => 'delivered',
            'created_at' => '2026-06-15T10:00:00Z', 'updated_at' => '2026-06-15T10:00:00Z',
            'totals' => ['total' => 100, 'subtotal' => 100, 'shipping' => 0],
            'items' => [],
        ];

        Http::fake([
            self::BASE . '/api/v1/order-statuses*' => Http::response(['data' => []]),
            self::BASE . '/api/v1/orders/1' => Http::response($order(1)),
            self::BASE . '/api/v1/orders/2' => Http::response($order(2)),
            self::BASE . '/api/v1/orders?*page=2*' => Http::response(['data' => [$order(2)], 'current_page' => 2, 'last_page' => 2]),
            self::BASE . '/api/v1/orders*' => Http::response(['data' => [$order(1)], 'current_page' => 1, 'last_page' => 2]),
        ]);

        (new \Extensions\ventacart\Services\VentaCart\VentaCartOrderSync(
            new \Extensions\ventacart\Services\VentaCart\VentaCartClient($store), $store
        ))->setFeesOnly()->setSkipStockAdjust()->pull(since: '2026-06-01', full: true, maxPages: 10);

        $this->assertSame(2, DB::table('ventacart_orders')->count(),
            'The pull stopped after the first page: a store with more orders than fit on one page is silently half-read.');
    }

    public function test_a_group_push_applies_the_groups_markup_and_sends_its_category(): void
    {
        $store = $this->store();
        $pid = $this->product();
        $group = \Extensions\ventacart\Models\VentaCartProductGroup::create([
            'ventacart_setting_id' => $store->id, 'name' => 'Pedals',
            'markup_percent' => 20, 'ventacart_category_id' => 77,
        ]);
        \Extensions\ventacart\Models\VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid]);

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['id' => 9101, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201),
        ]);

        $pusher = $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group', 'manage_ventacart/product_group']);
        $this->actingAs($pusher)
            ->post(route('ext.ventacart.product-groups.push', [$store->id, $group->id]), ['ids' => [$pid]])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/products')) {
                return false;
            }
            $body = $request->data();

            return round((float) $body['price'], 2) === 1200.0
                && ($body['category_ids'] ?? null) === [77];
        });
    }

    public function test_a_listings_none_declines_its_groups_category(): void
    {
        $store = $this->store();
        $pid = $this->product();
        \Extensions\ventacart\Models\VentaCartCategory::create(['ventacart_setting_id' => $store->id, 'ventacart_category_id' => 77, 'name' => 'Pedals', 'parent_id' => 0]);
        $group = \Extensions\ventacart\Models\VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Pedals', 'ventacart_category_id' => 77]);
        \Extensions\ventacart\Models\VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid]);
        $editor = $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing']);

        $this->actingAs($editor)->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['ventacart_category_id' => 0])->assertSessionHasNoErrors();
        $saved = VentaCartListing::query()->where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('ventacart_category_id');
        $this->assertNotNull($saved, 'None is kept as the listing\'s answer, not blanked back to following');
        $this->assertSame(0, (int) $saved);

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['id' => 9102, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201),
        ]);
        $this->actingAs($editor)->post(route('ext.ventacart.listings.push', [$store->id, $pid]))->assertRedirect();
        Http::assertSent(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/products')
            && ($request->data()['category_ids'] ?? null) === []);

        $loose = $this->product('GP-300');
        $this->actingAs($editor)->put(route('ext.ventacart.listings.update', [$store->id, $loose]), ['ventacart_category_id' => 0])->assertSessionHasNoErrors();
        $this->assertNull(VentaCartListing::query()->where('ventacart_setting_id', $store->id)->where('product_id', $loose)->value('ventacart_category_id'), 'with no group category to decline, None is blank');
    }

    public function test_a_listings_own_markup_beats_its_groups(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartListing::create([
            'ventacart_setting_id' => $store->id, 'product_id' => $pid, 'markup_percent' => 10,
        ]);
        $group = \Extensions\ventacart\Models\VentaCartProductGroup::create([
            'ventacart_setting_id' => $store->id, 'name' => 'Pedals', 'markup_percent' => 50,
        ]);
        \Extensions\ventacart\Models\VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid]);

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['id' => 9101, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201),
        ]);

        $pusher = $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group', 'manage_ventacart/product_group']);
        $this->actingAs($pusher)
            ->post(route('ext.ventacart.product-groups.push', [$store->id, $group->id]), ['ids' => [$pid]])
            ->assertRedirect();

        Http::assertSent(fn ($request) => $request->method() !== 'POST'
            || ! str_ends_with($request->url(), '/api/v1/products')
            || round((float) $request->data()['price'], 2) === 1100.0);
    }

    public function test_a_group_push_sends_the_listings_own_words_not_the_catalogues(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartListing::create([
            'ventacart_setting_id' => $store->id, 'product_id' => $pid,
            'name' => 'Store only name', 'description' => 'Store only description.',
        ]);
        $group = \Extensions\ventacart\Models\VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Pedals']);
        \Extensions\ventacart\Models\VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid]);

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['id' => 9101, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201),
        ]);

        $pusher = $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group', 'manage_ventacart/product_group']);
        $this->actingAs($pusher)
            ->post(route('ext.ventacart.product-groups.push', [$store->id, $group->id]), ['ids' => [$pid]])
            ->assertRedirect();

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/products')) {
                return false;
            }
            $body = $request->data();

            return $body['name'] === 'Store only name'
                && $body['description'] === 'Store only description.';
        });
    }

    public function test_pushing_from_the_page_sends_the_records_content_and_price_and_creates_when_the_store_has_none(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'name' => 'Pedal, store wording', 'markup_percent' => 10]);

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['id' => 9001, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push', [$store->id, $pid]))
            ->assertRedirect(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertSessionHas('status');

        Http::assertSent(function ($request) {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v1/products')) {
                return false;
            }
            $body = $request->data();

            return $body['name'] === 'Pedal, store wording'
                && $body['description'] === 'The catalogue description.'
                && $body['sku'] === 'GP-200'
                && (float) $body['price'] === 1100.0;
        });

        $this->assertSame(9001, (int) VentaCartProductLink::where('product_id', $pid)->value('ventacart_product_id'));

        $listing = VentaCartListing::where('product_id', $pid)->firstOrFail();
        $this->assertNotNull($listing->last_pushed_at);
        $this->assertSame('listing', $listing->last_push_source);
        $this->assertSame('created', $listing->last_push_settings['state']);
        $this->assertSame(1100.0, (float) $listing->last_push_settings['price']);
        $this->assertSame(VentaCartListing::STATUS_ACTIVE, $listing->live_status);
    }

    public function test_pushing_updates_in_place_when_the_store_holds_the_sku(): void
    {
        $store = $this->store();
        $pid = $this->product();

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::sequence()
                ->push($this->storeProduct(5001))
                ->push(['id' => 5001, 'sku' => 'GP-200', 'images' => ['a'], 'images_failed' => [['url' => 'x', 'reason' => 'Host resolves to a disallowed address']]]),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('warning');

        $this->assertSame('Updated on Gear Depot. 1 of 2 images were rejected by the store: 1x Host resolves to a disallowed address.', session('warning'));
        Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/api/v1/products/GP-200'));
        $this->assertSame('updated', VentaCartListing::where('product_id', $pid)->first()->last_push_settings['state']);
    }

    public function test_a_refusal_reaches_the_page_in_the_stores_words(): void
    {
        $store = $this->store();
        $pid = $this->product();

        Http::fake([
            self::BASE . '/api/v1/products/GP-200' => Http::response(['error' => 'Product not found'], 404),
            self::BASE . '/api/v1/products' => Http::response(['error' => 'images_unavailable'], 422),
        ]);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $this->assertStringContainsString('will not create a product it cannot fetch images for', session('error'));
        $this->assertNull(VentaCartListing::where('product_id', $pid)->value('last_pushed_at'));
    }

    public function test_unlist_and_relist_write_through_to_the_store_and_the_mirror(): void
    {
        $store = $this->store();
        $pid = $this->product();
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);

        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response(['id' => 5001, 'status' => false])]);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.toggle', [$store->id, $pid]), ['action' => 'unlist'])
            ->assertRedirect()
            ->assertSessionHas('status');

        Http::assertSent(fn ($r) => $r->method() === 'PUT' && $r->data() === ['status' => false]);
        $this->assertSame(VentaCartListing::STATUS_INACTIVE, VentaCartListing::where('product_id', $pid)->value('live_status'));

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.toggle', [$store->id, $pid]), ['action' => 'relist'])
            ->assertRedirect();

        $this->assertSame(VentaCartListing::STATUS_ACTIVE, VentaCartListing::where('product_id', $pid)->value('live_status'));
    }

    public function test_the_view_tier_cannot_write(): void
    {
        $store = $this->store();
        $pid = $this->product();
        Http::fake();

        $this->actingAs($this->viewer())->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['name' => 'x'])->assertRedirect()->assertSessionHas('error');
        $this->actingAs($this->viewer())->post(route('ext.ventacart.listings.push', [$store->id, $pid]))->assertRedirect()->assertSessionHas('error');

        Http::assertNothingSent();
        $this->assertSame(0, VentaCartListing::count());
    }

    public function test_the_page_is_reachable_from_the_group_products_row_and_the_product_page(): void
    {
        $store = $this->store();
        $pid = $this->product();
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Pedals']);
        VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid, 'sync_status' => 'pending']);
        Http::fake();

        $html = $this->actingAs($this->userWith(['view_ventacart/product_group', 'manage_ventacart/product_group', 'view_ventacart/listing']))
            ->get(route('ext.ventacart.product-groups.products', [$store->id, $group->id]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString(route('ext.ventacart.listings.edit', [$store->id, $pid]), $html);

        $actions = $this->app->make(VentaCartExtension::class, ['app' => $this->app])->productActions($pid);
        $this->assertCount(1, $actions);
        $this->assertSame('Listing on Gear Depot', $actions[0]['label']);
        $this->assertSame('view_ventacart/listing', $actions[0]['permission']);
    }

    public function test_a_disabled_store_is_not_asked_and_still_shows_the_record(): void
    {
        $store = $this->store(false);
        $pid = $this->product();
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->getContent();

        $this->assertStringNotContainsString('This store is turned off', $html);
        Http::assertNothingSent();
    }

    public function test_add_products_brings_a_product_onto_this_store_and_the_engine_names_its_state(): void
    {
        $store = $this->store();
        $pid = $this->product('GP-200');
        $nowhere = $this->product('PICK-1');

        $page = $this->actingAs($this->manager())->get(route('ext.ventacart.listings.index', $store->id))->assertOk();
        $page->assertSee('data-add-panel-open', false);
        $page->assertSee('ventacart-add-panel');
        $page->assertDontSee('Go to Product Groups');

        $search = $this->actingAs($this->manager())->getJson(route('ext.ventacart.products.catalogue_search', [$store->id, 'q' => 'PICK']))->assertOk()->json();
        $this->assertSame([$nowhere], array_column($search['items'], 'id'));
        $this->actingAs($this->manager())->post(route('ext.ventacart.products.add_to_store', [$store->id, $nowhere]))->assertRedirect();
        $this->assertDatabaseHas('ventacart_listings', ['ventacart_setting_id' => $store->id, 'product_id' => $nowhere]);
        $page = $this->actingAs($this->manager())->get(route('ext.ventacart.listings.index', $store->id))->assertOk();
        $page->assertDontSee('a SKU on the catalog product');
        $page->assertDontSee('a product image on the catalog product');
        $page->assertSee('Remove from this channel');

        \Extensions\ventacart\Models\VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'ventacart_product_id' => 9001, 'product_id' => $pid, 'sku' => 'GP-200']);
        \Extensions\ventacart\Models\VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'live_status' => 'active', 'live_checked_at' => now(), 'last_pushed_at' => now()]);
        $states = (new \Extensions\ventacart\VentaCartExtension($this->app))->listingStates([$pid, $nowhere]);
        $this->assertSame(\App\Integrations\Listings\ListingState::LIVE, $states[$pid]->state);
        $this->assertSame(\App\Integrations\Listings\ListingState::NOT_LISTED, $states[$nowhere]->state);
        $this->assertContains($pid, (new \Extensions\ventacart\VentaCartExtension($this->app))->listedProductIds());
        $this->assertStringContainsString('/listings/' . $pid, (new \Extensions\ventacart\VentaCartExtension($this->app))->listingUrls([$pid])[$pid]);

        $this->actingAs($this->manager())->post(route('ext.ventacart.products.remove_from_store', [$store->id, $nowhere]))->assertRedirect();
        $this->assertDatabaseMissing('ventacart_listings', ['ventacart_setting_id' => $store->id, 'product_id' => $nowhere]);
        $this->actingAs($this->manager())->get(route('ext.ventacart.listings.index', $store->id))->assertOk()->assertDontSee('PICK-1');
    }


    public function test_push_price_sends_the_listings_price_rule(): void
    {
        $store = $this->store();
        $pid = $this->product('GP-200', 1000);
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);
        VentaCartListing::query()->create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'markup_percent' => 10]);
        Http::fake([self::BASE . '/api/v1/products/GP-200' => Http::response(['data' => ['sku' => 'GP-200']])]);

        $this->actingAs($this->userWith(['view_ventacart/listing', 'manage_ventacart/listing']))
            ->post(route('ext.ventacart.listings.push_price', [$store->id, $pid]))
            ->assertRedirect();

        Http::assertSent(fn ($r) => $r->method() === 'PUT'
            && str_contains($r->url(), '/api/v1/products/GP-200')
            && (float) ($r->data()['price'] ?? 0) === 1100.0);
    }


    private function fakeAStoreWithoutTheProduct(): void
    {
        Http::fake(function ($request) {
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/products')) {
                return Http::response(['id' => 9001, 'sku' => 'GP-200', 'images' => [], 'images_failed' => []], 201);
            }
            if ($request->method() === 'PUT') {
                return Http::response(['data' => ['sku' => 'GP-200']]);
            }

            return Http::response(['error' => 'Product not found'], 404);
        });
    }

    private function sentPrice(string $method, string $path): ?float
    {
        $sent = Http::recorded(fn ($request) => $request->method() === $method && str_ends_with($request->url(), $path));

        return $sent->isEmpty() ? null : (float) ($sent->last()[0]->data()['price'] ?? 0);
    }

    public function test_a_listings_own_price_is_where_its_rule_starts(): void
    {
        $store = $this->store();
        $pid = $this->product('GP-200', 1000);
        $this->fakeAStoreWithoutTheProduct();

        $this->actingAs($this->manager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['price' => '500', 'markup_percent' => '10'])
            ->assertSessionHasNoErrors();
        $this->assertSame(500.0, (float) VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('price'));

        $this->actingAs($this->manager())->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->assertSee('id="vl-price"', false)
            ->assertSee('550.00');

        $this->actingAs($this->manager())->post(route('ext.ventacart.listings.push', [$store->id, $pid]))->assertRedirect();
        $this->assertSame(550.0, $this->sentPrice('POST', '/api/v1/products'));

        $this->actingAs($this->manager())->post(route('ext.ventacart.listings.push_price', [$store->id, $pid]))->assertRedirect();
        $this->assertSame(550.0, $this->sentPrice('PUT', '/api/v1/products/GP-200'));
    }

    public function test_a_groups_rule_is_added_to_the_listings_own_price(): void
    {
        $store = $this->store();
        $pid = $this->product('GP-200', 1000);
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'price' => 500]);
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Pedals', 'markup_percent' => 20]);
        VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid]);
        $this->fakeAStoreWithoutTheProduct();

        $this->actingAs($this->manager())->post(route('ext.ventacart.listings.push_price', [$store->id, $pid]))->assertRedirect();

        $this->assertSame(600.0, $this->sentPrice('PUT', '/api/v1/products/GP-200'));
    }

    public function test_a_blank_price_starts_from_the_catalog_price(): void
    {
        $store = $this->store();
        $pid = $this->product('GP-200', 1000);
        VentaCartListing::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'price' => 500, 'markup_percent' => 10]);
        $this->fakeAStoreWithoutTheProduct();

        $this->actingAs($this->manager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['price' => '', 'markup_percent' => '10'])
            ->assertSessionHasNoErrors();
        $this->assertNull(VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('price'));

        $this->actingAs($this->manager())->post(route('ext.ventacart.listings.push', [$store->id, $pid]))->assertRedirect();

        $this->assertSame(1100.0, $this->sentPrice('POST', '/api/v1/products'));
    }

    public function test_a_product_with_variations_has_no_price_field(): void
    {
        $store = $this->store();
        $pid = $this->product('TEE');
        DB::table('product_option_combinations')->insert([
            'product_id' => $pid, 'sku' => 'TEE-S', 'quantity' => 4, 'absolute_price' => 1000, 'status' => 1, 'sort_order' => 1,
        ]);
        Http::fake([self::BASE . '/*' => Http::response(['error' => 'Product not found'], 404)]);

        $this->actingAs($this->manager())->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertOk()
            ->assertDontSee('id="vl-price"', false)
            ->assertSee('id="vl-markup-pct"', false)
            ->assertSee('id="vl-weight"', false);

        $this->actingAs($this->manager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['price' => '500'])
            ->assertSessionHasNoErrors();
        $this->assertNull(VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('price'));
    }

    public function test_the_parcel_sends_the_listings_own_figures_and_follows_the_catalog_for_the_rest(): void
    {
        $store = $this->store();
        $pid = $this->product();
        DB::table(config('catalog.prefix') . 'product')->where('product_id', $pid)->update(['weight' => 2, 'length' => 10, 'width' => 20, 'height' => 5]);
        $this->fakeAStoreWithoutTheProduct();

        $this->actingAs($this->manager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['weight' => '1.5', 'package_length' => '30', 'package_width' => '', 'package_height' => ''])
            ->assertSessionHasNoErrors();

        $this->actingAs($this->manager())->post(route('ext.ventacart.listings.push', [$store->id, $pid]))->assertRedirect();

        $sent = Http::recorded(fn ($request) => $request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/products'));
        $this->assertCount(1, $sent);
        $body = $sent->first()[0]->data();
        $this->assertSame([1.5, 30.0, 20.0, 5.0], [(float) $body['weight'], (float) $body['length'], (float) $body['width'], (float) $body['height']]);

        $this->actingAs($this->manager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['weight' => '0', 'package_length' => '0'])
            ->assertSessionHasErrors(['weight', 'package_length']);
        $this->assertSame(1.5, (float) VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('weight'));
    }

    public function test_a_listing_holds_one_of_its_own_stores_categories(): void
    {
        $store = $this->store();
        $pid = $this->product();
        \Extensions\ventacart\Models\VentaCartCategory::create(['ventacart_setting_id' => $store->id, 'ventacart_category_id' => 42, 'name' => 'Pedals', 'parent_id' => 0]);

        $this->actingAs($this->manager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['ventacart_category_id' => 42])
            ->assertSessionHasNoErrors();
        $this->assertSame(42, (int) VentaCartListing::query()->where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('ventacart_category_id'));

        $this->actingAs($this->manager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), ['ventacart_category_id' => 999])
            ->assertSessionHasErrors('ventacart_category_id');
    }

    public function test_a_stock_push_sends_the_variants_the_store_holds_and_names_the_one_it_lacks(): void
    {
        $store = $this->store();
        $pid = $this->product();
        foreach (['GP-200-S', 'GP-200-M', 'GP-200-L'] as $i => $sku) {
            DB::table('product_option_combinations')->insert([
                'product_id' => $pid, 'sku' => $sku, 'quantity' => 3, 'absolute_price' => 1000, 'status' => 1, 'sort_order' => $i,
            ]);
        }
        VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'GP-200']);
        Http::fake([
            self::BASE . '/api/v1/variants/GP-200-L' => Http::response(['error' => 'Variant not found'], 404),
            self::BASE . '/api/v1/variants/*' => Http::response(['sku' => 'ok', 'quantity' => 3]),
        ]);

        $this->actingAs($this->manager())
            ->from('/dashboard')
            ->post(route('ext.ventacart.listings.push_stock', [$store->id, $pid]))
            ->assertRedirect('/dashboard')
            ->assertSessionHas('status');

        foreach (['GP-200-S', 'GP-200-M'] as $sku) {
            Http::assertSent(fn ($r) => $r->method() === 'PUT' && str_ends_with($r->url(), '/api/v1/variants/' . $sku));
        }
        $this->assertNull(VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('last_push_error'));
        $held = json_decode((string) DB::table('listing_store_skus')
            ->where('channel', 'ventacart')->where('store_id', $store->id)->where('product_id', $pid)->value('skus'), true);
        $this->assertEqualsCanonicalizing(['GP-200-S', 'GP-200-M'], $held);

        $line = (string) \Extensions\ventacart\Services\VentaCart\VentaCartListingStates::for((int) $store->id)->errors([$pid])[$pid];
        $this->assertStringContainsString('Not on Gear Depot:', $line);
        $this->assertStringContainsString('GP-200-L', $line);
    }

    private function productNamedByTheCatalog(bool $linked = true, bool $withSize = false): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $store = $this->store();
        $pid = $this->product('TEE');

        $option = function (string $name) use ($pfx, $langId, $pid): array {
            $optionId = (int) DB::table($pfx . 'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
            DB::table($pfx . 'option_description')->insert(['option_id' => $optionId, 'language_id' => $langId, 'name' => $name]);
            $productOptionId = (int) DB::table($pfx . 'product_option')->insertGetId([
                'product_id' => $pid, 'option_id' => $optionId, 'value' => '', 'required' => 0,
            ]);

            return [$optionId, $productOptionId];
        };
        $value = function (array $option, string $name, string $sku, int $sort) use ($pfx, $langId, $pid): int {
            [$optionId, $productOptionId] = $option;
            $valueId = (int) DB::table($pfx . 'option_value')->insertGetId(['option_id' => $optionId, 'image' => '', 'sort_order' => $sort]);
            DB::table($pfx . 'option_value_description')->insert([
                'option_value_id' => $valueId, 'language_id' => $langId, 'option_id' => $optionId, 'name' => $name,
            ]);

            return (int) DB::table($pfx . 'product_option_value')->insertGetId([
                'product_option_id' => $productOptionId, 'product_id' => $pid,
                'option_id' => $optionId, 'option_value_id' => $valueId,
                'sku' => $sku, 'quantity' => 4, 'subtract' => 1,
                'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+',
                'weight' => 0, 'weight_prefix' => '+',
                'cost' => 0, 'cost_amount' => 0, 'cost_percentage' => 0, 'cost_additional' => 0,
                'absolute_cost' => 0, 'cost_prefix' => '+', 'absolute_price' => 1000,
            ]);
        };

        $colour = $option('Colour');
        $sizeM = $withSize ? $value($option('Size'), 'M', '', 0) : null;

        foreach ([['Crimson', 'TEE-RED'], ['Navy', 'TEE-BLU']] as $i => [$name, $sku]) {
            $povId = $value($colour, $name, $sku, $i);
            $comboId = (int) DB::table('product_option_combinations')->insertGetId([
                'product_id' => $pid, 'sku' => $sku, 'quantity' => 4, 'absolute_price' => 1000,
                'status' => 1, 'sort_order' => $i, 'created_at' => now(), 'updated_at' => now(),
            ]);
            DB::table('product_option_combination_values')->insert(['combination_id' => $comboId, 'product_option_value_id' => $povId]);
            if ($sizeM !== null) {
                DB::table('product_option_combination_values')->insert(['combination_id' => $comboId, 'product_option_value_id' => $sizeM]);
            }
        }

        if ($linked) {
            VentaCartProductLink::create(['ventacart_setting_id' => $store->id, 'product_id' => $pid, 'ventacart_product_id' => 5001, 'sku' => 'TEE']);
        }
        $group = VentaCartProductGroup::create(['ventacart_setting_id' => $store->id, 'name' => 'Tees']);
        VentaCartProductGroupProduct::create(['ventacart_product_group_id' => $group->id, 'product_id' => $pid]);

        return [$store, $pid, $group];
    }

    private function storeTee(string $type, array $values): array
    {
        $variants = [];
        $typeValues = [];
        $n = 0;
        foreach ($values as $sku => $value) {
            $n++;
            $typeValues[] = ['id' => $n, 'value' => $value];
            $variants[] = ['id' => 700 + $n, 'sku' => $sku, 'price' => 1000.0, 'quantity' => 4, 'is_active' => true,
                'option_values' => [['id' => $n, 'value' => $value]]];
        }

        return [
            'id' => 5001, 'sku' => 'TEE', 'name' => 'Tee', 'price' => 1000.0, 'quantity' => 8,
            'status' => true, 'has_variants' => true, 'images' => [],
            'variant_types' => [['id' => 1, 'name' => $type, 'values' => $typeValues]],
            'variants' => $variants,
        ];
    }

    private function fakeTheTeeStore(?array $held, int $putStatus = 200, ?array $putBody = null): void
    {
        Http::fake(function ($request) use ($held, $putStatus, $putBody) {
            $item = str_ends_with($request->url(), '/api/v1/products/TEE');
            if ($item && $request->method() === 'GET') {
                return $held !== null ? Http::response($held) : Http::response(['error' => 'Product not found'], 404);
            }
            if ($item && $request->method() === 'PUT') {
                return Http::response($putBody ?? $held ?? [], $putStatus);
            }
            if ($request->method() === 'POST' && str_ends_with($request->url(), '/api/v1/products')) {
                return Http::response(['id' => 5001, 'sku' => 'TEE', 'images' => [], 'images_failed' => []], 201);
            }

            return Http::response(['error' => 'unexpected'], 500);
        });
    }

    private function sentVariations(string $method, string $path): array
    {
        $sent = Http::recorded(fn ($r) => $r->method() === $method && str_ends_with($r->url(), $path));
        $this->assertCount(1, $sent, "one {$method} to {$path}");

        return collect($sent->first()[0]->data()['variants'] ?? [])
            ->mapWithKeys(fn ($v) => [$v['sku'] => [$v['option_name'], $v['name']]])
            ->all();
    }

    private function pusher(): User
    {
        return $this->userWith(['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group', 'manage_ventacart/product_group']);
    }

    public function test_an_update_from_the_listing_page_gives_the_variations_the_catalogs_names(): void
    {
        [$store, $pid] = $this->productNamedByTheCatalog();
        $this->fakeTheTeeStore($this->storeTee('Color', ['TEE-RED' => 'Red', 'TEE-BLU' => 'Blue']));

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(
            ['TEE-RED' => ['Colour', 'Crimson'], 'TEE-BLU' => ['Colour', 'Navy']],
            $this->sentVariations('PUT', '/api/v1/products/TEE'),
            'each variant keeps its own SKU and takes the catalog type and value'
        );
        Http::assertNotSent(fn ($r) => in_array($r->method(), ['POST', 'DELETE'], true));
        $this->assertNull(VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('last_push_error'));
    }

    public function test_a_group_push_update_gives_the_variations_the_catalogs_names(): void
    {
        [$store, $pid, $group] = $this->productNamedByTheCatalog();
        $this->fakeTheTeeStore($this->storeTee('Color', ['TEE-RED' => 'Red', 'TEE-BLU' => 'Blue']));

        $this->actingAs($this->pusher())
            ->post(route('ext.ventacart.product-groups.push', [$store->id, $group->id]), ['ids' => [$pid]])
            ->assertRedirect();

        $this->assertSame(
            ['TEE-RED' => ['Colour', 'Crimson'], 'TEE-BLU' => ['Colour', 'Navy']],
            $this->sentVariations('PUT', '/api/v1/products/TEE')
        );
        Http::assertNotSent(fn ($r) => in_array($r->method(), ['POST', 'DELETE'], true));
        $this->assertNull(VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('last_push_error'));
    }

    public function test_an_update_whose_names_already_match_sends_them_as_they_are(): void
    {
        [$store, $pid, $group] = $this->productNamedByTheCatalog();
        $this->fakeTheTeeStore($this->storeTee('Colour', ['TEE-RED' => 'Crimson', 'TEE-BLU' => 'Navy']));

        $this->actingAs($this->pusher())
            ->post(route('ext.ventacart.product-groups.push', [$store->id, $group->id]), ['ids' => [$pid]])
            ->assertRedirect();

        $this->assertSame(
            ['TEE-RED' => ['Colour', 'Crimson'], 'TEE-BLU' => ['Colour', 'Navy']],
            $this->sentVariations('PUT', '/api/v1/products/TEE'),
            'the names the store already holds'
        );
        Http::assertNotSent(fn ($r) => in_array($r->method(), ['POST', 'DELETE'], true) || str_contains($r->url(), '/variants'));
        $this->assertNull(VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('last_push_error'));
    }

    public function test_a_create_sends_its_variations_as_it_always_has(): void
    {
        [$store, $pid] = $this->productNamedByTheCatalog(linked: false);
        $this->fakeTheTeeStore(null);

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('status');

        $this->assertSame(
            ['TEE-RED' => ['Option', 'Crimson'], 'TEE-BLU' => ['Option', 'Navy']],
            $this->sentVariations('POST', '/api/v1/products')
        );
        Http::assertNotSent(fn ($r) => $r->method() === 'PUT');
        $this->assertSame(5001, (int) VentaCartProductLink::where('product_id', $pid)->value('ventacart_product_id'));
    }

    public function test_a_product_with_two_variation_types_updates_as_it_always_has(): void
    {
        [$store, $pid] = $this->productNamedByTheCatalog(withSize: true);
        $this->fakeTheTeeStore($this->storeTee('Option', ['TEE-RED' => 'Crimson / M', 'TEE-BLU' => 'Navy / M']));

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push', [$store->id, $pid]))
            ->assertRedirect();

        $sent = $this->sentVariations('PUT', '/api/v1/products/TEE');
        $this->assertSame(['TEE-RED', 'TEE-BLU'], array_keys($sent));
        foreach ($sent as [$type, $value]) {
            $this->assertSame('Option', $type, 'the store holds one type per variant from this endpoint, so none is half renamed');
            $this->assertStringContainsString(' / ', $value);
        }
    }

    public function test_a_refused_rename_is_recorded_on_the_listing(): void
    {
        [$store, $pid] = $this->productNamedByTheCatalog();
        $this->fakeTheTeeStore(
            $this->storeTee('Color', ['TEE-RED' => 'Red', 'TEE-BLU' => 'Blue']),
            422,
            ['message' => 'The variants.0.option_name field must not be greater than 100 characters.']
        );

        $this->actingAs($this->manager())
            ->post(route('ext.ventacart.listings.push', [$store->id, $pid]))
            ->assertRedirect()
            ->assertSessionHas('error');

        $line = (string) VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('last_push_error');
        $this->assertStringContainsString('The variants.0.option_name field must not be greater than 100 characters.', $line);
        $this->assertSame('Push failed: The variants.0.option_name field must not be greater than 100 characters.', $line);
    }
}
