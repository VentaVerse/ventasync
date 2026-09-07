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

/**
 * #175, the behaviour half: what the catalogue can answer, it answers.
 *
 * Lazada's category template lists name and description in the same
 * mandatory list as Colour Family, because its API carries everything
 * inside Attributes. The old design (CedCommerce's shape, carried through
 * the first AI build) read that as "the operator must answer every row".
 * Shopee and TikTok fill the obvious fields from the catalogue and ask
 * only the category's own questions; these tests pin Lazada doing the
 * same: the sheet shows only real questions, blanks the catalogue can
 * fill never block a save or make a listing read "not ready", and brand
 * is demanded once, defaulting to No Brand exactly as Shopee's does.
 */
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

    /** The category's sheet as Lazada answers it: basics, a real question, a sale prop, plumbing. */
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

        // Name and description fill from the catalogue; brand defaults to No
        // Brand as the payload builder sends it. The one honest gap is the
        // category's own question.
        $this->assertFalse($result['ready']);
        $this->assertCount(1, $result['missing'], implode(' | ', $result['missing']));
        $this->assertStringContainsString('warranty_type', $result['missing'][0]);
        $this->assertStringNotContainsString('name', strtolower($result['missing'][0]) === 'name' ? $result['missing'][0] : '');
        $this->assertStringNotContainsString('brand', implode(' ', $result['missing']));

        // Answer the real question and the listing is ready, with name,
        // description and brand never having been asked.
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
        // The variation axis is asked in Variant Mapping, not here, and the
        // plumbing key was never a question at all.
        $page->assertDontSee('name="attributes[color_family]"', false);
        $page->assertDontSee('__images__');
        // The note tells the operator where the basics come from.
        $page->assertSee('come from the catalog product');
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

        // Visible, so an override never applies silently.
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

        // Only the category's own question is posted; name and description
        // stay blank and that is fine, because push fills them. Before #175
        // this came back "name is mandatory. description is mandatory."
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

    /** A category with two questions of its own plus the SKU-level facts Lazada lists as attributes. */
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

    /** the owner, 2026-09-06: price, the parcel, SellerSku and the promotion dates are not questions; the payload fills them. */
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

    /** the owner, 2026-09-06: choosing one dropdown and saving used to be refused because another required attribute was still blank. */
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

    /** Save and push (the owner, 2026-09-06): one press keeps the category, the answers and sends the listing. */
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

    /**
     * The one form (the owner, 2026-09-06: "remove the Save Attribute button.
     * Everything should be in Save and Push"): the sheet sits inside the
     * listing form, the template read sits beside the category, and the
     * payload section is gone. A linked listing offers the update.
     */
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
        // The sheet's rows are inside the listing form: the form opens before them and closes after them.
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

    /** Picking a category fetches its sheet: a template on file answers at once, a re-read asks Lazada, a refusal is a sentence. */
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

        // On file: no call to Lazada, the saved answer is pre-selected.
        $r = $this->actingAs($user)->postJson(route('ext.lazada.products.attributes_fetch', $listing->product_id), ['primary_category_id' => 7013])
            ->assertOk()->assertJson(['ok' => true]);
        $this->assertStringContainsString('name="attributes[warranty_type]"', $r->json('html'));
        $this->assertStringContainsString('value="Local supplier warranty" selected', $r->json('html'));
        \Illuminate\Support\Facades\Http::assertNothingSent();

        // A category never read asks Lazada and files the template.
        $r = $this->actingAs($user)->postJson(route('ext.lazada.products.attributes_fetch', $listing->product_id), ['primary_category_id' => 7099])
            ->assertOk()->assertJson(['ok' => true, 'count' => 2]);
        $this->assertStringContainsString('name="attributes[material]"', $r->json('html'));
        $this->assertSame(1, LazadaCategoryTemplate::query()->where('primary_category_id', 7099)->count());
        \Illuminate\Support\Facades\Http::assertSentCount(1);

        // Re-read: asks Lazada even though the template is on file.
        $this->actingAs($user)->postJson(route('ext.lazada.products.attributes_fetch', $listing->product_id), ['primary_category_id' => 7013, 'reread' => 1])->assertOk();
        \Illuminate\Support\Facades\Http::assertSentCount(2);
        $this->assertArrayHasKey('material', collect(LazadaCategoryTemplate::query()->where('primary_category_id', 7013)->value('template_body')['data']['attributes'])->keyBy('name')->all(), 'the re-read replaced the template on file');

        // The listing itself is untouched by a fetch: the form saves the category.
        $this->assertNull($listing->fresh()->primary_category_id);
    }

    /** the owner, 2026-09-06: the row-level "required" warning came back; a refusal lands on the row Lazada names, by id or inside the sentence. */
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

        // Our own refusal first: a required answer is still blank, so nothing
        // is sent; the header is red and the row is named.
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

        // Every answer given: Lazada itself refuses, by field id and inside a sentence; both land on their rows.
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

    /** the owner, 2026-09-06: after Save and push, back to the Listings page, with the search the operator came from. */
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

        // The Listings page hands its own URL to the listing page, which keeps it on Cancel and on the form.
        $index = $this->actingAs($user)->get(route('ext.lazada.products.index', ['q' => 'valeton', 'status' => 'all']))->assertOk()->getContent();
        $this->assertStringContainsString('back=' . urlencode($search), $index);
        $page = $this->actingAs($user)->get(route('ext.lazada.products.edit', ['productId' => $listing->product_id, 'back' => $search]))->assertOk()->getContent();
        $this->assertStringContainsString('href="' . e($search) . '"', $page, 'Cancel returns to the search');
        $this->assertStringContainsString('name="back" value="' . e($search) . '"', $page);

        // Accepted push: back to that search. A forged host is refused and the plain Listings page stands in.
        $this->actingAs($user)->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'attributes' => ['warranty_type' => 'No Warranty', 'Musical_Instrument_Type' => 'Guitar'], 'push' => 1, 'back' => $search])
            ->assertRedirect($search);
        $r = $this->actingAs($user)->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'attributes' => ['warranty_type' => 'No Warranty', 'Musical_Instrument_Type' => 'Guitar'], 'push' => 1, 'back' => 'https://evil.example/x']);
        $r->assertRedirect(route('ext.lazada.products.index'));

        // A plain save stays on the page and keeps the way back.
        $this->actingAs($user)->put(route('ext.lazada.products.update', $listing->product_id), ['primary_category_id' => 7014, 'back' => $search])
            ->assertRedirect(route('ext.lazada.products.edit', ['productId' => $listing->product_id, 'back' => $search]));
    }

    /** A refused template read comes back as Lazada's own sentence, never a payload, and files nothing. */
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
