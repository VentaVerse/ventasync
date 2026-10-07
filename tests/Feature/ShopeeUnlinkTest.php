<?php

namespace Tests\Feature;

use Extensions\shopee\Models\ShopeeItemCache;
use Extensions\shopee\Models\ShopeeProductLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeUnlinkTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        \Extensions\shopee\Models\ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
    }

    private const PRODUCT = 2708;
    private const ITEM = 46008714785;
    private const SKU = 'demon-mt500';

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

    public function test_unlinking_clears_the_item_cache_for_that_link(): void
    {
        $this->link();

        ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId());

        $this->assertDatabaseMissing('shopee_item_cache', [
            'shopee_item_id' => self::ITEM,
            'sku' => self::SKU,
        ]);
    }

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

    public function test_unlinking_clears_the_group_pivot_copy_of_the_id(): void
    {
        $this->link();
        $this->pivot(['shopee_item_id' => (string) self::ITEM, 'sync_status' => 'pushed']);

        ShopeeProductLink::unlinkProduct(self::PRODUCT, $this->storeId());

        $row = \DB::table('shopee_product_group_products')->where('product_id', self::PRODUCT)->first();

        $this->assertNull($row->shopee_item_id, 'The pivot kept a Shopee id after the product was unlinked.');
        $this->assertSame('unlinked', $row->sync_status);
    }

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
