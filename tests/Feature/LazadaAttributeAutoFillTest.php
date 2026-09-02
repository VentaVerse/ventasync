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

    private function seedProduct(string $name, string $sku, string $description = ''): int
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

        $page = $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $listing->id))->assertOk();

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
        $this->actingAs($this->manager())->get(route('ext.lazada.products.edit', $listing->id))
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
            ->post(route('ext.lazada.products.attributes_save', $listing->id), [
                'attributes' => ['warranty_type' => 'No Warranty'],
            ])
            ->assertRedirect(route('ext.lazada.products.edit', $listing->id))
            ->assertSessionHasNoErrors();

        $this->assertSame('No Warranty', LazadaProductAttribute::query()
            ->where('lazada_product_id', $listing->id)->where('attribute_key', 'warranty_type')->value('value'));
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
