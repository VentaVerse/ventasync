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

class StoreStampTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_stamps_the_store_on_create_and_keeps_it_on_resync(): void
    {
        $store = ShopeeStore::create(['mode' => 'live', 'store_name' => 'Main store']);
        $so = ShopeeOrder::create(['order_sn' => 'SP-1', 'region' => 'PH', 'status' => 'READY_TO_SHIP',
            'raw' => ['currency' => 'PHP', 'total_amount' => 100, 'buyer_username' => 'b']]);
        $this->assertSame($store->id, (int) $so->store_id);

        app(ShopeeSalesOrderSync::class)->setSkipStockAdjust(true)->sync($so);
        $order = Order::where('channel', 'shopee')->where('channel_order_id', 'SP-1')->firstOrFail();
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame('Main store', $order->store_name);

        $store->update(['store_name' => 'Renamed store']);
        app(ShopeeSalesOrderSync::class)->setSkipStockAdjust(true)->sync($so->fresh());
        $this->assertSame('Main store', $order->fresh()->store_name, 'the name the order was saved with');

        $long = ShopeeStore::create(['mode' => 'live', 'store_name' => str_repeat('Long name ', 10)]);
        $so2 = ShopeeOrder::create(['order_sn' => 'SP-2', 'region' => 'PH', 'status' => 'READY_TO_SHIP', 'store_id' => $long->id,
            'raw' => ['currency' => 'PHP', 'total_amount' => 100, 'buyer_username' => 'b']]);
        app(ShopeeSalesOrderSync::class)->setSkipStockAdjust(true)->sync($so2);
        $this->assertSame(64, mb_strlen(Order::where('channel_order_id', 'SP-2')->value('store_name')), 'cut to the column');
    }

    public function test_lazada_stamps_the_store(): void
    {
        $store = LazadaStore::create(['mode' => 'live', 'store_name' => 'Laz PH', 'region' => 'PH']);
        $lo = LazadaOrder::create(['order_id' => 999101, 'region' => 'PH', 'status' => 'pending',
            'raw' => ['currency' => 'PHP', 'price' => 200, 'customer_name' => 'Li Wei']]);
        app(LazadaSalesOrderSync::class)->setSkipStockAdjust(true)->sync($lo);
        $order = Order::where('channel', 'lazada')->where('channel_order_id', '999101')->firstOrFail();
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame('Laz PH', $order->store_name);
    }

    public function test_tiktok_stamps_the_store(): void
    {
        $store = TikTokStore::create(['mode' => 'production', 'store_name' => 'TT Shop']);
        $to = TikTokOrder::create(['order_id' => 'TT-1', 'region' => 'PH', 'status' => 'AWAITING_SHIPMENT',
            'raw' => ['payment_info' => ['currency' => 'PHP', 'total_amount' => 75], 'recipient_address' => ['name' => 'Buyer']]]);
        app(TikTokSalesOrderSync::class)->setSkipStockAdjust(true)->sync($to);
        $order = Order::where('channel', 'tiktok')->where('channel_order_id', 'TT-1')->firstOrFail();
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame('TT Shop', $order->store_name);
    }
}
