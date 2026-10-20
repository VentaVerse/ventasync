<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class ListingTemplatesMigrationTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        $migration = require database_path('migrations/2026_10_21_000001_listing_templates.php');
        $migration->dropGroupSettings();
        DB::statement('SET FOREIGN_KEY_CHECKS=0');
        foreach (['shopee', 'lazada', 'tiktok', 'woocommerce', 'ventacart'] as $channel) {
            foreach (["ch_{$channel}_listing_template_attributes", "ch_{$channel}_listing_templates", "ch_{$channel}_product_group_products", "ch_{$channel}_product_groups", "ch_{$channel}_listings", "ch_{$channel}_stores"] as $table) {
                if (\Illuminate\Support\Facades\Schema::hasTable($table)) {
                    DB::table($table)->delete();
                }
            }
        }
        DB::table('ch_lazada_listing_attributes')->delete();
        DB::table('product_descriptions')->delete();
        DB::table('products')->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS=1');
        parent::tearDown();
    }

    private function migration(): object
    {
        $migration = require database_path('migrations/2026_10_21_000001_listing_templates.php');
        $migration->restoreGroupSettings();

        return $migration;
    }

    private function product(string $sku): int
    {
        $id = DB::table('products')->insertGetId([
            'sku' => $sku, 'quantity' => 5, 'price' => 100, 'status' => 1, 'image' => '', 'weight' => 0.5,
            'length' => 10, 'width' => 10, 'height' => 5, 'brand_id' => 0, 'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $id, 'language_id' => (int) config('catalog.default_language_id'), 'name' => 'Product ' . $sku,
            'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);

        return $id;
    }

    public function test_shopee_blank_listing_fields_take_the_group_values_and_own_values_stay(): void
    {
        $migration = $this->migration();
        $store = DB::table('ch_shopee_stores')->insertGetId(['mode' => 'live', 'store_name' => 'Main store']);
        $blank = $this->product('SH-1');
        $own = $this->product('SH-2');
        $missing = $this->product('SH-3');
        $group = DB::table('ch_shopee_product_groups')->insertGetId([
            'store_id' => $store, 'name' => 'Effects pedals', 'shopee_category_id' => 100, 'shopee_brand_id' => 7,
            'logistic_ids' => json_encode([1, 2]), 'markup_percent' => 10, 'markup_fixed' => null, 'watermark_template_id' => null,
        ]);
        DB::table('ch_shopee_product_group_attributes')->insert([
            ['shopee_product_group_id' => $group, 'attribute_key' => '501', 'value' => 'Local'],
            ['shopee_product_group_id' => $group, 'attribute_key' => '502', 'value' => '9V'],
        ]);
        foreach ([$blank, $own, $missing] as $pid) {
            DB::table('ch_shopee_product_group_products')->insert(['shopee_product_group_id' => $group, 'product_id' => $pid]);
        }
        DB::table('ch_shopee_listings')->insert(['store_id' => $store, 'product_id' => $blank]);
        DB::table('ch_shopee_listings')->insert([
            'store_id' => $store, 'product_id' => $own, 'shopee_category_id' => 200, 'shopee_brand_id' => 8,
            'logistic_ids' => json_encode([3]), 'markup_percent' => null, 'markup_fixed' => 50, 'attribute_values' => json_encode(['502' => '12V']),
        ]);

        $migration->copyGroups();

        $b = DB::table('ch_shopee_listings')->where('product_id', $blank)->first();
        $this->assertSame([100, 7, [1, 2], '10.00', null], [(int) $b->shopee_category_id, (int) $b->shopee_brand_id, json_decode($b->logistic_ids, true), $b->markup_percent, $b->markup_fixed]);
        $this->assertEquals(['501' => 'Local', '502' => '9V'], json_decode($b->attribute_values, true));

        $o = DB::table('ch_shopee_listings')->where('product_id', $own)->first();
        $this->assertSame([200, 8, [3], null, '50.00'], [(int) $o->shopee_category_id, (int) $o->shopee_brand_id, json_decode($o->logistic_ids, true), $o->markup_percent, $o->markup_fixed], 'own values and own price rule are kept');
        $this->assertEquals(['502' => '12V', '501' => 'Local'], json_decode($o->attribute_values, true), 'only the missing answer is added');

        $m = DB::table('ch_shopee_listings')->where('product_id', $missing)->first();
        $this->assertNotNull($m, 'a member with no listing gets one');
        $this->assertSame(100, (int) $m->shopee_category_id);

        $template = DB::table('ch_shopee_listing_templates')->where('store_id', $store)->first();
        $this->assertSame('Effects pedals', $template->name);
        $this->assertSame([100, 7, [1, 2], '10.00'], [(int) $template->shopee_category_id, (int) $template->shopee_brand_id, json_decode($template->logistic_ids, true), $template->markup_percent]);
        $this->assertSame(['501' => 'Local', '502' => '9V'], DB::table('ch_shopee_listing_template_attributes')->where('listing_template_id', $template->id)->orderBy('attribute_key')->pluck('value', 'attribute_key')->all());
    }

    public function test_lazada_brand_markup_and_attribute_rows_fill_only_blanks(): void
    {
        $migration = $this->migration();
        $store = DB::table('ch_lazada_stores')->insertGetId(['mode' => 'live', 'region' => 'ph', 'store_name' => 'Main']);
        $blank = $this->product('LZ-1');
        $own = $this->product('LZ-2');
        $group = DB::table('ch_lazada_product_groups')->insertGetId([
            'store_id' => $store, 'name' => 'Cables', 'lazada_category_id' => 4321, 'brand_id' => null, 'brand_name_override' => 'No Brand',
            'markup_percent' => null, 'markup_fixed' => 25,
        ]);
        DB::table('ch_lazada_product_group_attributes')->insert(['lazada_product_group_id' => $group, 'attribute_key' => 'warranty_type', 'value' => 'Local']);
        $l1 = DB::table('ch_lazada_listings')->insertGetId(['store_id' => $store, 'product_id' => $blank]);
        $l2 = DB::table('ch_lazada_listings')->insertGetId(['store_id' => $store, 'product_id' => $own, 'primary_category_id' => 9999, 'brand_id' => 55, 'markup_percent' => 5]);
        DB::table('ch_lazada_listing_attributes')->insert(['lazada_listing_id' => $l2, 'attribute_key' => 'warranty_type', 'value' => 'International']);
        foreach ([[$blank, $l1], [$own, $l2]] as [$pid, $lid]) {
            DB::table('ch_lazada_product_group_products')->insert(['lazada_product_group_id' => $group, 'product_id' => $pid, 'lazada_listing_id' => $lid]);
        }

        $migration->copyGroups();

        $b = DB::table('ch_lazada_listings')->find($l1);
        $this->assertSame([4321, 'No Brand', null, '25.00'], [(int) $b->primary_category_id, $b->brand_name_override, $b->markup_percent, $b->markup_fixed]);
        $this->assertSame('Local', DB::table('ch_lazada_listing_attributes')->where('lazada_listing_id', $l1)->value('value'));

        $o = DB::table('ch_lazada_listings')->find($l2);
        $this->assertSame([9999, 55, null, '5.00', null], [(int) $o->primary_category_id, (int) $o->brand_id, $o->brand_name_override, $o->markup_percent, $o->markup_fixed]);
        $this->assertSame('International', DB::table('ch_lazada_listing_attributes')->where('lazada_listing_id', $l2)->value('value'));
    }

    public function test_tiktok_and_woocommerce_groups_hand_over_category_and_rule(): void
    {
        $migration = $this->migration();
        $tt = DB::table('ch_tiktok_stores')->insertGetId(['store_name' => 'Main store', 'mode' => 'production']);
        $woo = DB::table('ch_woocommerce_stores')->insertGetId(['store_name' => 'Woo One', 'base_url' => 'https://woo.test', 'consumer_key' => 'ck', 'consumer_secret' => 'cs', 'enabled' => true]);
        $pid = $this->product('TT-1');

        $tg = DB::table('ch_tiktok_product_groups')->insertGetId(['store_id' => $tt, 'name' => 'Pedals', 'tiktok_category_id' => '600001', 'markup_percent' => 12]);
        DB::table('ch_tiktok_product_group_attributes')->insert(['tiktok_product_group_id' => $tg, 'attribute_key' => '100392', 'value' => 'Yes']);
        DB::table('ch_tiktok_product_group_products')->insert(['tiktok_product_group_id' => $tg, 'product_id' => $pid]);
        DB::table('ch_tiktok_listings')->insert(['store_id' => $tt, 'product_id' => $pid]);

        $wg = DB::table('ch_woocommerce_product_groups')->insertGetId(['store_id' => $woo, 'name' => 'Pedals', 'woo_category_id' => 15, 'markup_percent' => 0, 'markup_fixed' => 0]);
        DB::table('ch_woocommerce_product_group_products')->insert(['woocommerce_product_group_id' => $wg, 'product_id' => $pid]);

        $migration->copyGroups();

        $t = DB::table('ch_tiktok_listings')->where('product_id', $pid)->first();
        $this->assertSame(['600001', '12.00', ['100392' => 'Yes']], [(string) $t->tiktok_category_id, $t->markup_percent, json_decode($t->attribute_values, true)]);

        $w = DB::table('ch_woocommerce_listings')->where('store_id', $woo)->where('product_id', $pid)->first();
        $this->assertSame(15, (int) $w->woo_category_id);
        $this->assertNull($w->markup_percent, 'a zero rule changes no price, so nothing is copied');
        $this->assertSame('Pedals', DB::table('ch_woocommerce_listing_templates')->where('store_id', $woo)->value('name'));
    }

    public function test_a_template_name_already_taken_gets_a_number(): void
    {
        $migration = $this->migration();
        $store = DB::table('ch_ventacart_stores')->insertGetId(['store_name' => 'Gear Depot', 'base_url' => 'https://one.ventacart.test', 'api_token' => 'tok', 'enabled' => true]);
        DB::table('ch_ventacart_listing_templates')->insert(['store_id' => $store, 'name' => 'Pedals']);
        DB::table('ch_ventacart_product_groups')->insert(['store_id' => $store, 'name' => 'Pedals', 'ventacart_category_id' => 3]);

        $migration->copyGroups();

        $this->assertSame(['Pedals', 'Pedals (2)'], DB::table('ch_ventacart_listing_templates')->where('store_id', $store)->orderBy('id')->pluck('name')->all());
    }
}
