<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * THE ORDER STATUSES A NEW INSTALL STARTS WITH.
 *
 * Stock is subtracted the moment an order reaches a status that subtracts it,
 * and given back when it leaves for one that does not. So every status an
 * order passes through while the goods are committed has to subtract: an
 * order that subtracted on the way in and not on the way out sends the stock
 * back up while the parcel is in transit.
 */
class OrderStatusDefaultsTest extends TestCase
{
    use RefreshDatabase;

    /** @return \Illuminate\Support\Collection<string, int> name => subtract_stock */
    private function statuses()
    {
        return DB::table(config('catalog.prefix') . 'order_status')
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->pluck('subtract_stock', 'name')
            ->map(fn ($v) => (int) $v);
    }

    /** Everything from the unpaid order to the delivered one holds the goods. */
    public function test_the_statuses_an_order_passes_through_subtract_stock(): void
    {
        $statuses = $this->statuses();

        foreach (['Unpaid', 'Pending', 'Processing', 'Shipped', 'Delivered', 'Completed'] as $name) {
            $this->assertSame(1, $statuses[$name] ?? null, $name . ' subtracts stock');
        }
    }

    /** The ends that release the goods give the stock back. */
    public function test_the_statuses_that_release_the_goods_do_not(): void
    {
        $statuses = $this->statuses();

        foreach (['Cancelled', 'Denied', 'Reversed', 'Failed'] as $name) {
            $this->assertSame(0, $statuses[$name] ?? null, $name . ' returns the stock');
        }
    }

    /** One name for one state: "Complete" and "Completed" were both shipped. */
    public function test_there_is_only_one_completed_status(): void
    {
        $this->assertArrayNotHasKey('Complete', $this->statuses()->all());
        $this->assertArrayHasKey('Completed', $this->statuses()->all());
    }

    /** The marketplaces call it unpaid, so it is called that here too. */
    public function test_the_unpaid_status_is_named_for_what_it_is(): void
    {
        $statuses = $this->statuses();

        $this->assertArrayHasKey('Unpaid', $statuses->all());
        $this->assertArrayNotHasKey('Awaiting Payment', $statuses->all());
    }

    /** Nothing a channel maps to may be missing from the list. */
    public function test_every_channel_mapping_points_at_a_status_that_exists(): void
    {
        $ids = DB::table(config('catalog.prefix') . 'order_status')->pluck('order_status_id')->all();

        foreach (['shopee_order_status_map', 'lazada_order_status_map', 'tiktok_order_status_map'] as $table) {
            foreach (DB::table($table)->pluck('order_status_id') as $mapped) {
                $this->assertContains((int) $mapped, $ids, $table . ' maps to a status that exists');
            }
        }
    }
}
