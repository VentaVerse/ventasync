<?php

namespace Tests\Feature;

use App\Models\Catalog\Order;
use Extensions\Lazada\Models\LazadaOrder;
use Extensions\Lazada\Models\LazadaStore;
use Extensions\Lazada\Services\LazadaSalesOrderSync;
use Extensions\Shopee\Models\ShopeeOrder;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\ShopeeSalesOrderSync;
use Extensions\TikTok\Models\TikTokOrder;
use Extensions\TikTok\Models\TikTokStore;
use Extensions\TikTok\Services\TikTokSalesOrderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UnmappedStatusTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_new_shopee_order_in_a_status_nobody_mapped_makes_no_sales_order(): void
    {
        ShopeeStore::create(['mode' => 'live', 'store_name' => 'Main store']);
        $so = ShopeeOrder::create(['order_sn' => 'SP-NEW', 'region' => 'PH', 'status' => 'SOMETHING_NEW',
            'raw' => ['currency' => 'PHP', 'total_amount' => 100, 'buyer_username' => 'b']]);

        app(ShopeeSalesOrderSync::class)->sync($so);

        $this->assertFalse(Order::where('channel', 'shopee')->where('channel_order_id', 'SP-NEW')->exists());
        $this->assertNull($so->fresh()->sales_order_id);
    }

    public function test_a_shopee_order_moving_to_an_unmapped_status_keeps_its_sales_order_status(): void
    {
        ShopeeStore::create(['mode' => 'live', 'store_name' => 'Main store']);
        $so = ShopeeOrder::create(['order_sn' => 'SP-1', 'region' => 'PH', 'status' => 'COMPLETED',
            'raw' => ['currency' => 'PHP', 'total_amount' => 100, 'buyer_username' => 'b']]);
        app(ShopeeSalesOrderSync::class)->setSkipStockAdjust(true)->sync($so);
        $order = Order::where('channel', 'shopee')->where('channel_order_id', 'SP-1')->firstOrFail();
        $before = (int) $order->order_status_id;
        $this->assertNotSame(1, $before, 'COMPLETED is mapped to a status other than Pending');

        $so->update(['status' => 'SOMETHING_NEW']);
        app(ShopeeSalesOrderSync::class)->sync($so->fresh());

        $this->assertSame($before, (int) $order->fresh()->order_status_id);
    }

    public function test_lazada_and_tiktok_hold_an_unmapped_status_the_same_way(): void
    {
        LazadaStore::create(['mode' => 'live', 'store_name' => 'Laz PH', 'region' => 'PH']);
        $lo = LazadaOrder::create(['order_id' => 999101, 'region' => 'PH', 'status' => 'something_new',
            'raw' => ['currency' => 'PHP', 'price' => 200, 'customer_name' => 'Li Wei']]);
        app(LazadaSalesOrderSync::class)->sync($lo);
        $this->assertFalse(Order::where('channel', 'lazada')->where('channel_order_id', '999101')->exists());

        $lo->update(['status' => 'confirmed']);
        app(LazadaSalesOrderSync::class)->setSkipStockAdjust(true)->sync($lo->fresh());
        $lazada = Order::where('channel', 'lazada')->where('channel_order_id', '999101')->firstOrFail();
        $lo->update(['status' => 'something_new']);
        app(LazadaSalesOrderSync::class)->sync($lo->fresh());
        $this->assertSame((int) $lazada->order_status_id, (int) $lazada->fresh()->order_status_id);

        TikTokStore::create(['mode' => 'production', 'store_name' => 'TT Shop']);
        $to = TikTokOrder::create(['order_id' => 'TT-NEW', 'region' => 'PH', 'status' => 'SOMETHING_NEW',
            'raw' => ['payment_info' => ['currency' => 'PHP', 'total_amount' => 75], 'recipient_address' => ['name' => 'Customer']]]);
        app(TikTokSalesOrderSync::class)->sync($to);
        $this->assertFalse(Order::where('channel', 'tiktok')->where('channel_order_id', 'TT-NEW')->exists());
    }
}
