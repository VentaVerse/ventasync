<?php

namespace Tests\Feature;

use App\Support\ChannelMetrics;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MakesCoreOrders;
use Tests\TestCase;

class ChannelMetricsScopeTest extends TestCase
{
    use RefreshDatabase;
    use MakesCoreOrders;

    public function test_a_big_three_store_key_finds_the_orders_stamped_with_its_store_id(): void
    {
        $this->coreOrder(['marketplace_source' => 'shopee', 'store_id' => 1, 'store_name' => 'Main store', 'total' => 100]);
        $this->coreOrder(['marketplace_source' => 'shopee', 'store_id' => 1, 'store_name' => 'Main store', 'total' => 250]);
        $this->coreOrder(['marketplace_source' => 'shopee', 'store_id' => 2, 'store_name' => 'Outlet', 'total' => 900]);
        $this->coreOrder(['marketplace_source' => 'ventacart:3', 'store_id' => 3, 'store_name' => 'Gear Depot', 'total' => 40]);
        $metrics = app(ChannelMetrics::class);

        $main = $metrics->trend('shopee:1');
        $this->assertNotNull($main, 'the first store has sales');
        $this->assertSame(2, (int) $main['totalOrders']);

        $outlet = $metrics->trend('shopee:2');
        $this->assertNotNull($outlet);
        $this->assertSame(1, (int) $outlet['totalOrders']);

        $this->assertNotNull($metrics->trend('ventacart:3'));
        $this->assertNull($metrics->trend('shopee:9'));
    }

    public function test_best_sellers_read_the_same_store_key(): void
    {
        $mine = $this->coreOrder(['marketplace_source' => 'lazada', 'store_id' => 5, 'store_name' => 'Gearshipper']);
        $theirs = $this->coreOrder(['marketplace_source' => 'lazada', 'store_id' => 6, 'store_name' => 'Second']);
        $this->coreOrderLine($mine, 120, 40, 3);
        $this->coreOrderLine($theirs, 999, 40, 9);

        $rows = app(ChannelMetrics::class)->bestSellers('lazada:5');
        $this->assertCount(1, $rows);
        $this->assertSame(3, (int) $rows[0]['units']);
    }
}
