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

class MarketplaceBuyerNoteTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_keeps_the_message_to_seller_and_follows_a_change(): void
    {
        ShopeeStore::create(['mode' => 'live', 'store_name' => 'Main store']);
        $so = ShopeeOrder::create(['order_sn' => 'SP-N1', 'region' => 'PH', 'status' => 'READY_TO_SHIP',
            'raw' => ['currency' => 'PHP', 'total_amount' => 100, 'buyer_username' => 'b', 'message_to_seller' => '  Please wrap it as a gift. ']]);

        app(ShopeeSalesOrderSync::class)->setSkipStockAdjust(true)->sync($so);
        $order = Order::where('channel', 'shopee')->where('channel_order_id', 'SP-N1')->firstOrFail();
        $this->assertSame('Please wrap it as a gift.', $order->customer_note);

        $so->update(['raw' => ['currency' => 'PHP', 'total_amount' => 100, 'buyer_username' => 'b']]);
        app(ShopeeSalesOrderSync::class)->setSkipStockAdjust(true)->sync($so->fresh());
        $this->assertSame('', $order->fresh()->customer_note);
    }

    public function test_lazada_keeps_the_buyer_note(): void
    {
        LazadaStore::create(['mode' => 'live', 'store_name' => 'Laz PH', 'region' => 'PH']);
        $lo = LazadaOrder::create(['order_id' => 999201, 'region' => 'PH', 'status' => 'pending',
            'raw' => ['currency' => 'PHP', 'price' => 200, 'customer_name' => 'Li Wei', 'buyer_note' => 'Leave it at the guard house.']]);

        app(LazadaSalesOrderSync::class)->setSkipStockAdjust(true)->sync($lo);

        $this->assertSame('Leave it at the guard house.', Order::where('channel', 'lazada')->where('channel_order_id', '999201')->value('customer_note'));
    }

    public function test_tiktok_keeps_the_buyer_message(): void
    {
        TikTokStore::create(['mode' => 'production', 'store_name' => 'TT Shop']);
        $to = TikTokOrder::create(['order_id' => 'TT-N1', 'region' => 'PH', 'status' => 'AWAITING_SHIPMENT',
            'raw' => ['payment_info' => ['currency' => 'PHP', 'total_amount' => 75], 'recipient_address' => ['name' => 'Buyer'], 'buyer_message' => 'Black color please.']]);

        app(TikTokSalesOrderSync::class)->setSkipStockAdjust(true)->sync($to);

        $this->assertSame('Black color please.', Order::where('channel', 'tiktok')->where('channel_order_id', 'TT-N1')->value('customer_note'));
    }
}
