<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\ShopeeExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * A read route does not change what it reports.
 *
 * extensions/shopee/routes/web.php states the contract in its own words:
 * "Read routes (display-only, no DB write, no marketplace API write): gated by
 * view_shopee|manage_shopee". The product-group products page broke it. Inside
 * that GET handler it back-filled pivot rows from the links table, so an
 * operator holding only view_shopee mutated the database by looking at a page,
 * with no banner and no audit entry.
 *
 * That back-fill is useful for someone who can already write, so it is kept
 * for the manage tier and gated away from the read tier, which is what these
 * two tests hold apart. The related defect - the same back-fill overwriting a
 * recorded failure and rendering it as a green Pushed badge - is pinned
 * separately in ShopeePushStatusTest.
 */
class ReadRouteDoesNotWriteTest extends TestCase
{
    use RefreshDatabase;

    private const PRODUCT = 4242;
    private const ITEM = '778899001';

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function tierUser(string $label, array $permissions): User
    {
        $group = UserGroup::create(['name' => $label.' '.uniqid('', true)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $permissions)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    /**
     * A pivot with no item id, a link that could fill it, and a product the
     * group actually matches. This is the exact state the back-fill acts on.
     */
    private function seedAdoptableRow(): int
    {
        $pfx = (string) config('catalog.prefix');

        DB::table($pfx.'product')->insert([
            'product_id' => self::PRODUCT,
            'sku' => 'ADOPT-ME',
            'model' => 'ADOPT-ME',
            'quantity' => 5,
            'price' => 100,
            'status' => 1,
            // Both are NOT NULL with no default on this schema.
            'date_added' => now(),
            'date_modified' => now(),
        ]);

        // The listing query inner-joins product_description, so a product
        // with no name row never appears and the back-fill never sees it.
        DB::table($pfx.'product_description')->insert([
            'product_id' => self::PRODUCT,
            'language_id' => (int) config('catalog.default_language_id'),
            'name' => 'Adoptable product',
            'description' => '',
            'meta_title' => 'Adoptable product',
            'meta_description' => '',
            'meta_keyword' => '',
        ]);

        $groupId = DB::table('shopee_product_groups')->insertGetId([
            'name' => 'Adoption',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('shopee_product_group_products')->insert([
            'shopee_product_group_id' => $groupId,
            'product_id' => self::PRODUCT,
            'shopee_item_id' => null,
            'sync_status' => 'pending',
        ]);

        DB::table('shopee_product_links')->insert([
            'product_id' => self::PRODUCT,
            'shopee_item_id' => self::ITEM,
            'sku' => 'ADOPT-ME',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $groupId;
    }

    private function pivot(int $groupId): ?object
    {
        return DB::table('shopee_product_group_products')
            ->where('shopee_product_group_id', $groupId)
            ->where('product_id', self::PRODUCT)
            ->first();
    }

    /** The defect. Looking at a page must not be a write. */
    public function test_the_read_tier_does_not_mutate_rows_by_loading_the_page(): void
    {
        $groupId = $this->seedAdoptableRow();

        $this->actingAs($this->tierUser('Shopee Viewer', ['view_shopee', 'view_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.products', ['id' => $groupId]))
            ->assertOk();

        $row = $this->pivot($groupId);

        $this->assertNull($row->shopee_item_id,
            'A view-only operator wrote an item id to the database by loading a read route.');
        $this->assertSame('pending', $row->sync_status,
            'A view-only operator changed a row status by loading a read route.');
    }

    /** And the convenience is not lost for someone who can already write. */
    public function test_the_manage_tier_still_adopts_an_existing_link(): void
    {
        $groupId = $this->seedAdoptableRow();

        $this->actingAs($this->tierUser('Shopee Manager', ['view_shopee', 'view_shopee/dashboard', 'manage_shopee', 'manage_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.products', ['id' => $groupId]))
            ->assertOk();

        $row = $this->pivot($groupId);

        $this->assertSame(self::ITEM, (string) $row->shopee_item_id,
            'The manage tier no longer adopts a link that already exists.');
        $this->assertSame('pushed', $row->sync_status);
    }

    /**
     * Rows changing state without anyone asking must leave a trace, or the
     * only record of the change is the change itself.
     */
    public function test_an_adoption_is_recorded(): void
    {
        $groupId = $this->seedAdoptableRow();

        $this->actingAs($this->tierUser('Shopee Manager', ['view_shopee', 'view_shopee/dashboard', 'manage_shopee', 'manage_shopee/dashboard']))
            ->get(route('ext.shopee.product-groups.products', ['id' => $groupId]))
            ->assertOk();

        $this->assertDatabaseHas('activity_logs', [
            'subject_type' => 'Shopee Product Group',
            'subject_id' => $groupId,
        ]);
    }
}
