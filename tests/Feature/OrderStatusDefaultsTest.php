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
        return DB::table('order_statuses')
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

        foreach (['Canceled', 'Denied', 'Reversed', 'Failed'] as $name) {
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
        $ids = DB::table('order_statuses')->pluck('order_status_id')->all();

        foreach (['ch_shopee_order_status_map', 'ch_lazada_order_status_map', 'ch_tiktok_order_status_map'] as $table) {
            foreach (DB::table($table)->pluck('order_status_id') as $mapped) {
                $this->assertContains((int) $mapped, $ids, $table . ' maps to a status that exists');
            }
        }
    }

    private const JOURNEYS = [
        'ch_shopee_order_status_map' => ['shopee_status', [
            'takes' => ['UNPAID', 'READY_TO_SHIP', 'PROCESSED', 'RETRY_SHIP', 'SHIPPED', 'TO_CONFIRM_RECEIVE', 'TO_RETURN', 'COMPLETED', 'IN_CANCEL', 'INVOICE_PENDING'],
            'gives' => ['CANCELLED'],
        ]],
        'ch_tiktok_order_status_map' => ['tiktok_status', [
            'takes' => ['UNPAID', 'ON_HOLD', 'AWAITING_SHIPMENT', 'AWAITING_COLLECTION', 'IN_TRANSIT', 'DELIVERED', 'COMPLETED'],
            'gives' => ['CANCELLED'],
        ]],
        'ch_lazada_order_status_map' => ['lazada_status', [
            'takes' => ['unpaid', 'pending', 'packed', 'repacked', 'ready_to_ship', 'shipped', 'lost_by_3pl', 'damaged_by_3pl', 'delivered', 'confirmed'],
            'gives' => ['canceled', 'cancelled', 'failed_delivery'],
        ]],
    ];

    public function test_every_channel_keeps_stock_taken_from_paid_to_delivered_on_a_new_install(): void
    {
        $subtracts = DB::table('order_statuses')->pluck('subtract_stock', 'order_status_id')->map(fn ($v) => (int) $v);

        foreach (self::JOURNEYS as $table => [$column, $journey]) {
            $map = DB::table($table)->where('context', 'order')->pluck('order_status_id', $column);

            foreach ($journey['takes'] as $status) {
                $this->assertArrayHasKey($status, $map->all(), "{$table} maps {$status} on a new install");
                $this->assertSame(1, $subtracts[(int) $map[$status]] ?? null, "{$table}: {$status} keeps the stock taken");
            }
            foreach ($journey['gives'] as $status) {
                $this->assertArrayHasKey($status, $map->all(), "{$table} maps {$status} on a new install");
                $this->assertSame(0, $subtracts[(int) $map[$status]] ?? null, "{$table}: {$status} gives the stock back");
            }
        }
    }
}
