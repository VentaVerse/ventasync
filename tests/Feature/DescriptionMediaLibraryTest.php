<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\DescriptionTemplate;
use App\Models\User;
use App\Support\Catalog\DescriptionHtml;
use Extensions\ventacart\Models\VentaCartListing;
use Extensions\ventacart\Models\VentaCartSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class DescriptionMediaLibraryTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://one.ventacart.test';

    private const LABEL = 'Insert picture from the media library';

    protected function setUp(): void
    {
        parent::setUp();
        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('ventacart');
        $manager->enable('ventacart');
        $this->app->register(\Extensions\ventacart\VentaCartExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
        Http::preventStrayRequests();
    }

    private function user(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Editor ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', $keys)->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function catalogEditor(): User
    {
        return $this->user(['view_catalog/product', 'manage_catalog/product', 'view_catalog/product_image']);
    }

    private function ventaCartManager(bool $library = true): User
    {
        return $this->user(array_merge(
            ['view_ventacart/listing', 'manage_ventacart/listing', 'view_ventacart/product_group',
                'view_ventacart/description_template', 'manage_ventacart/description_template'],
            $library ? ['view_catalog/product_image'] : []
        ));
    }

    private function store(): VentaCartSetting
    {
        return VentaCartSetting::create(['store_name' => 'Gear Depot', 'base_url' => self::BASE, 'api_token' => 't', 'enabled' => true]);
    }

    private function product(string $description): int
    {
        $pfx = (string) config('catalog.prefix');
        $pid = (int) DB::table($pfx . 'product')->insertGetId([
            'model' => 'GP-200', 'sku' => 'GP-200', 'price' => 1000, 'quantity' => 7, 'status' => 1,
            'image' => '', 'weight' => 0, 'length' => 0, 'width' => 0, 'height' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Guitar Pedal 200', 'description' => $description,
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    private function textarea(string $html, string $id): string
    {
        $this->assertSame(1, preg_match('#<textarea[^>]*\bid="' . preg_quote($id, '#') . '"[^>]*>(.*?)</textarea>#s', $html, $m), "no textarea #{$id}");

        return $m[1];
    }

    private function listingEditor(string $description): string
    {
        $store = $this->store();
        $pid = $this->product($description);
        Http::fake([self::BASE . '/*' => Http::response(['error' => 'Product not found'], 404)]);

        $html = $this->actingAs($this->ventaCartManager())->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))
            ->assertOk()->getContent();

        return $this->textarea($html, 'vl-description');
    }

    public function test_the_core_create_and_edit_forms_carry_the_button_and_the_product_picker(): void
    {
        $user = $this->catalogEditor();

        $this->actingAs($user)->get(route('products.create'))->assertOk()
            ->assertSee(self::LABEL, false)
            ->assertSee("'library'", false)
            ->assertSee('id="pimServerModal"', false);

        $pid = $this->product('<p>Words</p>');
        $this->actingAs($user)->get(route('products.edit', $pid))->assertOk()
            ->assertSee(self::LABEL, false)
            ->assertSee("'library'", false)
            ->assertSee('id="pimServerModal"', false);
    }

    public function test_a_listing_page_carries_the_button_and_the_shared_picker(): void
    {
        $store = $this->store();
        $pid = $this->product('<p>Words</p>');
        Http::fake([self::BASE . '/*' => Http::response(['error' => 'Product not found'], 404)]);

        $this->actingAs($this->ventaCartManager())->get(route('ext.ventacart.listings.edit', [$store->id, $pid]))->assertOk()
            ->assertSee(self::LABEL, false)
            ->assertSee('data-ilp-url="' . route('products.images.browse') . '"', false);
    }

    public function test_the_description_templates_page_carries_the_button_and_the_shared_picker(): void
    {
        $store = $this->store();

        $this->actingAs($this->ventaCartManager())->get(route('ext.ventacart.description-templates.index', ['store' => $store->id]))->assertOk()
            ->assertSee(self::LABEL, false)
            ->assertSee('class="x-input wysiwyg"', false)
            ->assertSee('data-ilp-url="' . route('products.images.browse') . '"', false);
    }

    public function test_the_templates_page_offers_no_library_to_someone_who_cannot_browse_it(): void
    {
        $store = $this->store();

        $this->actingAs($this->ventaCartManager(library: false))->get(route('ext.ventacart.description-templates.index', ['store' => $store->id]))->assertOk()
            ->assertDontSee('data-ilp-url=', false);
    }

    public function test_a_listing_editor_shows_the_catalog_descriptions_pictures_in_every_src_form(): void
    {
        $shown = $this->listingEditor(
            '<p>Warm tone.</p>'
            . '<p><img src="https://cdn.example.test/front.jpg" alt=""></p>'
            . '<p><img src="/storage/catalog/demo/side.jpg" alt=""></p>'
            . '<p><img src="catalog/demo/back view.jpg" alt=""></p>'
        );

        $this->assertStringContainsString(e('<img src="https://cdn.example.test/front.jpg" alt="">'), $shown);
        $this->assertStringContainsString(e('<img src="/storage/catalog/demo/side.jpg" alt="">'), $shown);
        $this->assertStringContainsString(e('<img src="' . asset('storage/catalog/demo/back%20view.jpg') . '" alt="">'), $shown, 'a bare library path must become a URL any page can load');
    }

    public function test_a_description_stored_entity_encoded_shows_as_markup_not_as_text(): void
    {
        $shown = $this->listingEditor(e('<p>Warm tone.</p><p><img src="https://cdn.example.test/front.jpg" alt=""></p>'));

        $this->assertStringContainsString(e('<p>Warm tone.</p><p><img src="https://cdn.example.test/front.jpg" alt=""></p>'), $shown);
        $this->assertStringNotContainsString('&amp;lt;', $shown, 'the tags would show as words in the editor');
    }

    public function test_the_editor_never_loads_a_hostile_picture(): void
    {
        $shown = $this->listingEditor('<p><img src="https://cdn.example.test/a.jpg" onerror="alert(1)" alt=""></p>');

        $this->assertStringContainsString(e('<img src="https://cdn.example.test/a.jpg" alt="">'), $shown);
        $this->assertStringNotContainsString('onerror', $shown);
    }

    public function test_the_core_edit_form_shows_the_descriptions_pictures(): void
    {
        $pid = $this->product(e('<p>Words</p><p><img src="catalog/demo/a.jpg" alt=""></p>'));

        $html = $this->actingAs($this->catalogEditor())->get(route('products.edit', $pid))->assertOk()->getContent();

        $this->assertStringContainsString(
            e('<p>Words</p><p><img src="' . asset('storage/catalog/demo/a.jpg') . '" alt=""></p>'),
            $this->textarea($html, 'f-description')
        );
    }

    public function test_a_listing_save_keeps_an_inserted_picture(): void
    {
        $store = $this->store();
        $pid = $this->product('<p>The catalogue description.</p>');
        $img = '<img src="' . asset('storage/catalog/demo/front.jpg') . '" alt="">';

        $this->actingAs($this->ventaCartManager())
            ->put(route('ext.ventacart.listings.update', [$store->id, $pid]), [
                'name' => 'Listing name',
                'description' => '<p>The catalogue description.</p><p>' . $img . '</p>',
                'description_edited' => '1',
            ])
            ->assertRedirect();

        $saved = (string) VentaCartListing::where('ventacart_setting_id', $store->id)->where('product_id', $pid)->value('description');
        $this->assertStringContainsString($img, $saved);
    }

    public function test_a_template_save_keeps_an_inserted_picture(): void
    {
        $store = $this->store();
        $img = '<img src="' . asset('storage/catalog/demo/badge.png') . '" alt="">';

        $this->actingAs($this->ventaCartManager())
            ->post(route('ext.ventacart.description-templates.store', ['store' => $store->id]), ['name' => 'Badge', 'body' => '<p>' . $img . '</p>'])
            ->assertRedirect();

        $this->assertStringContainsString($img, (string) DescriptionTemplate::where('name', 'Badge')->value('body'));
    }

    public function test_the_reader_leaves_real_markup_and_its_words_alone(): void
    {
        $this->assertSame('<p>fits a 5 &lt; 6 mm post</p>', DescriptionHtml::forEditor('<p>fits a 5 &lt; 6 mm post</p>'));
        $this->assertSame('fits a 5 &lt; 6 mm post', DescriptionHtml::decoded('fits a 5 &lt; 6 mm post'));
    }

    public function test_the_reader_refuses_to_climb_out_of_the_web_root_and_writes_nothing(): void
    {
        $climb = DescriptionHtml::forEditor('<p><img src="catalog/../../.env" alt=""></p>');
        $this->assertStringContainsString('src="catalog/../../.env"', $climb);

        $pasted = '<p><img src="data:image/png;base64,iVBORw0KGgo=" alt=""></p>';
        $this->assertStringContainsString('data:image/png;base64,', DescriptionHtml::forEditor($pasted), 'a pasted picture stays pasted until a save');
    }
}
