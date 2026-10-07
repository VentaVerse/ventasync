<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderStatusDefaultsTest extends TestCase
{
    use RefreshDatabase;

    private function statuses()
    {
        return DB::table(config('catalog.prefix') . 'order_status')
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->pluck('subtract_stock', 'name')
            ->map(fn ($v) => (int) $v);
    }

    public function test_the_statuses_an_order_passes_through_subtract_stock(): void
    {
        $statuses = $this->statuses();

        foreach (['Unpaid', 'Pending', 'Processing', 'Shipped', 'Delivered', 'Completed'] as $name) {
            $this->assertSame(1, $statuses[$name] ?? null, $name . ' subtracts stock');
        }
    }

    public function test_the_statuses_that_release_the_goods_do_not(): void
    {
        $statuses = $this->statuses();

        foreach (['Cancelled', 'Denied', 'Reversed', 'Failed'] as $name) {
            $this->assertSame(0, $statuses[$name] ?? null, $name . ' returns the stock');
        }
    }

    public function test_there_is_only_one_completed_status(): void
    {
        $this->assertArrayNotHasKey('Complete', $this->statuses()->all());
        $this->assertArrayHasKey('Completed', $this->statuses()->all());
    }

    public function test_the_unpaid_status_is_named_for_what_it_is(): void
    {
        $statuses = $this->statuses();

        $this->assertArrayHasKey('Unpaid', $statuses->all());
        $this->assertArrayNotHasKey('Awaiting Payment', $statuses->all());
    }

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
