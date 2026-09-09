<?php

namespace Tests\Feature;

use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Unlinking a product from Shopee.
 *
 * There were two unlink actions and they did not agree. The products page
 * deleted the shopee_product_links rows and cleared the matching item cache;
 * the product-group page set a pivot's sync_status to 'unlinked' and deleted
 * nothing, then reported "Product unlinked from Shopee." either way.
 *
 * The push loop's first test is whether a ShopeeProductLink exists, so a
 * product unlinked from the group page kept coming back as
 * "0 pushed, 1 skipped (already linked or disabled)" - with no API log, because
 * the skip happens before any call is made. The interface said the link was
 * gone, the table said it was there, and the operator had no way to see which
 * was telling the truth.
 *
 * These tests pin the behaviour rather than the wording, so the two callers
 * cannot drift apart again: whatever "unlink" means, it must mean the same
 * thing to both, and it must mean the link is actually gone.
 */
class ShopeeUnlinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Multi-store S2 (#194): rows the tests create belong to a store.
        \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
    }

    private const PRODUCT = 2708;
    private const ITEM = 46008714785;
    private const SKU = 'demon-mt500';

    /** The one store the fixtures create; every unlink is FOR a store now. */
    private function storeId(): int
    {
        return (int) \Extensions\shopee\Models\ShopeeSetting::query()->orderBy('id')->value('id');
    }

    private function link(): ShopeeProductLink
    {
        ShopeeItemCache::create([
            'shopee_item_id' => self::ITEM,
            'sku' => self::SKU,
            'item_name' => 'DemonFX MT-500 Strobe Tuner',
        ]);

        return ShopeeProductLink::create([
            'product_id' => self::PRODUCT,
            'shopee_item_id' => self::ITEM,
            'sku' => self::SKU,
        ]);
    }

    private function pivot(array $attrs = []): void
    {
        $group = \DB::table('shopee_product_groups')->insertGetId([
            'shopee_setting_id' => \Extensions\shopee\Models\ShopeeSetting::query()->orderBy('id')->value('id')
                ?? \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store'])->id,
            'name' => 'Group 8',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        \DB::table('shopee_product_group_products')->insert(array_merge([
            'shopee_product_group_id' => $group,
            'product_id' => self::PRODUCT,
        ], $attrs));
    }

    public function test_unlinking_removes_the_link_row(): void
    {
        $this->link();

        $removed = ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId());

        $this->assertSame(1, $removed);
        $this->assertDatabaseMissing('shopee_product_links', ['product_id' => self::PRODUCT]);
    }

    /**
     * A cache row outliving its link is what makes the next SKU match silently
     * re-attach the product to the item it was just detached from, so the
     * operator unlinks, pushes, and lands back where they started.
     */
    public function test_unlinking_clears_the_item_cache_for_that_link(): void
    {
        $this->link();

        ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId());

        $this->assertDatabaseMissing('shopee_item_cache', [
            'shopee_item_id' => self::ITEM,
            'sku' => self::SKU,
        ]);
    }

    /**
     * Another product's link sharing the cache must survive. The delete is
     * keyed on item AND sku for exactly this reason.
     */
    public function test_unlinking_leaves_other_products_alone(): void
    {
        $this->link();

        ShopeeProductLink::create([
            'product_id' => 9999,
            'shopee_item_id' => 88888888,
            'sku' => 'other-sku',
        ]);
        ShopeeItemCache::create([
            'shopee_item_id' => 88888888,
            'sku' => 'other-sku',
        ]);

        ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId());

        $this->assertDatabaseHas('shopee_product_links', ['product_id' => 9999]);
        $this->assertDatabaseHas('shopee_item_cache', ['shopee_item_id' => 88888888]);
    }

    /**
     * The third place the item id lives. The group page keeps its own copy, and
     * a copy that outlives the link is displayed as a live Shopee id against a
     * product that is linked to nothing.
     */
    public function test_unlinking_clears_the_group_pivot_copy_of_the_id(): void
    {
        $this->link();
        $this->pivot(['shopee_item_id' => (string) self::ITEM, 'sync_status' => 'pushed']);

        ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId());

        $row = \DB::table('shopee_product_group_products')->where('product_id', self::PRODUCT)->first();

        $this->assertNull($row->shopee_item_id, 'The pivot kept a Shopee id after the product was unlinked.');
        $this->assertSame('unlinked', $row->sync_status);
    }

    /**
     * The exact state this bug left behind: a pivot holding an id the link
     * table has already lost. Refusing to clean it because there is no link is
     * how the leftover survived every unlink.
     */
    public function test_a_pivot_id_is_cleared_even_when_the_link_is_already_gone(): void
    {
        $this->pivot(['shopee_item_id' => (string) self::ITEM, 'sync_status' => 'error']);

        $this->assertSame(0, ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId()));

        $row = \DB::table('shopee_product_group_products')->where('product_id', self::PRODUCT)->first();
        $this->assertNull($row->shopee_item_id, 'An orphaned pivot id survived an unlink.');
    }

    public function test_unlinking_something_never_linked_reports_nothing_removed(): void
    {
        $this->assertSame(0, ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId()));
    }

    public function test_unlinking_twice_is_safe(): void
    {
        $this->link();

        $this->assertSame(1, ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId()));
        $this->assertSame(0, ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId()));
    }

    /**
     * The ratchet. Both unlink actions must go through the one definition -
     * a third caller that hand-rolls its own delete, or an old one that only
     * touches a status column, is how this broke the first time.
     */
    public function test_every_unlink_action_goes_through_the_shared_definition(): void
    {
        $callers = [
            'extensions/shopee/Controllers/ShopeeProductController.php',
            'extensions/shopee/Controllers/ShopeeProductGroupController.php',
        ];

        foreach ($callers as $file) {
            $source = file_get_contents(base_path($file));

            $this->assertStringContainsString(
                'ShopeeProductLink::unlinkProduct(',
                $source,
                $file.' unlinks without using the shared definition, so it can drift from the other caller again.'
            );
        }
    }
}
