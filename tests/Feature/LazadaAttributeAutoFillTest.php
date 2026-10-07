<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaProduct;
use Extensions\lazada\Models\LazadaProductAttribute;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaListingReadiness;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LazadaAttributeAutoFillTest extends TestCase
{
    use RefreshDatabase;

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
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada autofill managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['manage_lazada/product', 'view_lazada/product'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $name, string $sku, string $description = 'A real description.'): int
    {
        $pfx = (string) config('catalog.prefix');
        $productId = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => 'catalog/x.png', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => $name, 'description' => $description,
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $productId;
    }

    private function template(int $categoryId = 7013): void
    {
        LazadaCategoryTemplate::query()->create([
            'region' => 'ph', 'primary_category_id' => $categoryId, 'fetched_at' => now(),
            'template_body' => ['data' => ['attributes' => [
                ['name' => 'name', 'is_mandatory' => 1, 'input_type' => 'text'],
                ['name' => 'description', 'is_mandatory' => 1, 'input_type' => 'richText'],
                ['name' => 'short_description', 'is_mandatory' => 0, 'input_type' => 'richText'],
                ['name' => 'brand', 'is_mandatory' => 1, 'input_type' => 'options', 'options' => [['name' => 'No Brand']]],
                ['name' => 'warranty_type', 'is_mandatory' => 1, 'input_type' => 'options',
                 'options' => [['name' => 'No Warranty'], ['name' => 'Local supplier warranty']]],
                ['name' => 'color_family', 'is_mandatory' => 0, 'input_type' => 'options', 'is_sale_prop' => 1,
                 'options' => [['name' => 'Black'], ['name' => 'Red']]],
                ['name' => '__images__', 'is_mandatory' => 0, 'input_type' => 'img'],
            ]]],
        ]);
    }

    public function test_readiness_asks_only_what_the_catalogue_cannot_answer(): void
    {
        $this->template();
        $listing = LazadaProduct::query()->create([
            'product_id' => $this->seedProduct('Valeton GP-5', 'GP5'),
            'primary_category_id' => 7013,
        ]);

        $result = app(LazadaListingReadiness::class)->forListings(collect([$listing]))[$listing->id];

        $this->assertFalse($result['ready']);
        $this->assertCount(1, $result['missing'], implode(' | ', $result['missing']));
        $this->assertStringContainsString('warranty_type', $result['missing'][0]);
        $this->assertStringNotContainsString('name', strtolower($result['missing'][0]) === 'name' ? $result['missing'][0] : '');
        $this->assertStringNotContainsString('brand', implode(' ', $result['missing']));

        LazadaProductAttribute::query()->create([
            'lazada_product_id' => $listing->id, 'attribute_key' => 'warranty_type', 'value' => 'No Warranty',
        ]);
        $again = app(LazadaListingReadiness::class)->forListings(collect([$listing->fresh()]))[$listing->id];
        $this->assertTrue($again['ready'], implode(' | ', $again['missing'] ?? []));
    }

    public function test_the_sheet_shows_only_the_categorys_own_questions(): void
    {
        $this->template();
        $listing = LazadaProduct::query()->create([
            'product_id' => $this->seedProduct('Valeton GP-5', 'GP5'),
            'primary_category_id' => 7013,
        ]);

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk();

        $page->assertSee('name="attributes[warranty_type]"', false);
        $page->assertDontSee('name="attributes[name]"', false);
        $page->assertDontSee('name="attributes[description]"', false);
        $page->assertDontSee('name="attributes[color_family]"', false);
        $page->assertDontSee('__images__');
        $page->assertDontSee('come from the catalog product');
    }

    public function test_a_custom_value_someone_already_typed_stays_visible_and_wins(): void
    {
        $this->template();
        $listing = LazadaProduct::query()->create([
            'product_id' => $this->seedProduct('Catalogue name', 'GP5'),
            'primary_category_id' => 7013,
        ]);
        LazadaProductAttribute::query()->create([
            'lazada_product_id' => $listing->id, 'attribute_key' => 'name', 'value' => 'Hand-written Lazada title',
        ]);

        $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $listing->product_id))
            ->assertOk()
            ->assertSee('name="attributes[name]"', false)
            ->assertSee('Hand-written Lazada title');
    }

    public function test_saving_the_sheet_no_longer_demands_what_fills_itself(): void
    {
        $this->template();
        $listing = LazadaProduct::query()->create([
            'product_id' => $this->seedProduct('Valeton GP-5', 'GP5'),
            'primary_category_id' => 7013,
        ]);

        $this->actingAs($this->manager())
            ->put(route('ext.lazada.products.update', $listing->product_id), [
                'primary_category_id' => 7013,
                'attributes' => ['warranty_type' => 'No Warranty'],
            ])
            ->assertRedirect(route('ext.lazada.products.edit', $listing->product_id))
            ->assertSessionHasNoErrors();

        $this->assertSame('No Warranty', LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)->where('attribute_key', 'warranty_type')->value('value'));
    }

    private function fullerTemplate(int $categoryId = 7014): void
    {
        LazadaCategoryTemplate::query()->create([
            'region' => 'ph', 'primary_category_id' => $categoryId, 'fetched_at' => now(),
            'template_body' => ['data' => ['attributes' => [
                ['id' => 1, 'name' => 'name', 'is_mandatory' => 1, 'input_type' => 'text'],
                ['id' => 2, 'name' => 'brand', 'is_mandatory' => 1, 'input_type' => 'options', 'options' => [['name' => 'No Brand']]],
                ['id' => 3, 'name' => 'warranty_type', 'is_mandatory' => 1, 'input_type' => 'options', 'options' => [['name' => 'No Warranty'], ['name' => 'Local supplier warranty']]],
                ['id' => 4, 'name' => 'Musical_Instrument_Type', 'label' => 'Musical Instrument Type', 'is_mandatory' => 1, 'input_type' => 'options', 'options' => [['name' => 'Guitar'], ['name' => 'Bass']]],
                ['id' => 5, 'name' => 'price', 'is_mandatory' => 1, 'input_type' => 'numeric'],
                ['id' => 6, 'name' => 'package_weight', 'is_mandatory' => 1, 'input_type' => 'numeric'],
                ['id' => 7, 'name' => 'SellerSku', 'is_mandatory' => 1, 'input_type' => 'text'],
                ['id' => 8, 'name' => 'special_from_date', 'is_mandatory' => 0, 'input_type' => 'date'],
                ['id' => 100005514, 'name' => 'material', 'label' => 'Material', 'is_mandatory' => 0, 'input_type' => 'text'],
            ]]],
        ]);
    }

    public function test_the_sheet_never_asks_the_sku_level_facts_the_payload_fills(): void
    {
        $this->fullerTemplate();
        $listing = LazadaProduct::query()->create(['product_id' => $this->seedProduct('Valeton GP-5', 'GP5'), 'primary_category_id' => 7014]);

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk();
        $page->assertSee('name="attributes[Musical_Instrument_Type]"', false)->assertSee('name="attributes[warranty_type]"', false);
        foreach (['price', 'package_weight', 'SellerSku', 'special_from_date'] as $key) {
            $page->assertDontSee('name="attributes[' . $key . ']"', false);
        }
    }

    public function test_saving_one_answer_is_kept_even_while_another_required_attribute_is_blank_and_the_rest_is_named(): void
    {
        $this->fullerTemplate();
        $listing = LazadaProduct::query()->create(['product_id' => $this->seedProduct('Valeton GP-5', 'GP5'), 'primary_category_id' => 7014]);

        $this->actingAs($this->manager())
            ->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'attributes' => ['Musical_Instrument_Type' => 'Guitar']])
            ->assertRedirect(route('ext.lazada.products.edit', $listing->product_id))
            ->assertSessionHasNoErrors();
        $this->assertSame('Guitar', LazadaProductAttribute::query()->where('lazada_product_id', $listing->id)->where('attribute_key', 'Musical_Instrument_Type')->value('value'));
        $status = (string) session('status');
        $this->assertStringContainsString('Listing saved.', $status);
        $this->assertStringContainsString('Still needed before a push', $status);
        $this->assertStringContainsString('warranty_type', $status);
        $this->assertStringNotContainsString('price', $status, 'SKU-level facts are never named as missing answers');
    }

    public function test_save_and_push_keeps_the_answers_and_goes_on_to_the_upload(): void
    {
        $this->fullerTemplate();
        $listing = LazadaProduct::query()->create(['product_id' => $this->seedProduct('Valeton GP-5', 'GP5'), 'primary_category_id' => 7014]);
        \Illuminate\Support\Facades\Http::fake([
            '*/images/migrate*' => \Illuminate\Support\Facades\Http::response(['code' => '0', 'data' => ['images' => [['url' => 'https://ph-live.slatic.net/x.jpg', 'hash_code' => 'h']]]], 200),
            '*/image/migrate*' => \Illuminate\Support\Facades\Http::response(['code' => '0', 'data' => ['image' => ['url' => 'https://ph-live.slatic.net/x.jpg', 'hash_code' => 'h']]], 200),
            '*' => \Illuminate\Support\Facades\Http::response(['code' => '0', 'data' => ['item_id' => 4242, 'sku_list' => []]], 200),
        ]);

        $r = $this->actingAs($this->manager())
            ->from(route('ext.lazada.products.edit', $listing->product_id))
            ->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'attributes' => ['Musical_Instrument_Type' => 'Guitar', 'warranty_type' => 'No Warranty'], 'push' => 1])
            ->assertRedirect();
        $this->assertSame('Guitar', LazadaProductAttribute::query()->where('lazada_product_id', $listing->id)->where('attribute_key', 'Musical_Instrument_Type')->value('value'));
        $this->assertTrue(session()->has('status') || session()->has('error'), "the upload's outcome is what comes back, not the plain 'Listing saved.'");
        $this->assertStringNotContainsString('Listing saved.', (string) session('status'));
        \Illuminate\Support\Facades\Http::assertSent(fn ($request) => str_contains($request->url(), '/product/create'));
    }

    public function test_the_page_carries_one_form_with_the_sheet_inside_it_and_the_template_read_beside_the_category(): void
    {
        $this->template();
        $listing = LazadaProduct::query()->create(['product_id' => $this->seedProduct('Valeton GP-5', 'GP5'), 'primary_category_id' => 7013]);

        $user = $this->manager();
        $html = $this->actingAs($user)->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk()->getContent();
        $this->assertStringNotContainsString('Save attributes', $html);
        $this->assertStringNotContainsString('id="lazada-attributes"', $html);
        $this->assertStringNotContainsString('The payload', $html);
        $this->assertStringNotContainsString('Send a sample push', $html);
        $this->assertStringContainsString('Save and push to Lazada', $html);
        $this->assertStringContainsString('Re-read the template', $html);
        $this->assertStringContainsString(route('ext.lazada.products.attributes_fetch', $listing->product_id), $html);
        $form = strpos($html, 'id="lazada-listing"');
        $sheet = strpos($html, 'name="attributes[warranty_type]"');
        $formEnd = strpos($html, '</form>', $form);
        $this->assertNotFalse($sheet);
        $this->assertTrue($form < $sheet && $sheet < $formEnd, 'the attribute rows submit with the listing form');
        $this->assertStringContainsString('data-slow-action="push"', $html, 'only the push shows the talking-to-Lazada overlay');

        $listing->update(['lazada_item_id' => '777']);
        $this->actingAs($user)->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk()
            ->assertSee('Save and push the update');
    }

    public function test_picking_a_category_fetches_its_sheet_and_a_reread_asks_lazada(): void
    {
        $this->template(7013);
        $listing = LazadaProduct::query()->create(['product_id' => $this->seedProduct('Valeton GP-5', 'GP5')]);
        LazadaProductAttribute::query()->create(['lazada_product_id' => $listing->id, 'attribute_key' => 'warranty_type', 'value' => 'Local supplier warranty']);
        $user = $this->manager();
        \Illuminate\Support\Facades\Http::fake([
            '*' => \Illuminate\Support\Facades\Http::response(['code' => '0', 'data' => ['attributes' => [
                ['name' => 'warranty_type', 'is_mandatory' => 1, 'input_type' => 'options', 'options' => [['name' => 'No Warranty']]],
                ['name' => 'material', 'is_mandatory' => 0, 'input_type' => 'text'],
            ]]], 200),
        ]);

        $r = $this->actingAs($user)->postJson(route('ext.lazada.products.attributes_fetch', $listing->product_id), ['primary_category_id' => 7013])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('name="attributes[warranty_type]"', $r->json('html'));
        $this->assertStringContainsString('value="Local supplier warranty" selected', $r->json('html'));
        \Illuminate\Support\Facades\Http::assertNothingSent();

        $r = $this->actingAs($user)->postJson(route('ext.lazada.products.attributes_fetch', $listing->product_id), ['primary_category_id' => 7099])
            ->assertOk()->assertJson(['ok' => true, 'count' => 2]);
        $this->assertStringContainsString('name="attributes[material]"', $r->json('html'));
        $this->assertSame(1, LazadaCategoryTemplate::query()->where('primary_category_id', 7099)->count());
        \Illuminate\Support\Facades\Http::assertSentCount(1);

        $this->actingAs($user)->postJson(route('ext.lazada.products.attributes_fetch', $listing->product_id), ['primary_category_id' => 7013, 'reread' => 1])->assertOk();
        \Illuminate\Support\Facades\Http::assertSentCount(2);
        $this->assertArrayHasKey('material', collect(LazadaCategoryTemplate::query()->where('primary_category_id', 7013)->value('template_body')['data']['attributes'])->keyBy('name')->all(), 'the re-read replaced the template on file');

        $this->assertNull($listing->fresh()->primary_category_id);
    }

    public function test_a_blank_required_row_says_so_and_a_refusal_lands_on_the_row_it_names(): void
    {
        $this->fullerTemplate();
        $listing = LazadaProduct::query()->create(['product_id' => $this->seedProduct('Valeton GP-5', 'GP5'), 'primary_category_id' => 7014]);
        $user = $this->manager();

        $html = $this->actingAs($user)->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk()->getContent();
        $this->assertStringContainsString('Required before a push.', $html);

        \Illuminate\Support\Facades\Http::fake([
            '*/images/migrate*' => \Illuminate\Support\Facades\Http::response(['code' => '0', 'data' => ['images' => [['url' => 'https://ph-live.slatic.net/x.jpg', 'hash_code' => 'h']]]], 200),
            '*/image/migrate*' => \Illuminate\Support\Facades\Http::response(['code' => '0', 'data' => ['image' => ['url' => 'https://ph-live.slatic.net/x.jpg', 'hash_code' => 'h']]], 200),
            '*' => \Illuminate\Support\Facades\Http::response(['code' => '4108', 'type' => 'ISP', 'message' => 'E500: Create product failed', 'detail' => [
                ['field' => 'p-100005514', 'message' => 'C004: cannot be blank.', 'code' => 'CHK_BASIC_REQUIRED'],
                ['field' => '', 'message' => 'Musical_Instrument_Type is mandatory', 'code' => 'CHK_BASIC_REQUIRED'],
            ]], 200),
        ]);

        $r = $this->actingAs($user)
            ->from(route('ext.lazada.products.edit', $listing->product_id))
            ->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'attributes' => ['warranty_type' => 'No Warranty'], 'push' => 1])
            ->assertRedirect(route('ext.lazada.products.edit', $listing->product_id));
        $r->assertSessionHasErrors(['attributes.Musical_Instrument_Type']);
        $r->assertSessionMissing('status');
        $this->assertStringContainsString('Musical_Instrument_Type', (string) session('error'));
        \Illuminate\Support\Facades\Http::assertNothingSent();
        $html = $this->actingAs($user)->withSession(['errors' => session('errors')])->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk()->getContent();
        $this->assertStringContainsString('Musical_Instrument_Type is mandatory', $html);

        $r = $this->actingAs($user)
            ->from(route('ext.lazada.products.edit', $listing->product_id))
            ->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'attributes' => ['warranty_type' => 'No Warranty', 'Musical_Instrument_Type' => 'Guitar'], 'push' => 1])
            ->assertRedirect(route('ext.lazada.products.edit', $listing->product_id));
        $r->assertSessionHasErrors(['attributes.material', 'attributes.Musical_Instrument_Type']);
        $this->assertStringContainsString('cannot be blank', session('errors')->first('attributes.material'));
        $html = $this->actingAs($user)->withSession(['errors' => session('errors')])->get(route('ext.lazada.products.edit', $listing->product_id))->assertOk()->getContent();
        $this->assertStringContainsString('Lazada: C004: cannot be blank.', $html);
        $this->assertStringContainsString('Lazada: Musical_Instrument_Type is mandatory', $html);
    }

    public function test_save_and_push_returns_to_the_listings_search_it_came_from(): void
    {
        $this->fullerTemplate();
        $listing = LazadaProduct::query()->create(['product_id' => $this->seedProduct('Valeton GP-5', 'GP5'), 'primary_category_id' => 7014]);
        \Illuminate\Support\Facades\Http::fake([
            '*/images/migrate*' => \Illuminate\Support\Facades\Http::response(['code' => '0', 'data' => ['images' => [['url' => 'https://ph-live.slatic.net/x.jpg', 'hash_code' => 'h']]]], 200),
            '*/image/migrate*' => \Illuminate\Support\Facades\Http::response(['code' => '0', 'data' => ['image' => ['url' => 'https://ph-live.slatic.net/x.jpg', 'hash_code' => 'h']]], 200),
            '*' => \Illuminate\Support\Facades\Http::response(['code' => '0', 'data' => ['item_id' => 4242, 'sku_list' => []]], 200),
        ]);
        $user = $this->manager();
        $search = '/channels/lazada/' . LazadaSetting::query()->value('id') . '/products?q=valeton&status=all';

        $index = $this->actingAs($user)->get(route('ext.lazada.products.index', ['q' => 'valeton', 'status' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('back=' . urlencode($search), $index);
        $page = $this->actingAs($user)->get(route('ext.lazada.products.edit', ['productId' => $listing->product_id, 'back' => $search]))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . e($search) . '"', $page, 'Cancel returns to the search');
        $this->assertStringContainsString('name="back" value="' . e($search) . '"', $page);

        $this->actingAs($user)->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'attributes' => ['warranty_type' => 'No Warranty', 'Musical_Instrument_Type' => 'Guitar'], 'push' => 1, 'back' => $search])
            ->assertRedirect($search);
        $r = $this->actingAs($user)->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'attributes' => ['warranty_type' => 'No Warranty', 'Musical_Instrument_Type' => 'Guitar'], 'push' => 1, 'back' => 'https://evil.example/x']);
        $r->assertRedirect(route('ext.lazada.products.index'));

        $this->actingAs($user)->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'back' => $search])
            ->assertRedirect(route('ext.lazada.products.edit', ['productId' => $listing->product_id, 'back' => $search]));
    }

    public function test_a_refused_template_read_is_a_sentence(): void
    {
        $listing = LazadaProduct::query()->create(['product_id' => $this->seedProduct('Valeton GP-5', 'GP5')]);
        \Illuminate\Support\Facades\Http::fake(['*' => \Illuminate\Support\Facades\Http::response(['code' => 'IllegalAccessToken', 'message' => 'The access token is invalid'], 200)]);

        $r = $this->actingAs($this->manager())->postJson(route('ext.lazada.products.attributes_fetch', $listing->product_id), ['primary_category_id' => 7100])->assertStatus(422);
        $this->assertStringContainsString('The access token is invalid', $r->json('message'));
        $this->assertStringNotContainsString('{', $r->json('message'));
        $this->assertSame(0, LazadaCategoryTemplate::query()->where('primary_category_id', 7100)->count());
    }

    public function test_the_push_payload_carries_the_catalogue_answers_the_operator_never_typed(): void
    {
        $this->template();
        $listing = LazadaProduct::query()->create([
            'product_id' => $this->seedProduct('Valeton GP-5 Mini Pedal', 'GP5', '<p>Tiny pedal, big sound</p>'),
            'primary_category_id' => 7013,
        ]);
        LazadaProductAttribute::query()->create([
            'lazada_product_id' => $listing->id, 'attribute_key' => 'warranty_type', 'value' => 'No Warranty',
        ]);

        [$payload] = app(\Extensions\lazada\Services\Lazada\LazadaPushPayload::class)
            ->buildLazadaProductCreatePayload($listing->fresh());

        $attrs = $payload['Request']['Product']['Attributes'];
        $this->assertSame('Valeton GP-5 Mini Pedal', $attrs['name'], 'name fills from the catalogue before the gate');
        $this->assertSame('<p>Tiny pedal, big sound</p>', $attrs['description']);
        $this->assertSame('No Warranty', $attrs['warranty_type'], "the operator's answer rides beside them");
        $this->assertArrayHasKey('short_description', $attrs, 'optional basics fill too rather than going blank');
    }
}
