<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\opencart\Models\OpenCartProductLink;
use Extensions\opencart\Models\OpenCartSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OpenCartItemImportTest extends TestCase
{
    use RefreshDatabase;

    private const BASE = 'https://oc-one.test';

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('opencart');
        $manager->enable('opencart');
        $this->app->register(\Extensions\opencart\OpencartExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');
    }

    private function store(): OpenCartSetting
    {
        return OpenCartSetting::query()->create([
            'store_name' => 'One', 'enabled' => true,
            'base_url' => self::BASE, 'api_key' => 'k',
        ]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'OpenCart import managers']);
        $group->permissions()->attach(
            Permission::whereIn('key', ['view_opencart/import', 'manage_opencart/import'])->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function seedProduct(string $sku): int
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');
        $pid = DB::table($pfx . 'product')->insertGetId([
            'model' => $sku, 'sku' => $sku, 'quantity' => 5, 'price' => 100,
            'status' => 1, 'image' => '', 'weight' => 0.5, 'length' => 10, 'width' => 10, 'height' => 5,
            'manufacturer_id' => 0,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($pfx . 'product_description')->insert([
            'product_id' => $pid, 'language_id' => $langId,
            'name' => 'Existing ' . $sku, 'description' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $pid;
    }

    private function fakeProducts(array $rows): void
    {
        Http::fake([
            self::BASE . '/index.php*' => Http::response([
                'success' => true,
                'data' => $rows,
                'pagination' => ['page' => 1, 'limit' => 100, 'total' => count($rows), 'total_pages' => 1],
            ]),
        ]);
    }

    public function test_fetch_renders_the_live_diff_and_saves_none_of_it(): void
    {
        $setting = $this->store();
        $this->seedProduct('KNOWN-1');

        $this->fakeProducts([
            ['product_id' => 11, 'model' => 'KNOWN-1', 'sku' => '', 'name' => 'Known product', 'price' => '100', 'quantity' => 5],
            ['product_id' => 12, 'model' => 'M-12', 'sku' => 'NEW-1', 'name' => 'Caf&eacute; grinder', 'price' => '250', 'quantity' => 3,
                'image' => 'catalog/grinder.jpg',
                'option_values' => [['option_name' => 'Size', 'option_value_name' => 'S']]],
        ]);

        $html = $this->actingAs($this->manager())
            ->get(route('ext.opencart.products.import', ['store' => $setting->id, 'fetch' => 1]))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Café grinder', $html, 'OpenCart entities decode before display');
        $this->assertStringNotContainsString('Known product', $html,
            'a model the catalog knows counts as its SKU - OpenCart habit - and is never listed');
        $this->assertStringContainsString('name="refs[]" value="12"', $html);
        $this->assertStringContainsString(self::BASE . '/image/catalog/grinder.jpg', $html,
            'store-relative image paths resolve against the store base');
    }

    public function test_import_converts_one_option_set_to_an_axis_with_absolute_prices(): void
    {
        $setting = $this->store();

        $this->fakeProducts([[
            'product_id' => 12, 'model' => 'IMP-1', 'sku' => 'IMP-1',
            'name' => 'Importable thing', 'description' => '&lt;p&gt;Store words.&lt;/p&gt;',
            'price' => '100.0000', 'quantity' => 6, 'weight' => '0.40',
            'length' => '10', 'width' => '10', 'height' => '5',
            'option_values' => [
                ['option_name' => 'Size', 'option_value_name' => 'Small', 'sku' => 'IMP-1-S', 'quantity' => 4, 'price' => '5.0000', 'price_prefix' => '-'],
                ['option_name' => 'Size', 'option_value_name' => 'Large', 'sku' => 'IMP-1-L', 'quantity' => 2, 'price' => '10.0000', 'price_prefix' => '+'],
            ],
        ]]);

        $this->actingAs($this->manager())
            ->post(route('ext.opencart.products.import_one', $setting->id), ['ref' => 12])
            ->assertRedirect();

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->where('sku', 'IMP-1')->first();
        $this->assertNotNull($product, 'the import must create the catalog product');
        $this->assertSame(1, (int) $product->status, 'already selling on the store: it arrives enabled');

        $values = DB::table($pfx . 'product_option_value')
            ->where('product_id', $product->product_id)->get();
        $this->assertEqualsCanonicalizing(['IMP-1-S', 'IMP-1-L'], $values->pluck('sku')->all(),
            'one OpenCart option set becomes a real variation axis');

        $absolute = $values->map(fn ($v) => (float) $v->absolute_price)->all();
        $this->assertEqualsCanonicalizing([95.0, 110.0], $absolute,
            'relative OpenCart prices convert to absolute variation prices');

        $link = OpenCartProductLink::query()->where('product_id', $product->product_id)->first();
        $this->assertNotNull($link);
        $this->assertSame(12, (int) $link->oc_product_id);
    }

    public function test_multiple_independent_option_sets_import_flat_and_say_so(): void
    {
        $setting = $this->store();

        $this->fakeProducts([[
            'product_id' => 13, 'model' => 'IMP-3', 'sku' => 'IMP-3',
            'name' => 'Multi-option thing', 'price' => '100', 'quantity' => 6,
            'option_values' => [
                ['option_name' => 'Size', 'option_value_name' => 'Small', 'price' => '0', 'price_prefix' => '+'],
                ['option_name' => 'Color', 'option_value_name' => 'Red', 'price' => '0', 'price_prefix' => '+'],
            ],
        ]]);

        $this->actingAs($this->manager())
            ->post(route('ext.opencart.products.import_one', $setting->id), ['ref' => 13])
            ->assertRedirect();

        $pfx = (string) config('catalog.prefix');
        $product = DB::table($pfx . 'product')->where('sku', 'IMP-3')->first();
        $this->assertNotNull($product);
        $this->assertSame(0, DB::table($pfx . 'product_option_value')->where('product_id', $product->product_id)->count(),
            'independent option sets cannot honestly become variations - the import stays flat');
        $this->assertStringContainsString('independent option sets', session('status'),
            'the flat import says so instead of quietly dropping structure');
    }

    public function test_a_sku_the_catalog_holds_rolls_the_import_back(): void
    {
        $setting = $this->store();
        $this->seedProduct('CLASH-V');

        $this->fakeProducts([[
            'product_id' => 14, 'model' => 'IMP-2', 'sku' => 'IMP-2',
            'name' => 'Clashing thing', 'price' => '100', 'quantity' => 6,
            'option_values' => [
                ['option_name' => 'Size', 'option_value_name' => 'Small', 'sku' => 'CLASH-V', 'quantity' => 1, 'price' => '0', 'price_prefix' => '+'],
            ],
        ]]);

        $r = $this->actingAs($this->manager())
            ->post(route('ext.opencart.products.import_one', $setting->id), ['ref' => 14]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Not imported', session('error'));
        $pfx = (string) config('catalog.prefix');
        $this->assertNull(DB::table($pfx . 'product')->where('sku', 'IMP-2')->first(),
            'a refused import must leave nothing half-created');
    }

    public function test_the_page_starts_blank_and_reads_nothing(): void
    {
        $setting = $this->store();
        Http::fake();

        $html = $this->actingAs($this->manager())
            ->get(route('ext.opencart.products.import', $setting->id))
            ->assertOk()->getContent();

        $this->assertStringContainsString('Nothing fetched yet', $html);
        $this->assertStringContainsString('Fetch from One', $html);
        $this->assertStringContainsString('your Master Catalog', $html,
            'the copy names the destination in plain words');
        Http::assertNothingSent();
    }
}
