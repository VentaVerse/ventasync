<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Catalogue redesign, Round B Task 1: Products index onto the new design
 * system (resources/views/catalog/products/index.blade.php).
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
}
