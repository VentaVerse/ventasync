<?php

namespace Tests\Feature;

use Extensions\shopee\Models\ShopeeSetting;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Multi-store Shopee (#194, umbrella #180): the promotion of the shape
 * Venta, OpenCart and Shopify already live in. Grows a section per
 * stage; S1 pins the ground - settings are rows and every storeless
 * read goes through one named accessor.
 */
class ShopeeMultistoreTest extends TestCase
{
    use RefreshDatabase;

    // ── S1: the ground ───────────────────────────────────────────────

    public function test_default_store_is_the_first_enabled_row(): void
    {
        $disabled = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Closed shop', 'enabled' => false]);
        $first = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $second = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        $this->assertSame($first->id, ShopeeSetting::defaultStore()?->id,
            'defaultStore must return the first ENABLED row - a disabled store must never answer for the channel.');
    }

    public function test_no_store_at_all_answers_null_not_an_error(): void
    {
        $this->assertNull(ShopeeSetting::defaultStore());
    }

    /**
     * The whole point of S1: one spelling to re-aim in S4. A raw
     * singleton read reappearing would silently pin its caller to
     * "whatever row is first" forever.
     */
    public function test_nothing_reads_the_settings_singleton_raw(): void
    {
        $offenders = [];
        $scan = array_merge(
            glob(base_path('extensions/*/Controllers/*.php')) ?: [],
            glob(base_path('extensions/*/Commands/*.php')) ?: [],
            glob(base_path('extensions/*/Services/**/*.php')) ?: [],
            glob(base_path('extensions/*/Services/*.php')) ?: [],
            glob(base_path('extensions/*/Models/*.php')) ?: [],
            glob(base_path('extensions/*/*Extension.php')) ?: [],
            glob(base_path('app/**/*.php')) ?: [],
        );
        $this->assertNotEmpty($scan, 'the scan found no files; the guard is scanning nothing');

        foreach ($scan as $path) {
            $source = file_get_contents($path);
            $this->assertNotFalse($source, "unreadable: {$path}");
            if (str_contains($source, 'ShopeeSetting::query()->first()') || str_contains($source, 'ShopeeSetting::first()')) {
                $offenders[] = str_replace(base_path() . '/', '', $path);
            }
        }

        $this->assertSame([], $offenders,
            'These files read the Shopee settings singleton raw instead of ShopeeSetting::defaultStore(): '
            . implode(', ', $offenders));
    }

    // ── S2: the data carries the store ───────────────────────────────

    public function test_a_write_that_names_no_store_lands_on_the_default(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);

        $order = \Extensions\shopee\Models\ShopeeOrder::create(['region' => 'ph', 'order_sn' => 'S2-STAMP']);

        $this->assertSame($store->id, (int) $order->shopee_setting_id,
            'the creating hook must stamp the default store, or the NOT NULL column bites every un-threaded write path');
    }

    public function test_two_stores_may_hold_the_same_order_sn_in_one_region(): void
    {
        /* THE collision #180 names: (region, order_sn) was unique, so a
         * second shop in the same region could not even store its orders. */
        $one = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);

        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $one->id, 'region' => 'ph', 'order_sn' => 'SAME-SN']);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $two->id, 'region' => 'ph', 'order_sn' => 'SAME-SN']);

        $this->assertSame(2, \Extensions\shopee\Models\ShopeeOrder::where('order_sn', 'SAME-SN')->count());

        $this->expectException(\Illuminate\Database\UniqueConstraintViolationException::class);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $two->id, 'region' => 'sg', 'order_sn' => 'SAME-SN']);
    }

    public function test_for_store_scope_separates_the_shops(): void
    {
        $one = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $two = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Outlet']);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $one->id, 'region' => 'ph', 'order_sn' => 'ONE-1']);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $two->id, 'region' => 'ph', 'order_sn' => 'TWO-1']);

        $this->assertSame(['ONE-1'], \Extensions\shopee\Models\ShopeeOrder::forStore($one)->pluck('order_sn')->all());
        $this->assertSame(['TWO-1'], \Extensions\shopee\Models\ShopeeOrder::forStore($two->id)->pluck('order_sn')->all());
    }

    public function test_a_store_with_orders_refuses_deletion(): void
    {
        /* Restricted, not cascaded: dropping a store must never silently
         * erase its order history. */
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        \Extensions\shopee\Models\ShopeeOrder::create(['shopee_setting_id' => $store->id, 'region' => 'ph', 'order_sn' => 'HELD-1']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $store->delete();
    }

    public function test_the_existing_row_became_store_one_with_a_name(): void
    {
        /* The migration names the first row so the store list never shows
         * an anonymous entry; a fresh install with no row stays empty. */
        $s = ShopeeSetting::create(['mode' => 'live']);
        $this->assertTrue((bool) $s->fresh()->enabled, 'a row created the old way is an enabled store by default');
    }
}
