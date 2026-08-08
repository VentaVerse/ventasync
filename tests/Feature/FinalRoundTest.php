<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\LazadaExtension;
use Extensions\lazada\Models\LazadaApiLog;
use Extensions\lazada\Models\LazadaBrand;
use Extensions\lazada\Models\LazadaCategory;
use Extensions\lazada\Models\LazadaCategoryTemplate;
use Extensions\lazada\Models\LazadaProductGroup;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\shopee\Models\ShopeeApiLog;
use Extensions\shopee\Models\ShopeeBrand;
use Extensions\shopee\Models\ShopeeCategory;
use Extensions\shopee\Models\ShopeeCategoryTemplate;
use Extensions\shopee\Models\ShopeeProductGroup;
use Extensions\shopee\Models\ShopeeReturn;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\ShopeeExtension;
use Extensions\tiktok\Models\TikTokCategory;
use Extensions\tiktok\Models\TikTokProductGroup;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\TiktokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Round F, Task 1: the channel deep pages.
 *
 * The last fourteen views inside the three marketplace workspaces leave
 * layouts/app.blade.php and move onto the established anatomies. What this
 * suite is for, in order of how much it would hurt to lose:
 *
 *   1. Permission gating in both directions. Six of these views replaced a
 *      hardcoded `$canManage... = true` with the real check, so a read-tier
 *      operator must now see the page and NOT the write controls, and the
 *      routes behind those controls must still refuse them. A render test
 *      alone would pass on a page that offers a view-tier operator a Delete
 *      button.
 *   2. The save round trip, field by field, against the exact census each
 *      controller validates. These forms were rewritten from scratch; a
 *      dropped or renamed input is invisible to a 200 assertion.
 *   3. XSS. Group names, marketplace category names and attribute labels all
 *      reach these pages, two of them through a JSON data island and one
 *      through an AJAX result list that used to be built with innerHTML.
 *   4. That no page ships an uncompiled <x- component tag, which is the one
 *      class of defect that renders a 200, "contains" the field name, and
 *      submits nothing.
 *   5. That every page renders at all, on the layout it is supposed to be on.
 */
class FinalRoundTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Idempotent extension setup, the same shape ChannelWorkspaceTest,
     * LazadaWorkspaceTest and WorkspaceInteriorsTest use: install() is
     * updateOrCreate(), enable() is a plain update, and $this->app->register()
     * returns the already-registered provider rather than double-booting one.
     *
     * refreshNameLookups() is load-bearing and must run AFTER all three
     * providers have loaded their routes. Registering a provider puts its
     * routes into the RouteCollection, but the separate name-lookup table that
     * route() and Route::has() read is only rebuilt on demand. Without it
     * every route() in these fourteen views throws and the render tests fail
     * for a reason that has nothing to do with the views.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);

        foreach (['shopee', 'lazada', 'tiktok'] as $extension) {
            $manager->install($extension);
            $manager->enable($extension);
        }

        $this->app->register(ShopeeExtension::class);
        $this->app->register(LazadaExtension::class);
        $this->app->register(TiktokExtension::class);

        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    /* =================================================================
       Operators
       ================================================================= */

    private function userWith(array $permissionKeys, string $label): User
    {
        // Unique per call: the render sweeps build the same tier once per
        // URL and user_groups.name is unique.
        $group = UserGroup::create(['name' => $label . ' ' . uniqid()]);

        if ($permissionKeys !== []) {
            $group->permissions()->attach(
                Permission::whereIn('key', $permissionKeys)->pluck('id')->all()
            );
        }

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /** Read tier for one channel and nothing else. */
    private function viewer(string $channel): User
    {
        return $this->userWith(['view_' . $channel], 'Final Round Viewer ' . $channel);
    }

    /** Manage tier, which every write route on these pages demands. */
    private function manager(string $channel): User
    {
        return $this->userWith(['manage_' . $channel], 'Final Round Manager ' . $channel);
    }

    /** Holds no permission for the channel at all. */
    private function outsider(): User
    {
        return $this->userWith([], 'Final Round Outsider');
    }

    /**
     * App\Http\Middleware\EnsurePermission does NOT 403 an HTML caller. It
     * redirects (to the referer, else /dashboard) and flashes the denial into
     * session('error'). Asserting a 403 would be asserting against middleware
     * this application does not have, and a bare assertRedirect() would also
     * pass on a SUCCESSFUL post, which is exactly the failure a gating test
     * exists to catch. Both halves are checked.
     */
    private function assertDenied(\Illuminate\Testing\TestResponse $response): void
    {
        $response->assertRedirect();
        $response->assertSessionHas('error', "You don't have permission to do this action.");
    }

    /* =================================================================
       Fixtures
       ================================================================= */

    private function seedSettings(): void
    {
        ShopeeSetting::create([
            'mode' => 'live', 'partner_id' => 200123, 'partner_key' => encrypt('k'),
            'shop_id' => 900456, 'access_token' => encrypt('t'), 'region' => 'ph',
        ]);
        LazadaSetting::create([
            'app_key' => 'laz-key', 'app_secret' => encrypt('laz-secret'),
            'access_token' => encrypt('laz-token'), 'region' => 'ph',
        ]);
        TikTokSetting::create([
            'app_key' => 'tt-key', 'app_secret' => encrypt('tt-secret'),
            'access_token' => encrypt('tt-token'),
        ]);
    }

    /**
     * One catalogue product the pickers can find and the group pages can list.
     * Deliberately named with an HTML-special character: every assertion that
     * reads it back is also a check that the name survives one layer of
     * escaping and exactly one.
     */
    private function seedProduct(string $name = 'Tents & Awnings', string $sku = 'SKU-FR-1'): int
    {
        $prefix = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $productId = DB::table($prefix . 'product')->insertGetId([
            'model' => 'MDL-FR-1', 'sku' => $sku, 'quantity' => 7,
            'price' => 120.5000, 'status' => 1, 'image' => null,
            'weight' => 1.25, 'length' => 10, 'width' => 8, 'height' => 4,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);

        DB::table($prefix . 'product_description')->insert([
            'product_id' => $productId, 'language_id' => $langId, 'name' => $name,
            'description' => '', 'tag' => '', 'meta_title' => $name,
            'meta_description' => '', 'meta_keyword' => '',
        ]);

        return $productId;
    }

    private function seedShopeeCategory(string $name = 'Camping & Hiking'): ShopeeCategory
    {
        return ShopeeCategory::create([
            'category_id' => 100200, 'parent_id' => 0, 'name' => $name, 'leaf' => 1,
        ]);
    }

    private function seedLazadaCategory(string $name = 'Outdoor & Garden'): LazadaCategory
    {
        return LazadaCategory::create([
            'category_id' => 300400, 'parent_id' => 0, 'name' => $name, 'leaf' => 1,
        ]);
    }

    private function seedTiktokCategory(string $name = 'Sports & Outdoors'): TikTokCategory
    {
        return TikTokCategory::create([
            'id' => '600700', 'parent_id' => '0', 'name' => $name, 'is_leaf' => true,
        ]);
    }

    /* =================================================================
       1. Renders, both tiers, every page family
       ================================================================= */

    /** @return array<string, string> label => url */
    private function readTierUrls(): array
    {
        $this->seedSettings();

        $shopeeGroup = ShopeeProductGroup::create(['name' => 'Shopee Group', 'shopee_category_id' => 100200]);
        $lazadaGroup = LazadaProductGroup::create(['name' => 'Lazada Group', 'lazada_category_id' => 300400]);
        $tiktokGroup = TikTokProductGroup::create(['name' => 'TikTok Group', 'tiktok_category_id' => '600700']);

        $this->seedShopeeCategory();
        $this->seedLazadaCategory();
        $this->seedTiktokCategory();
        $productId = $this->seedProduct();

        return [
            'shopee.group.edit'      => route('ext.shopee.product-groups.edit', $shopeeGroup->id),
            'shopee.group.products'  => route('ext.shopee.product-groups.products', $shopeeGroup->id),
            'shopee.cat.attributes'  => route('ext.shopee.categories.attributes.show', 100200),
            'shopee.products.push'   => route('ext.shopee.products.push', $productId),
            'lazada.group.edit'      => route('ext.lazada.product-groups.edit', $lazadaGroup->id),
            'lazada.group.products'  => route('ext.lazada.product-groups.products', $lazadaGroup->id),
            'lazada.cat.attributes'  => route('ext.lazada.categories.attributes.show', 300400),
            'tiktok.group.edit'      => route('ext.tiktok.product-groups.edit', $tiktokGroup->id),
            'tiktok.group.products'  => route('ext.tiktok.product-groups.products', $tiktokGroup->id),
        ];
    }

    public function test_every_read_tier_page_renders_for_the_read_tier(): void
    {
        $urls = $this->readTierUrls();

        foreach ($urls as $label => $url) {
            $channel = explode('.', $label)[0];
            $response = $this->actingAs($this->viewer($channel))->get($url);
            $response->assertOk();
            $this->assertStringNotContainsString(
                '<x-',
                $response->getContent(),
                $label . ' shipped an uncompiled component tag, which renders as text and submits nothing'
            );
        }
    }

    public function test_every_read_tier_page_renders_for_the_manage_tier(): void
    {
        $urls = $this->readTierUrls();

        foreach ($urls as $label => $url) {
            $channel = explode('.', $label)[0];
            $this->actingAs($this->manager($channel))->get($url)->assertOk();
        }
    }

    public function test_every_read_tier_page_refuses_an_operator_with_no_permission(): void
    {
        $urls = $this->readTierUrls();
        $outsider = $this->outsider();

        foreach ($urls as $label => $url) {
            $this->assertDenied($this->actingAs($outsider)->get($url));
        }
    }

    /** @return array<string, string> label => url */
    private function manageTierUrls(): array
    {
        $this->seedSettings();
        $this->seedShopeeCategory();
        $this->seedLazadaCategory();
        $this->seedTiktokCategory();

        return [
            'shopee.group.create'   => route('ext.shopee.product-groups.create'),
            'shopee.products.add'   => route('ext.shopee.products.add'),
            'lazada.group.create'   => route('ext.lazada.product-groups.create'),
            'lazada.products.create' => route('ext.lazada.products.create'),
            'tiktok.group.create'   => route('ext.tiktok.product-groups.create'),
        ];
    }

    public function test_every_manage_only_page_renders_for_the_manage_tier(): void
    {
        $urls = $this->manageTierUrls();

        foreach ($urls as $label => $url) {
            $channel = explode('.', $label)[0];
            $response = $this->actingAs($this->manager($channel))->get($url);
            $response->assertOk();
            $this->assertStringNotContainsString('<x-', $response->getContent(), $label);
        }
    }

    public function test_every_manage_only_page_refuses_the_read_tier(): void
    {
        $urls = $this->manageTierUrls();

        foreach ($urls as $label => $url) {
            $channel = explode('.', $label)[0];
            $this->assertDenied($this->actingAs($this->viewer($channel))->get($url));
        }
    }

    /* =================================================================
       2. The read tier is shown the page and NOT the controls

       This is the assertion that was missing before the redesign: all six
       of these views hardcoded the manage tier on, so a view_* operator was
       offered every write control on the page and each one refused them.
       ================================================================= */

    public function test_the_read_tier_gets_no_write_controls_on_a_group_form(): void
    {
        $this->seedSettings();
        $this->seedShopeeCategory();
        $group = ShopeeProductGroup::create(['name' => 'Read Tier Group', 'shopee_category_id' => 100200]);

        $response = $this->actingAs($this->viewer('shopee'))
            ->get(route('ext.shopee.product-groups.edit', $group->id));

        $response->assertOk();
        $response->assertSee('Read Tier Group');
        $response->assertSee('Changing it needs the Shopee manage permission');
        // No submit, no picker, no refresh: three separate controls, each of
        // which would have refused this operator.
        $response->assertDontSee('Save product group');
        $response->assertDontSee('data-group-product-picker', false);
        $response->assertDontSee('data-combo-refresh-url', false);
    }

    public function test_the_read_tier_gets_no_write_controls_on_a_group_products_page(): void
    {
        $this->seedSettings();
        $group = ShopeeProductGroup::create(['name' => 'Read Tier Products']);

        $response = $this->actingAs($this->viewer('shopee'))
            ->get(route('ext.shopee.product-groups.products', $group->id));

        $response->assertOk();
        // The bulk bar, the row overflow menus and the add-by-name section
        // are all manage-tier. None of their markup may ship.
        $response->assertDontSee('data-bulk-form', false);
        $response->assertDontSee('data-row-check', false);
        $response->assertDontSee('data-group-manual-add', false);
        $response->assertDontSee('Delete from Shopee');
    }

    public function test_the_read_tier_gets_no_fetch_button_on_a_category_attributes_page(): void
    {
        $this->seedSettings();
        $this->seedShopeeCategory();

        $response = $this->actingAs($this->viewer('shopee'))
            ->get(route('ext.shopee.categories.attributes.show', 100200));

        $response->assertOk();
        $response->assertSee('Camping &amp; Hiking', false);
        $response->assertDontSee('Read them from Shopee');
        $response->assertSee('needs to read them from Shopee first');
    }

    public function test_the_read_tier_gets_no_push_button_on_the_push_form(): void
    {
        $this->seedSettings();
        $this->seedShopeeCategory();
        $productId = $this->seedProduct();

        $response = $this->actingAs($this->viewer('shopee'))
            ->get(route('ext.shopee.products.push', $productId));

        $response->assertOk();
        $response->assertSee('needs the Shopee manage permission');
        // The breadcrumb leaf is also the words "Push to Shopee", and the
        // account menu's logout is a submit, so this names the rendered
        // button itself rather than either.
        $response->assertDontSee('>Push to Shopee</button>', false);
    }

    /* =================================================================
       3. One save round trip per channel

       Each posts the exact field census its controller validates, and each
       reads the row back rather than trusting the redirect.
       ================================================================= */

    public function test_a_shopee_group_saves_every_field_it_posts(): void
    {
        $this->seedSettings();
        $this->seedShopeeCategory();
        $productId = $this->seedProduct();

        $response = $this->actingAs($this->manager('shopee'))
            ->post(route('ext.shopee.product-groups.store'), [
                'name' => 'Pedals & Pedalboards',
                'shopee_category_id' => 100200,
                'shopee_brand_id' => 0,
                'logistic_ids' => [8001, 8002],
                'markup_percent' => '7.5',
                'markup_fixed' => '25.00',
                'product_ids' => [$productId],
                'attributes' => ['material' => 'Aluminium'],
            ]);

        $response->assertSessionHasNoErrors();

        $group = ShopeeProductGroup::query()->firstWhere('name', 'Pedals & Pedalboards');
        $this->assertNotNull($group, 'the group form posted a name the controller did not store');
        $this->assertSame(100200, (int) $group->shopee_category_id);
        $this->assertSame([8001, 8002], array_map('intval', (array) $group->logistic_ids));
        $this->assertEquals(7.5, (float) $group->markup_percent);
        $this->assertEquals(25.0, (float) $group->markup_fixed);
        $this->assertSame(
            [$productId],
            $group->groupProducts()->pluck('product_id')->map('intval')->all(),
            'product_ids[] is the only thing the two-pane picker submits and it did not arrive'
        );
    }

    public function test_a_lazada_group_saves_every_field_it_posts(): void
    {
        $this->seedSettings();
        $this->seedLazadaCategory();
        $productId = $this->seedProduct();
        LazadaBrand::create(['brand_id' => 5150, 'name' => 'Valeton', 'region' => 'ph']);

        $response = $this->actingAs($this->manager('lazada'))
            ->post(route('ext.lazada.product-groups.store'), [
                'name' => 'Multi FX & Loopers',
                'lazada_category_id' => 300400,
                'brand_id' => 5150,
                'no_brand' => 0,
                'markup_percent' => '3.25',
                'markup_fixed' => '10.00',
                'product_ids' => [$productId],
            ]);

        $response->assertSessionHasNoErrors();

        $group = LazadaProductGroup::query()->firstWhere('name', 'Multi FX & Loopers');
        $this->assertNotNull($group);
        $this->assertSame(300400, (int) $group->lazada_category_id);
        $this->assertSame(5150, (int) $group->brand_id);
        $this->assertEquals(3.25, (float) $group->markup_percent);
        $this->assertSame([$productId], $group->groupProducts()->pluck('product_id')->map('intval')->all());
    }

    public function test_the_lazada_no_brand_flag_still_reaches_the_controller(): void
    {
        $this->seedSettings();
        $this->seedLazadaCategory();

        $this->actingAs($this->manager('lazada'))
            ->post(route('ext.lazada.product-groups.store'), [
                'name' => 'No Brand Group',
                'lazada_category_id' => 300400,
                'brand_id' => '',
                'no_brand' => 1,
            ])->assertSessionHasNoErrors();

        $group = LazadaProductGroup::query()->firstWhere('name', 'No Brand Group');
        $this->assertNotNull($group);
        $this->assertSame(
            'No Brand',
            $group->brand_name_override,
            'the no_brand hidden input is the whole wire for "there is no brand" and it did not arrive'
        );
    }

    public function test_a_tiktok_group_saves_every_field_it_posts(): void
    {
        $this->seedSettings();
        $this->seedTiktokCategory();
        $productId = $this->seedProduct();

        $response = $this->actingAs($this->manager('tiktok'))
            ->post(route('ext.tiktok.product-groups.store'), [
                'name' => 'Amps & Cabs',
                'tiktok_category_id' => '600700',
                'markup_percent' => '12',
                'markup_fixed' => '5.50',
                'product_ids' => [$productId],
            ]);

        $response->assertSessionHasNoErrors();

        $group = TikTokProductGroup::query()->firstWhere('name', 'Amps & Cabs');
        $this->assertNotNull($group);
        // TikTok's id is a string with an exists: rule, not an integer.
        $this->assertSame('600700', (string) $group->tiktok_category_id);
        $this->assertEquals(12.0, (float) $group->markup_percent);
        $this->assertSame([$productId], $group->groupProducts()->pluck('product_id')->map('intval')->all());
    }

    public function test_a_group_form_without_its_required_fields_is_refused_and_nothing_is_stored(): void
    {
        $this->seedSettings();

        $this->actingAs($this->manager('shopee'))
            ->post(route('ext.shopee.product-groups.store'), ['name' => ''])
            ->assertSessionHasErrors(['name', 'shopee_category_id', 'logistic_ids']);

        $this->assertSame(0, ShopeeProductGroup::query()->count());
    }

    /* =================================================================
       4. The picker's AJAX round trip

       The two-pane picker's only server call. The response shape is what
       resources/js/pages/group-form.js maps into rows, so the keys matter
       as much as the status.
       ================================================================= */

    public function test_the_product_picker_search_returns_the_shape_the_picker_reads(): void
    {
        $this->seedSettings();
        $productId = $this->seedProduct('Tents & Awnings', 'SKU-PICKER');

        $response = $this->actingAs($this->manager('shopee'))
            ->getJson(route('ext.shopee.product-groups.searchProducts') . '?q=' . urlencode('Tents'));

        $response->assertOk();
        $response->assertJsonPath('items.0.product_id', $productId);
        $response->assertJsonPath('items.0.name', 'Tents & Awnings');
        $response->assertJsonStructure(['items' => [['product_id', 'name', 'sku', 'model', 'quantity']]]);
    }

    public function test_the_product_picker_search_is_open_to_the_read_tier_and_closed_to_an_outsider(): void
    {
        $this->seedSettings();
        $this->seedProduct('Tents & Awnings', 'SKU-PICKER');

        $url = route('ext.shopee.product-groups.searchProducts') . '?q=Tents';

        // Read tier: the edit form renders for them, so the search behind it
        // must answer or the page is a dead control.
        $this->actingAs($this->viewer('shopee'))->getJson($url)->assertOk();
        $this->assertDenied($this->actingAs($this->outsider())->get($url));
    }

    public function test_every_channel_picker_search_answers(): void
    {
        $this->seedSettings();
        $this->seedProduct('Tents & Awnings', 'SKU-PICKER');

        foreach (['shopee', 'lazada', 'tiktok'] as $channel) {
            $this->actingAs($this->manager($channel))
                ->getJson(route('ext.' . $channel . '.product-groups.searchProducts') . '?q=Tents')
                ->assertOk()
                ->assertJsonPath('items.0.name', 'Tents & Awnings');
        }
    }

    /* =================================================================
       5. XSS

       Three separate paths, because they escape in three different places:
       a group name in ordinary Blade, a marketplace category name inside a
       JSON data island, and an attribute label inside the partial that is
       ALSO the body of an AJAX response.
       ================================================================= */

    private const PAYLOAD = '<script>alert("xss")</script>';

    public function test_a_group_name_carrying_markup_is_escaped_everywhere_it_appears(): void
    {
        $this->seedSettings();
        $this->seedShopeeCategory();
        $group = ShopeeProductGroup::create([
            'name' => self::PAYLOAD, 'shopee_category_id' => 100200,
        ]);

        foreach ([
            route('ext.shopee.product-groups.edit', $group->id),
            route('ext.shopee.product-groups.products', $group->id),
        ] as $url) {
            $response = $this->actingAs($this->manager('shopee'))->get($url);
            $response->assertOk();
            $this->assertStringNotContainsString(self::PAYLOAD, $response->getContent(), $url);
            // Positive control: the name IS on the page, escaped. Without
            // this the assertion above would also pass on a blank page.
            $response->assertSee(self::PAYLOAD);
        }
    }

    public function test_a_marketplace_category_name_carrying_markup_cannot_close_the_data_island(): void
    {
        $this->seedSettings();
        // TikTok's picker ships its whole category list to the page as a JSON
        // island, so a name containing a closing script tag is the exact
        // attack this island's JSON_HEX_TAG flag exists to stop.
        TikTokCategory::create([
            'id' => '600700', 'parent_id' => '0',
            'name' => 'Outdoor </script><script>alert(1)</script>', 'is_leaf' => true,
        ]);
        $group = TikTokProductGroup::create(['name' => 'Island Group', 'tiktok_category_id' => '600700']);

        $response = $this->actingAs($this->manager('tiktok'))
            ->get(route('ext.tiktok.product-groups.edit', $group->id));

        $response->assertOk();
        $content = $response->getContent();
        $this->assertStringNotContainsString('</script><script>alert(1)', $content);
        // JSON_HEX_TAG encodes < and > as < / >, so the name is
        // still in the island, still readable by the picker, and inert.
        $this->assertStringContainsString('</script>', $content);
    }

    public function test_an_attribute_label_carrying_markup_is_escaped_in_the_partial_the_ajax_endpoint_returns(): void
    {
        $this->seedSettings();
        $this->seedShopeeCategory();

        ShopeeCategoryTemplate::create([
            'category_id' => 100200,
            'region' => 'ph',
            'attributes' => [[
                'attribute_id' => 1,
                'original_attribute_name' => self::PAYLOAD,
                'is_mandatory' => true,
                'input_type' => 'TEXT_FILED',
                'attribute_value_list' => [],
            ]],
            'fetched_at' => now(),
        ]);

        $group = ShopeeProductGroup::create(['name' => 'Attr Group', 'shopee_category_id' => 100200]);

        $response = $this->actingAs($this->manager('shopee'))
            ->get(route('ext.shopee.product-groups.edit', $group->id));

        $response->assertOk();
        $this->assertStringNotContainsString(self::PAYLOAD, $response->getContent());
        $response->assertSee(self::PAYLOAD);
    }

    public function test_a_category_name_carrying_markup_is_escaped_on_the_attributes_page(): void
    {
        $this->seedSettings();
        $this->seedShopeeCategory(self::PAYLOAD);

        $response = $this->actingAs($this->manager('shopee'))
            ->get(route('ext.shopee.categories.attributes.show', 100200));

        $response->assertOk();
        $this->assertStringNotContainsString(self::PAYLOAD, $response->getContent());
        $response->assertSee(self::PAYLOAD);
    }

    /* =================================================================
       6. The two OAuth callbacks

       Both are pages a human reads, not bare redirects, and both had to
       leave layouts/app.blade.php before Round F Task 4 deletes it. They
       land on different layouts for a reason the tests pin, because getting
       it wrong 404s the one page that has to work when the connection has
       gone wrong.
       ================================================================= */

    public function test_the_lazada_callback_renders_without_a_session(): void
    {
        // No actingAs: lazada.callback deliberately carries no auth
        // middleware so Lazada's answer is not lost behind a login redirect.
        // It is on layouts.standalone precisely because layouts.channel would
        // abort(404) on a route outside the ext.* namespace.
        $response = $this->get(route('lazada.callback'));

        $response->assertOk();
        $response->assertSee('Lazada authorisation');
        $response->assertSee('x-standalone', false);
        $this->assertStringNotContainsString('<x-', $response->getContent());
    }

    public function test_the_lazada_callback_says_plainly_that_nothing_was_exchanged(): void
    {
        $response = $this->get(route('lazada.callback'));

        $response->assertOk();
        $response->assertSee('Nothing was exchanged');
    }

    /* =================================================================
       7. House rules the whole round is held to
       ================================================================= */

    /** @return list<string> */
    private function convertedViewPaths(): array
    {
        return [
            'extensions/shopee/views/product-groups/form.blade.php',
            'extensions/shopee/views/product-groups/products.blade.php',
            'extensions/shopee/views/product-groups/_attributes.blade.php',
            'extensions/shopee/views/categories/attributes.blade.php',
            'extensions/shopee/views/products/add.blade.php',
            'extensions/shopee/views/products/push.blade.php',
            'extensions/shopee/views/orders/return-detail.blade.php',
            'extensions/shopee/views/callback.blade.php',
            'extensions/lazada/views/product-groups/form.blade.php',
            'extensions/lazada/views/product-groups/products.blade.php',
            'extensions/lazada/views/product-groups/_attributes.blade.php',
            'extensions/lazada/views/products/form.blade.php',
            'extensions/lazada/views/categories/attributes.blade.php',
            'extensions/lazada/views/callback.blade.php',
            'extensions/tiktok/views/product-groups/form.blade.php',
            'extensions/tiktok/views/product-groups/products.blade.php',
            'resources/views/layouts/standalone.blade.php',
        ];
    }

    public function test_no_converted_view_still_extends_the_legacy_layout(): void
    {
        foreach ($this->convertedViewPaths() as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertStringNotContainsString(
                "@extends('layouts.app')",
                $source,
                $path . ' still extends the layout Round F Task 4 deletes'
            );
        }
    }

    public function test_no_converted_view_puts_css_or_behaviour_in_the_blade(): void
    {
        foreach ($this->convertedViewPaths() as $path) {
            // Blade comments are stripped first: several of these files
            // explain in prose that they used to carry an inline <script> or
            // a style attribute, and a sweep that reads its own changelog as
            // markup is a sweep that can never pass.
            $source = preg_replace('/\{\{--.*?--\}\}/s', '', file_get_contents(base_path($path)));

            $this->assertDoesNotMatchRegularExpression(
                '/\sstyle\s*=\s*"/i',
                $source,
                $path . ' carries a style attribute; CSS belongs in resources/css'
            );
            $this->assertStringNotContainsString(
                '<style',
                $source,
                $path . ' carries a <style> block'
            );
            // A <script type="application/json"> data island is data, not
            // behaviour, and is the sanctioned way to hand a payload to a
            // module. Anything else is a script in a Blade.
            $this->assertDoesNotMatchRegularExpression(
                '/<script(?![^>]*type="application\/json")/i',
                $source,
                $path . ' carries executable script; behaviour belongs in resources/js'
            );
            $this->assertDoesNotMatchRegularExpression(
                '/\son(click|change|input|focus|blur|submit|error|load)\s*=/i',
                $source,
                $path . ' carries an inline event handler'
            );
        }
    }

    public function test_no_converted_view_uses_a_blade_directive_as_a_component_attribute(): void
    {
        // A directive in the attribute position stops the component compiling
        // and the tag ships as literal text: the page renders, the response
        // still "contains" the field name, and nothing submits.
        foreach ($this->convertedViewPaths() as $path) {
            $this->assertDoesNotMatchRegularExpression(
                '/<x-[a-z0-9.\-]+[^>]*@(disabled|checked|selected|readonly|required|class|style)\(/s',
                file_get_contents(base_path($path)),
                $path
            );
        }
    }

    public function test_no_converted_view_contains_an_em_dash(): void
    {
        foreach ($this->convertedViewPaths() as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertStringNotContainsString("\u{2014}", $source, $path . ' contains an em dash');
            $this->assertStringNotContainsString('&mdash;', $source, $path . ' contains an em dash entity');
            $this->assertStringNotContainsString('&#8212;', $source, $path . ' contains an em dash entity');
        }
    }

    public function test_no_converted_view_hardcodes_a_currency_symbol(): void
    {
        foreach ($this->convertedViewPaths() as $path) {
            $source = file_get_contents(base_path($path));
            $this->assertDoesNotMatchRegularExpression(
                '/[\x{20B1}\x{FFE5}\x{20AC}\x{00A3}]|\$\s*\{\{|defaultSymbol\(\)/u',
                $source,
                $path . ' resolves a currency symbol itself; use App\Support\Money or <x-money>'
            );
        }
    }

    public function test_the_shopee_add_page_builds_its_push_url_from_the_routing_table(): void
    {
        $this->seedSettings();
        $this->seedShopeeCategory();

        $response = $this->actingAs($this->manager('shopee'))->get(route('ext.shopee.products.add'));

        $response->assertOk();
        // The previous version hardcoded '/integrations/shopee/products' and
        // would have broken silently on any change to the address plan.
        $response->assertSee(route('ext.shopee.products.push', ['productId' => '__PRODUCT_ID__']), false);
    }

    /* =================================================================
       8. The return detail page
       ================================================================= */

    public function test_the_return_detail_page_renders_on_the_shared_record_anatomy(): void
    {
        $this->seedSettings();

        ShopeeReturn::create([
            'return_sn' => 'RT12345',
            'region' => 'ph',
            'order_sn' => 'OR98765',
            'status' => 'ACCEPTED',
            'reason' => 'ITEM_WRONG',
            'reason_text' => 'They sent the wrong colour',
            'refund_amount' => 1250.00,
            'currency' => 'PHP',
            'needs_logistics' => true,
            'raw' => [
                'item' => [[
                    'name' => 'Tents & Awnings', 'item_sku' => 'SKU-FR-1',
                    'amount' => 2, 'refund_amount' => 1250.00, 'item_price' => 625.00,
                    'item_id' => 11, 'model_id' => 22,
                ]],
                'user' => ['username' => 'buyer_one'],
                'create_time' => 1754000000,
            ],
        ]);

        $response = $this->actingAs($this->userWith(['view_shopee_orders'], 'Return Reader'))
            ->get(route('ext.shopee.orders.return_detail', 'RT12345'));

        $response->assertOk();
        $response->assertSee('RT12345');
        // The status label and tone come from ChannelStatusTone now, not from
        // a raw marketplace constant and a hand-rolled colour class.
        $response->assertSee('Accepted');
        $response->assertDontSee('ACCEPTED');
        $response->assertDontSee('ret-badge', false);
        $response->assertSee('od-page', false);
        // Money goes through App\Support\Money, never a symbol in the view.
        $response->assertSee('1,250.00');
        $this->assertStringNotContainsString('<x-', $response->getContent());
    }

    public function test_the_return_detail_page_refuses_an_operator_with_no_orders_permission(): void
    {
        $this->seedSettings();
        ShopeeReturn::create([
            'return_sn' => 'RT99999', 'region' => 'ph', 'order_sn' => 'OR11111',
            'status' => 'REQUESTED',
            'refund_amount' => 10.00, 'raw' => [],
        ]);

        $this->assertDenied(
            $this->actingAs($this->outsider())->get(route('ext.shopee.orders.return_detail', 'RT99999'))
        );
    }

    /* =================================================================
       9. Write routes still refuse the read tier

       The pages no longer offer these controls, but the page is not the
       gate. Each of these is a route the read tier can reach the RENDER of
       and must not be able to act on.
       ================================================================= */

    public function test_every_write_route_behind_these_pages_refuses_the_read_tier(): void
    {
        $this->seedSettings();
        $this->seedShopeeCategory();
        $group = ShopeeProductGroup::create(['name' => 'Gate Group', 'shopee_category_id' => 100200]);
        $viewer = $this->viewer('shopee');

        $posts = [
            route('ext.shopee.product-groups.store'),
            route('ext.shopee.product-groups.push', $group->id),
            route('ext.shopee.product-groups.pushPrices', $group->id),
            route('ext.shopee.product-groups.pushStock', $group->id),
            route('ext.shopee.product-groups.massRemove', $group->id),
            route('ext.shopee.product-groups.deleteFromShopee', $group->id),
            route('ext.shopee.product-groups.addProducts', $group->id),
            route('ext.shopee.product-groups.refreshCategories'),
            route('ext.shopee.product-groups.fetchAttributes'),
            route('ext.shopee.categories.attributes.fetch', 100200),
        ];

        foreach ($posts as $url) {
            $this->assertDenied($this->actingAs($viewer)->post($url, ['ids' => [1]]));
        }

        $this->assertDenied(
            $this->actingAs($viewer)->put(route('ext.shopee.product-groups.update', $group->id), ['name' => 'Renamed'])
        );

        $this->assertSame('Gate Group', $group->fresh()->name, 'the read tier renamed a group');
    }

    public function test_the_lazada_and_tiktok_write_routes_refuse_their_own_read_tiers(): void
    {
        $this->seedSettings();
        $this->seedLazadaCategory();
        $this->seedTiktokCategory();

        $lazadaGroup = LazadaProductGroup::create(['name' => 'Laz Gate', 'lazada_category_id' => 300400]);
        $tiktokGroup = TikTokProductGroup::create(['name' => 'TT Gate', 'tiktok_category_id' => '600700']);

        $this->assertDenied(
            $this->actingAs($this->viewer('lazada'))
                ->post(route('ext.lazada.product-groups.deleteFromLazada', $lazadaGroup->id), ['ids' => [1]])
        );
        $this->assertDenied(
            $this->actingAs($this->viewer('tiktok'))
                ->post(route('ext.tiktok.product-groups.deleteFromTikTok', $tiktokGroup->id), ['ids' => [1]])
        );

        $this->assertSame('Laz Gate', $lazadaGroup->fresh()->name);
        $this->assertSame('TT Gate', $tiktokGroup->fresh()->name);
    }
}
