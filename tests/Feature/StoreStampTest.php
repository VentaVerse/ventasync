<?php

namespace Tests\Feature;

use App\Models\Catalog\Order;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\LazadaCatalogOrderSync;
use Extensions\shopee\Models\ShopeeOrder;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\ShopeeCatalogOrderSync;
use Extensions\tiktok\Models\TikTokOrder;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTokCatalogOrderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class StoreStampTest extends TestCase
{
    use RefreshDatabase;

    public function test_shopee_stamps_the_store_on_create_and_follows_a_rename_on_resync(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $so = ShopeeOrder::create(['order_sn' => 'SP-1', 'region' => 'PH', 'status' => 'READY_TO_SHIP',
            'raw' => ['currency' => 'PHP', 'total_amount' => 100, 'buyer_username' => 'b']]);
        $this->assertSame($store->id, (int) $so->shopee_setting_id);

        app(ShopeeCatalogOrderSync::class)->setSkipStockAdjust(true)->sync($so);
        $order = Order::where('marketplace_source', 'shopee')->where('marketplace_order_id', 'SP-1')->firstOrFail();
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame('Main store', $order->store_name);

        $store->update(['store_name' => str_repeat('Long name ', 10)]);
        app(ShopeeCatalogOrderSync::class)->setSkipStockAdjust(true)->sync($so->fresh());
        $this->assertSame(64, mb_strlen($order->fresh()->store_name), 'cut to the column');
    }

    public function test_lazada_stamps_the_store(): void
    {
        $store = LazadaSetting::create(['mode' => 'live', 'store_name' => 'Laz PH', 'region' => 'PH']);
        $lo = LazadaOrder::create(['order_id' => 999101, 'region' => 'PH', 'status' => 'pending',
            'raw' => ['currency' => 'PHP', 'price' => 200, 'customer_name' => 'Li Wei']]);
        app(LazadaCatalogOrderSync::class)->setSkipStockAdjust(true)->sync($lo);
        $order = Order::where('marketplace_source', 'lazada')->where('marketplace_order_id', '999101')->firstOrFail();
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame('Laz PH', $order->store_name);
    }

    public function test_tiktok_stamps_the_store(): void
    {
        $store = TikTokSetting::create(['mode' => 'production', 'store_name' => 'TT Shop']);
        $to = TikTokOrder::create(['order_id' => 'TT-1', 'region' => 'PH', 'status' => 'AWAITING_SHIPMENT',
            'raw' => ['payment_info' => ['currency' => 'PHP', 'total_amount' => 75], 'recipient_address' => ['name' => 'Buyer']]]);
        app(TikTokCatalogOrderSync::class)->setSkipStockAdjust(true)->sync($to);
        $order = Order::where('marketplace_source', 'tiktok')->where('marketplace_order_id', 'TT-1')->firstOrFail();
        $this->assertSame($store->id, (int) $order->store_id);
        $this->assertSame('TT Shop', $order->store_name);
    }

    public function test_the_backfill_stamps_old_orders_from_the_channel_rows_and_leaves_orphans_alone(): void
    {
        $store = ShopeeSetting::create(['mode' => 'live', 'store_name' => 'Main store']);
        $pfx = config('catalog.prefix');

        [$linked, $orphan] = array_map(function (string $sn) use ($pfx) {
            $so = ShopeeOrder::create(['order_sn' => $sn, 'region' => 'PH', 'status' => 'READY_TO_SHIP',
                'raw' => ['currency' => 'PHP', 'total_amount' => 100, 'buyer_username' => 'b']]);
            app(ShopeeCatalogOrderSync::class)->setSkipStockAdjust(true)->sync($so);
            $id = (int) Order::where('marketplace_order_id', $sn)->value('order_id');
            DB::table($pfx . 'order')->where('order_id', $id)->update(['store_id' => 0, 'store_name' => '']);
            return $id;
        }, ['OLD-1', 'GONE-1']);
        ShopeeOrder::withoutGlobalScopes()->where('order_sn', 'GONE-1')->delete();

        (new \StampStoreOnMarketplaceOrders)->up();

        $this->assertSame($store->id, (int) DB::table($pfx . 'order')->where('order_id', $linked)->value('store_id'));
        $this->assertSame('Main store', DB::table($pfx . 'order')->where('order_id', $linked)->value('store_name'));
        $this->assertSame(0, (int) DB::table($pfx . 'order')->where('order_id', $orphan)->value('store_id'));
    }
}
