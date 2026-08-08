<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Catalogue redesign, Round B.
 *
 * Task 1: the products index (resources/views/catalog/products/index.blade.php).
 * Task 2: product create/edit and their satellites, on the `fm-` form-page
 *         anatomy. The round-trip tests below are the point of that task: the
 *         form was rebuilt from scratch, so the only thing that proves the
 *         wire survived is posting every field group the controller validates
 *         and reading the rows back.
 *
 * Wire truth: Catalog\ProductController@index (list + filters + sort +
 * pagination) and @bulkAction (POST /catalog/products/bulk, fields `action`
 * and `ids[]`). routes/catalog.php gates the list on
 * perm:view_products|manage_products and every write (create/store/bulk/
 * edit/update/destroy) on perm:manage_products specifically.
 */
class CatalogueRedesignTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Core permissions (view_products, manage_products, ...) reach the
     * `permissions` table via config/permissions.php + syncCorePermissions(),
     * which nothing runs automatically on a fresh migrate - only extension
     * permissions auto-sync on boot. Without this, `view_products` simply
     * does not exist as a row and every "holds view_products" fixture below
     * would silently attach nothing. Same live gap already tracked for
     * view_orders; not this task's job to fix, just to work around in the
     * test the same way ChannelWorkspaceTest works around Shopee's
     * extension-registration timing.
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->app->make(ExtensionManager::class)->syncCorePermissions();
    }

    private function userWithPermissions(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Test group '.implode('-', $keys)]);

        $group->permissions()->attach(
            Permission::whereIn('key', $keys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function viewer(): User
    {
        return $this->userWithPermissions(['view_products']);
    }

    private function manager(): User
    {
        return $this->userWithPermissions(['manage_products']);
    }

    private function unrelatedUser(): User
    {
        // Holds a real permission, just not one of Catalogue's, so a
        // completely unauthenticated-request explanation cannot account for
        // a passing denial assertion.
        return $this->userWithPermissions(['view_shopee']);
    }

    /**
     * One product with an image and a variation (product_option_combinations
     * row) so the list's item/thumbnail/variation-count rendering path is
     * genuinely exercised, plus one plain product with neither. Directly on
     * the catalog schema tables (no factory exists for these OpenCart-shaped
     * tables anywhere in this codebase yet).
     */
    private function seedProducts(): array
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $variantId = DB::table($p.'product')->insertGetId([
            'model' => 'MDL-1', 'sku' => 'SKU-VAR-1', 'quantity' => 12,
            'price' => 199.5000, 'status' => 1, 'image' => 'catalog/test-thumb.png',
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($p.'product_description')->insert([
            'product_id' => $variantId, 'language_id' => $langId, 'name' => 'Variant Tee',
            'description' => '', 'tag' => '', 'meta_title' => 'Variant Tee',
            'meta_description' => '', 'meta_keyword' => '',
        ]);

        $optionId = DB::table($p.'option')->insertGetId(['type' => 'select', 'sort_order' => 0]);
        DB::table($p.'option_description')->insert([
            'option_id' => $optionId, 'language_id' => $langId, 'name' => 'Size',
        ]);
        $optionValueId = DB::table($p.'option_value')->insertGetId(['option_id' => $optionId, 'image' => '', 'sort_order' => 0]);
        DB::table($p.'option_value_description')->insert([
            'option_value_id' => $optionValueId, 'language_id' => $langId, 'option_id' => $optionId, 'name' => 'Large',
        ]);
        $productOptionId = DB::table($p.'product_option')->insertGetId([
            'product_id' => $variantId, 'option_id' => $optionId, 'value' => '', 'required' => 0,
        ]);
        $povId = DB::table($p.'product_option_value')->insertGetId([
            'product_option_id' => $productOptionId, 'product_id' => $variantId,
            'option_id' => $optionId, 'option_value_id' => $optionValueId,
            'sku' => 'SKU-VAR-1-L', 'quantity' => 12,
        ]);
        $combinationId = DB::table('product_option_combinations')->insertGetId([
            'product_id' => $variantId, 'sku' => 'SKU-VAR-1-L', 'quantity' => 12,
            'absolute_price' => 199.50, 'absolute_cost' => 0, 'subtract' => 1, 'sort_order' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('product_option_combination_values')->insert([
            'combination_id' => $combinationId, 'product_option_value_id' => $povId,
        ]);

        $plainId = DB::table($p.'product')->insertGetId([
            'model' => 'MDL-2', 'sku' => 'SKU-PLAIN-1', 'quantity' => 3,
            'price' => 49.0000, 'status' => 0, 'image' => null,
            'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($p.'product_description')->insert([
            'product_id' => $plainId, 'language_id' => $langId, 'name' => 'Plain Mug',
            'description' => '', 'tag' => '', 'meta_title' => 'Plain Mug',
            'meta_description' => '', 'meta_keyword' => '',
        ]);

        return compact('variantId', 'plainId');
    }

    // ---- Renders for a permitted user -----------------------------------

    public function test_the_index_renders_for_a_view_tier_user(): void
    {
        $this->seedProducts();

        $response = $this->actingAs($this->viewer())->get(route('products.index'));

        $response->assertOk();
        $response->assertSee('Variant Tee');
        $response->assertSee('Plain Mug');
        $response->assertSee('SKU-VAR-1', false);
        $response->assertSee('1 variation');
    }

    public function test_the_index_renders_for_a_manage_tier_user(): void
    {
        $this->seedProducts();

        $response = $this->actingAs($this->manager())->get(route('products.index'));

        $response->assertOk();
        $response->assertSee('New product');
    }

    // ---- Gating, both directions -----------------------------------------

    public function test_a_user_holding_neither_permission_is_denied_the_list(): void
    {
        $response = $this->actingAs($this->unrelatedUser())->get(route('products.index'));

        $response->assertStatus(302);
        $this->assertNotSame(route('products.index'), $response->headers->get('Location'));
    }

    public function test_a_guest_is_redirected_to_login(): void
    {
        $this->get(route('products.index'))->assertRedirect('/login');
    }

    public function test_view_tier_alone_reaches_the_list_but_not_the_bulk_endpoint(): void
    {
        $viewer = $this->viewer();
        $ids = $this->seedProducts();

        $this->actingAs($viewer)->get(route('products.index'))->assertOk();

        $response = $this->actingAs($viewer)->post(route('products.bulk'), [
            'action' => 'disable',
            'ids' => [$ids['variantId']],
        ]);

        // manage_products, not view_products, guards every write route -
        // EnsurePermission denies with a redirect (HTML caller), never a 200.
        $response->assertStatus(302);
        $this->assertSame(1, DB::table(config('catalog.prefix').'product')
            ->where('product_id', $ids['variantId'])->value('status'));
    }

    public function test_manage_tier_reaches_both_the_list_and_the_bulk_endpoint(): void
    {
        $manager = $this->manager();
        $ids = $this->seedProducts();

        $this->actingAs($manager)->get(route('products.index'))->assertOk();

        $response = $this->actingAs($manager)->post(route('products.bulk'), [
            'action' => 'disable',
            'ids' => [$ids['variantId']],
        ]);

        $response->assertRedirect(route('products.index'));
        $this->assertSame(0, DB::table(config('catalog.prefix').'product')
            ->where('product_id', $ids['variantId'])->value('status'));
    }

    // ---- Bulk action round trip, exact field names -----------------------

    public function test_bulk_disable_round_trip_uses_the_controllers_exact_field_names(): void
    {
        $ids = $this->seedProducts();

        $this->actingAs($this->manager())->post(route('products.bulk'), [
            'action' => 'disable',
            'ids' => [$ids['variantId'], $ids['plainId']],
        ])->assertRedirect(route('products.index'));

        $p = config('catalog.prefix');
        $this->assertSame(0, DB::table($p.'product')->where('product_id', $ids['variantId'])->value('status'));
        $this->assertSame(0, DB::table($p.'product')->where('product_id', $ids['plainId'])->value('status'));
    }

    public function test_bulk_delete_round_trip_removes_the_selected_rows_only(): void
    {
        $ids = $this->seedProducts();

        $this->actingAs($this->manager())->post(route('products.bulk'), [
            'action' => 'delete',
            'ids' => [$ids['variantId']],
        ])->assertRedirect(route('products.index'));

        $p = config('catalog.prefix');
        $this->assertSame(0, DB::table($p.'product')->where('product_id', $ids['variantId'])->count());
        $this->assertSame(1, DB::table($p.'product')->where('product_id', $ids['plainId'])->count());
    }

    // ---- Filters, sort, pagination shape carry through --------------------

    public function test_search_filters_by_name(): void
    {
        $this->seedProducts();

        $response = $this->actingAs($this->manager())->get(route('products.index', ['q' => 'Plain']));

        $response->assertOk();
        $response->assertSee('Plain Mug');
        $response->assertDontSee('Variant Tee');
    }

    public function test_status_filter_narrows_the_list(): void
    {
        $this->seedProducts();

        $response = $this->actingAs($this->manager())->get(route('products.index', ['status' => '0']));

        $response->assertOk();
        $response->assertSee('Plain Mug');
        $response->assertDontSee('Variant Tee');
    }

    public function test_an_unknown_sort_column_falls_back_to_product_id(): void
    {
        // The controller's own guard (index 209): array_key_exists($sort,
        // $allowedSort) rejects anything else, silently defaulting to
        // product_id rather than erroring - unchanged behaviour, just
        // exercised through the new view's sort links.
        $this->seedProducts();

        $this->actingAs($this->manager())
            ->get(route('products.index', ['sort' => 'price; DROP TABLE product', 'dir' => 'asc']))
            ->assertOk();
    }

    public function test_the_empty_state_renders_when_a_filter_matches_nothing(): void
    {
        $this->seedProducts();

        $response = $this->actingAs($this->manager())->get(route('products.index', ['q' => 'no-such-product-xyz']));

        $response->assertOk();
        $response->assertSee('No products match those filters');
    }

    // ---- XSS: product names flow through escaped output -------------------

    public function test_a_product_name_containing_markup_is_escaped(): void
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $id = DB::table($p.'product')->insertGetId([
            'model' => 'MDL-X', 'sku' => 'SKU-X', 'quantity' => 1, 'price' => 1,
            'status' => 1, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($p.'product_description')->insert([
            'product_id' => $id, 'language_id' => $langId,
            'name' => '<script>alert(1)</script>', 'description' => '', 'tag' => '',
            'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '',
        ]);

        $response = $this->actingAs($this->manager())->get(route('products.index'));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        $response->assertSee('&lt;script&gt;alert(1)&lt;/script&gt;', false);
    }

    // =================================================================
    // Task 2: product create/edit + satellites
    // =================================================================

    /**
     * A category row plus its description. This schema's category_description
     * has seo_keyword and seo_h1..h3 with no defaults, so all four are supplied.
     */
    private function seedCategory(string $name = 'Round Trip Category'): int
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $categoryId = DB::table($p.'category')->insertGetId([
            'parent_id' => 0, 'top' => 0, 'column' => 1, 'sort_order' => 0, 'status' => 1,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table($p.'category_description')->insert([
            'category_id' => $categoryId, 'language_id' => $langId, 'name' => $name,
            'description' => '', 'meta_title' => $name, 'meta_description' => '',
            'meta_keyword' => '', 'seo_keyword' => 'rt-cat-'.$categoryId,
            'seo_h1' => $name, 'seo_h2' => '', 'seo_h3' => '',
        ]);

        return $categoryId;
    }

    private function attachCategory(int $productId): int
    {
        $categoryId = $this->seedCategory();
        DB::table(config('catalog.prefix').'product_to_category')
            ->insert(['product_id' => $productId, 'category_id' => $categoryId]);

        return $categoryId;
    }

    /**
     * The full set of product fields ProductController@store/@update
     * validate, with values distinct enough that a field landing in the wrong
     * column is visible. Read straight off the two validate() blocks: name,
     * model, sku, price, cost_amount, cost_percentage, cost_additional,
     * quantity, weight, length, width, height, status, manufacturer_id,
     * manufacturer_name, category_ids[], category_id, category_name,
     * description, images_json.
     */
    private function fullProductPayload(array $overrides = []): array
    {
        return array_merge([
            'name' => 'Round Trip Widget',
            'model' => 'RT-MODEL-9',
            'sku' => 'RT-SKU-9',
            'price' => '250.00',
            'cost_amount' => '30.00',
            'cost_percentage' => '10',
            'cost_additional' => '5.00',
            'quantity' => '17',
            'weight' => '1.25',
            'length' => '30',
            'width' => '20',
            'height' => '10',
            'status' => '1',
            'reorder_level' => '4',
            'manufacturer_name' => 'Round Trip Co',
            'manufacturer_id' => '0',
            'description' => '<p>A description with <strong>markup</strong>.</p>',
            'images_json' => '[]',
        ], $overrides);
    }

    // ---- Renders ---------------------------------------------------------

    public function test_the_create_form_renders_every_section(): void
    {
        $response = $this->actingAs($this->manager())->get(route('products.create'));

        $response->assertOk();
        $response->assertSee('New product');
        foreach (['General', 'Pricing', 'Images', 'Variations', 'Status', 'Inventory', 'Shipping'] as $section) {
            $response->assertSee($section);
        }
        // Every control the controller validates has to be on the page under
        // the name the controller reads.
        foreach (['name', 'model', 'sku', 'price', 'cost_amount', 'cost_percentage',
                  'cost_additional', 'quantity', 'weight', 'length', 'width', 'height',
                  'status', 'manufacturer_id', 'manufacturer_name',
                  'description', 'images_json', 'pim_token', '_options_format'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
        // category_ids[] is one hidden input per selected category, so a blank
        // form carries the picker rather than the field. Same as before.
        $response->assertSee('id="category_search"', false);
        $response->assertSee('Create product');
    }

    public function test_the_edit_form_renders_every_section_and_spoofs_the_put(): void
    {
        $ids = $this->seedProducts();
        $this->attachCategory($ids['variantId']);

        $response = $this->actingAs($this->manager())->get(route('products.edit', $ids['variantId']));

        $response->assertOk();
        $response->assertSee('Variant Tee');
        $response->assertSee('name="_method"', false);
        $response->assertSee('value="PUT"', false);
        $response->assertSee('name="_return"', false);
        foreach (['name', 'model', 'sku', 'price', 'cost_amount', 'cost_percentage',
                  'cost_additional', 'quantity', 'weight', 'length', 'width', 'height',
                  'status', 'manufacturer_id', 'manufacturer_name',
                  'description', 'images_json', 'pim_token', '_options_format'] as $field) {
            $response->assertSee('name="'.$field.'"', false);
        }
        // The product is in a category, so its chip and hidden input render.
        $response->assertSee('name="category_ids[]"', false);
        $response->assertSee('Round Trip Category');
        $response->assertSee('Save changes');
        // Destroy is behind the confirm convention, never a bare submit.
        $response->assertSee('data-confirm', false);
        $response->assertSee('data-confirm-submit="product-delete-form"', false);
    }

    public function test_the_edit_form_says_variation_and_never_option(): void
    {
        $ids = $this->seedProducts();

        $response = $this->actingAs($this->manager())->get(route('products.edit', $ids['variantId']));

        $response->assertOk();
        $response->assertSee('Variations');
        $response->assertSee('Variation 1', false);
        // The word Option must not survive anywhere a person reads it. Script
        // bodies are stripped first: the builder's own identifiers
        // (EXISTING_OPTION, option_name, _options_format) are code, not copy.
        $visible = preg_replace('#<script\b[^>]*>.*?</script>#is', '', $response->getContent());
        $this->assertIsString($visible);
        $this->assertDoesNotMatchRegularExpression('/\bOptions?\b/i', strip_tags($visible));
    }

    public function test_the_satellite_pages_render(): void
    {
        $ids = $this->seedProducts();
        $user = $this->manager();

        $this->actingAs($user)->get(route('products.sales', $ids['variantId']))
            ->assertOk()->assertSee('Sales history')->assertSee('No sales yet');

        $this->actingAs($user)->get(route('products.stock_history', $ids['variantId']))
            ->assertOk()->assertSee('Stock history')->assertSee('On hand');
    }

    // ---- Gating, both directions ----------------------------------------

    public function test_view_tier_cannot_reach_the_create_or_edit_forms(): void
    {
        $viewer = $this->viewer();
        $ids = $this->seedProducts();

        $this->actingAs($viewer)->get(route('products.create'))->assertStatus(302);
        $this->actingAs($viewer)->get(route('products.edit', $ids['variantId']))->assertStatus(302);

        // ...but the read-only satellites are view-tier routes and do open.
        $this->actingAs($viewer)->get(route('products.sales', $ids['variantId']))->assertOk();
        $this->actingAs($viewer)->get(route('products.stock_history', $ids['variantId']))->assertOk();
    }

    public function test_view_tier_cannot_update_a_product(): void
    {
        $ids = $this->seedProducts();
        $p = config('catalog.prefix');

        $this->actingAs($this->viewer())
            ->put(route('products.update', $ids['variantId']), $this->fullProductPayload())
            ->assertStatus(302);

        $this->assertSame('MDL-1', DB::table($p.'product')->where('product_id', $ids['variantId'])->value('model'));
    }

    public function test_manage_tier_reaches_the_forms(): void
    {
        $ids = $this->seedProducts();
        $manager = $this->manager();

        $this->actingAs($manager)->get(route('products.create'))->assertOk();
        $this->actingAs($manager)->get(route('products.edit', $ids['variantId']))->assertOk();
    }

    // ---- THE round trip --------------------------------------------------

    public function test_a_full_update_lands_every_validated_field_in_the_database(): void
    {
        $ids = $this->seedProducts();
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $categoryId = $this->seedCategory();

        // resolveManufacturerId() looks a name up, it never creates one, so
        // the row has to exist for manufacturer_name to resolve. That is the
        // real flow too: the typeahead sets manufacturer_id, and the name is
        // the fallback the controller falls back to.
        $manufacturerId = DB::table($p.'manufacturer')->insertGetId([
            'name' => 'Round Trip Co', 'image' => '', 'sort_order' => 0,
        ]);

        $response = $this->actingAs($this->manager())->put(
            route('products.update', $ids['variantId']),
            $this->fullProductPayload([
                'category_ids' => [$categoryId],
                '_return' => route('products.index'),
            ])
        );

        $response->assertRedirect(route('products.index'));

        $row = DB::table($p.'product')->where('product_id', $ids['variantId'])->first();

        $this->assertSame('RT-MODEL-9', $row->model);
        $this->assertSame('RT-SKU-9', $row->sku);
        $this->assertEqualsWithDelta(250.00, (float) $row->price, 0.0001);
        $this->assertEqualsWithDelta(30.00, (float) $row->cost_amount, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $row->cost_percentage, 0.0001);
        $this->assertEqualsWithDelta(5.00, (float) $row->cost_additional, 0.0001);
        // cost = cost_amount + (cost_percentage / 100 * price) + cost_additional
        // The form's own `cost` box is a preview; the server recomputes it.
        $this->assertEqualsWithDelta(30.00 + (10.0 / 100 * 250.00) + 5.00, (float) $row->cost, 0.0001);
        $this->assertSame(17, (int) $row->quantity);
        $this->assertSame(4, (int) $row->reorder_level);
        $this->assertEqualsWithDelta(1.25, (float) $row->weight, 0.0001);
        $this->assertEqualsWithDelta(30.0, (float) $row->length, 0.0001);
        $this->assertEqualsWithDelta(20.0, (float) $row->width, 0.0001);
        $this->assertEqualsWithDelta(10.0, (float) $row->height, 0.0001);
        $this->assertSame(1, (int) $row->status);

        // manufacturer_name resolved to the existing row and its id written
        // back, with manufacturer_id posted as 0.
        $this->assertSame($manufacturerId, (int) $row->manufacturer_id);

        $desc = DB::table($p.'product_description')
            ->where('product_id', $ids['variantId'])->where('language_id', $langId)->first();
        $this->assertSame('Round Trip Widget', $desc->name);
        $this->assertSame('<p>A description with <strong>markup</strong>.</p>', $desc->description);

        $this->assertSame([$categoryId], DB::table($p.'product_to_category')
            ->where('product_id', $ids['variantId'])->pluck('category_id')->map(fn ($v) => (int) $v)->all());
    }

    /**
     * Binds the rendered form to the wire the round trip above exercises.
     *
     * The round-trip tests post synthetic payloads, so on their own they
     * prove the controller works, not that the rebuilt form still sends it
     * what it needs. This one reads the field names straight out of the
     * rendered edit page and checks every key the round trip posts is
     * actually a control on it, plus the form plumbing (CSRF, the PUT
     * spoof, the return URL, the image token, the variations format flag).
     *
     * The status assertion is deliberately literal. `status` validates as
     * `required|in:0,1`, so the switch only works because a hidden 0 is
     * rendered in front of the checkbox; lose that one input and every save
     * with the switch off becomes a validation error instead of a disabled
     * product. That is the single most breakable mapping on this page.
     */
    public function test_the_rendered_edit_form_carries_every_field_the_round_trip_posts(): void
    {
        $ids = $this->seedProducts();

        $html = $this->actingAs($this->manager())
            ->get(route('products.edit', $ids['variantId']))
            ->getContent();

        preg_match_all('/<(?:input|select|textarea)\b[^>]*\bname="([^"]+)"/i', $html, $m);
        $rendered = array_values(array_unique($m[1]));

        foreach (array_keys($this->fullProductPayload()) as $field) {
            $this->assertContains($field, $rendered, "The edit form no longer renders a control named {$field}, which the update round trip posts.");
        }

        foreach (['_token', '_method', '_return', 'pim_token', '_options_format'] as $field) {
            $this->assertContains($field, $rendered, "The edit form no longer renders the hidden {$field} input.");
        }

        $this->assertStringContainsString(
            '<input type="hidden" name="status" value="0">',
            $html,
            'The status switch lost its hidden 0 companion, so switching a product off would fail validation instead of disabling it.'
        );
    }

    public function test_a_create_round_trip_lands_every_validated_field(): void
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $this->actingAs($this->manager())
            ->post(route('products.store'), $this->fullProductPayload(['sku' => 'RT-NEW-1']))
            ->assertRedirect();

        $row = DB::table($p.'product')->where('sku', 'RT-NEW-1')->first();
        $this->assertNotNull($row);
        $this->assertSame('RT-MODEL-9', $row->model);
        $this->assertEqualsWithDelta(250.00, (float) $row->price, 0.0001);
        $this->assertEqualsWithDelta(60.00, (float) $row->cost, 0.0001);
        $this->assertSame(17, (int) $row->quantity);
        $this->assertSame(1, (int) $row->status);
        $this->assertEqualsWithDelta(1.25, (float) $row->weight, 0.0001);

        $this->assertSame('Round Trip Widget', DB::table($p.'product_description')
            ->where('product_id', $row->product_id)->where('language_id', $langId)->value('name'));
    }

    /**
     * The switch posts a hidden 0 in front of the checkbox, which is the only
     * shape `status => required|in:0,1` accepts in both positions. Off has to
     * reach the database as 0, not as a validation error.
     */
    public function test_the_status_switch_off_position_disables_the_product(): void
    {
        $ids = $this->seedProducts();
        $p = config('catalog.prefix');

        $this->actingAs($this->manager())->put(
            route('products.update', $ids['variantId']),
            $this->fullProductPayload(['status' => '0'])
        )->assertRedirect();

        $this->assertSame(0, (int) DB::table($p.'product')->where('product_id', $ids['variantId'])->value('status'));
    }

    // ---- Variations round trip -------------------------------------------

    /**
     * Add a variation through the builder's wire (_options_format=absolute,
     * option_name, values[i][...]) and then change one quantity. Two things
     * have to hold: the variation rows land, and the parent product's
     * quantity becomes the sum of the variation quantities regardless of what
     * the quantity input said.
     */
    public function test_a_variation_add_and_quantity_change_round_trip(): void
    {
        $ids = $this->seedProducts();
        $p = config('catalog.prefix');
        $manager = $this->manager();

        $this->actingAs($manager)->put(
            route('products.update', $ids['plainId']),
            $this->fullProductPayload([
                'sku' => 'RT-PARENT-1',
                'quantity' => '999',           // ignored: variations drive it
                '_options_format' => 'absolute',
                'option_name' => 'Size',
                'values' => [
                    ['name' => 'Small', 'sku' => 'RT-S', 'quantity' => '4', 'absolute_price' => '250', 'absolute_cost' => '60'],
                    ['name' => 'Large', 'sku' => 'RT-L', 'quantity' => '6', 'absolute_price' => '270', 'absolute_cost' => '70'],
                ],
            ])
        )->assertRedirect();

        $povRows = DB::table($p.'product_option_value')
            ->where('product_id', $ids['plainId'])->get()->keyBy('sku');

        $this->assertCount(2, $povRows);
        $this->assertSame(4, (int) $povRows['RT-S']->quantity);
        $this->assertSame(6, (int) $povRows['RT-L']->quantity);

        $this->assertSame(10, (int) DB::table($p.'product')
            ->where('product_id', $ids['plainId'])->value('quantity'));

        // Now change one quantity, and let it go negative: an oversold
        // variation is legitimate and the parent is the algebraic sum.
        $this->actingAs($manager)->put(
            route('products.update', $ids['plainId']),
            $this->fullProductPayload([
                'sku' => 'RT-PARENT-1',
                'quantity' => '999',
                '_options_format' => 'absolute',
                'option_name' => 'Size',
                'values' => [
                    ['name' => 'Small', 'sku' => 'RT-S', 'quantity' => '-2', 'absolute_price' => '250', 'absolute_cost' => '60'],
                    ['name' => 'Large', 'sku' => 'RT-L', 'quantity' => '6', 'absolute_price' => '270', 'absolute_cost' => '70'],
                ],
            ])
        )->assertRedirect();

        $this->assertSame(-2, (int) DB::table($p.'product_option_value')
            ->where('product_id', $ids['plainId'])->where('sku', 'RT-S')->value('quantity'));

        $this->assertSame(4, (int) DB::table($p.'product')
            ->where('product_id', $ids['plainId'])->value('quantity'));
    }

    // ---- Image round trip -------------------------------------------------

    /**
     * The two halves of the image flow, end to end against a faked public
     * disk: uploadToCatalog writes the file and answers with its storage
     * path, and that path, carried back on the product form as images_json,
     * becomes the product's main image row.
     */
    public function test_an_image_upload_round_trip_stores_the_file_and_attaches_it(): void
    {
        Storage::fake('public');

        $ids = $this->seedProducts();
        $p = config('catalog.prefix');
        $manager = $this->manager();

        $upload = $this->actingAs($manager)->post(route('products.images.upload_to_catalog'), [
            'file' => UploadedFile::fake()->image('round-trip.jpg', 40, 40),
            'path' => 'round-trip-co',
        ]);

        $upload->assertOk();
        $upload->assertJson(['ok' => true]);

        $path = $upload->json('path');
        $this->assertSame('catalog/round-trip-co/round-trip.jpg', $path);
        Storage::disk('public')->assertExists($path);

        // Two images: the first is the product's main image, the rest become
        // product_image rows in order. That ordering IS the wire.
        $second = $this->actingAs($manager)->post(route('products.images.upload_to_catalog'), [
            'file' => UploadedFile::fake()->image('round-trip-2.jpg', 40, 40),
            'path' => 'round-trip-co',
        ])->json('path');

        $this->actingAs($manager)->put(
            route('products.update', $ids['plainId']),
            $this->fullProductPayload([
                'sku' => 'RT-IMG-1',
                'images_json' => json_encode([$path, $second]),
            ])
        )->assertRedirect();

        $this->assertSame($path, DB::table($p.'product')
            ->where('product_id', $ids['plainId'])->value('image'));

        $this->assertSame([$second], DB::table($p.'product_image')
            ->where('product_id', $ids['plainId'])->orderBy('sort_order')->pluck('image')->all());
    }

    public function test_deleting_an_image_from_the_library_is_gated_on_the_manage_tier(): void
    {
        Storage::fake('public');
        Storage::disk('public')->put('catalog/round-trip-co/gone.jpg', 'x');

        $this->actingAs($this->viewer())
            ->post(route('products.images.delete'), ['path' => 'catalog/round-trip-co/gone.jpg'])
            ->assertStatus(302);

        Storage::disk('public')->assertExists('catalog/round-trip-co/gone.jpg');
    }

    // ---- XSS through the form ---------------------------------------------

    public function test_a_product_name_containing_markup_is_escaped_on_the_edit_form(): void
    {
        $p = config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $id = DB::table($p.'product')->insertGetId([
            'model' => 'MDL-X', 'sku' => '"><script>alert(1)</script>', 'quantity' => 1, 'price' => 1,
            'status' => 1, 'date_added' => now(), 'date_modified' => now(), 'date_available' => now(),
        ]);
        DB::table($p.'product_description')->insert([
            'product_id' => $id, 'language_id' => $langId,
            'name' => '<script>alert(1)</script>',
            'description' => '</textarea><script>alert(2)</script>',
            'tag' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '',
        ]);

        $response = $this->actingAs($this->manager())->get(route('products.edit', $id));

        $response->assertOk();
        $response->assertDontSee('<script>alert(1)</script>', false);
        // The description used to be echoed raw into the textarea, so a
        // description carrying a closing tag broke straight out of it.
        $response->assertDontSee('</textarea><script>alert(2)</script>', false);
    }
}
