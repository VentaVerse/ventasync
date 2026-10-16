<?php

namespace Tests\Feature;

use App\Models\Catalog\Order;
use Extensions\Shopee\Models\ShopeeOrder;
use Extensions\Shopee\Models\ShopeeOrderProduct;
use Extensions\Shopee\Models\ShopeeStore;
use Extensions\Shopee\Services\ShopeeSalesOrderSync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class OrderSnapshotTest extends TestCase
{
    use RefreshDatabase;

    private int $productId;

    private int $valueId;

    protected function setUp(): void
    {
        parent::setUp();

        $lang = (int) config('catalog.default_language_id');
        $this->productId = (int) DB::table('products')->insertGetId([
            'sku' => '3PDT', 'quantity' => 10, 'price' => 300, 'cost' => 100, 'status' => 1, 'image' => '',
            'weight' => 1, 'length' => 1, 'width' => 1, 'height' => 1, 'brand_id' => 0,
            'date_added' => now(), 'date_modified' => now(),
        ]);
        DB::table('product_descriptions')->insert([
            'product_id' => $this->productId, 'language_id' => $lang,
            'name' => '3PDT Footswitch', 'description' => '', 'meta_title' => '', 'meta_description' => '', 'meta_keyword' => '', 'tag' => '',
        ]);
        $typeId = (int) DB::table('variation_types')->insertGetId(['type' => 'select', 'sort_order' => 0]);
        DB::table('variation_type_descriptions')->insert(['variation_type_id' => $typeId, 'language_id' => $lang, 'name' => 'Set']);
        $this->valueId = (int) DB::table('variation_values')->insertGetId(['variation_type_id' => $typeId, 'image' => '', 'sort_order' => 0]);
        DB::table('variation_value_descriptions')->insert(['variation_value_id' => $this->valueId, 'language_id' => $lang, 'variation_type_id' => $typeId, 'name' => '5 pcs']);
        $ptId = (int) DB::table('product_variation_types')->insertGetId(['product_id' => $this->productId, 'variation_type_id' => $typeId, 'value' => '', 'required' => 1]);
        DB::table('product_variation_values')->insert([
            'product_variation_type_id' => $ptId, 'product_id' => $this->productId, 'variation_type_id' => $typeId,
            'variation_value_id' => $this->valueId, 'sku' => '3PDT-5', 'quantity' => 10, 'subtract_stock' => 1,
            'price' => 0, 'price_prefix' => '+', 'points' => 0, 'points_prefix' => '+', 'weight' => 0, 'weight_prefix' => '+',
        ]);
    }

    private function shopeeOrder(string $status, array $lines): ShopeeOrder
    {
        $order = ShopeeOrder::query()->where('order_sn', 'SP-SNAP')->first()
            ?? ShopeeOrder::create(['order_sn' => 'SP-SNAP', 'region' => 'PH', 'status' => $status,
                'raw' => ['currency' => 'PHP', 'total_amount' => 300, 'buyer_username' => 'b']]);
        $order->update(['status' => $status]);
        ShopeeOrderProduct::query()->where('shopee_order_id', $order->id)->delete();
        foreach ($lines as $sku => $text) {
            ShopeeOrderProduct::create(['shopee_order_id' => $order->id, 'sku' => $sku, 'name' => '3PDT Footswitch',
                'variation' => $text, 'quantity' => 1, 'price' => 300]);
        }

        return $order->fresh();
    }

    private function sync(ShopeeOrder $order): Order
    {
        app(ShopeeSalesOrderSync::class)->setSkipStockAdjust(true)->sync($order->fresh());

        return Order::where('channel', 'shopee')->where('channel_order_id', 'SP-SNAP')->firstOrFail();
    }

    public function test_a_resync_keeps_the_cost_variation_store_and_currency_the_order_was_saved_with(): void
    {
        $store = ShopeeStore::create(['mode' => 'live', 'store_name' => 'Main store']);
        $order = $this->sync($this->shopeeOrder('READY_TO_SHIP', ['3PDT-5' => '5 pcs']));

        $line = DB::table('order_products')->where('order_id', $order->order_id)->first();
        $this->assertEquals(100, (float) $line->cost);
        $this->assertSame([['Set', '5 pcs']], DB::table('order_variations')->where('order_product_id', $line->order_product_id)
            ->get(['name', 'value'])->map(fn ($v) => [$v->name, $v->value])->all());
        $this->assertSame('Main store', $order->store_name);
        $this->assertSame('PHP', $order->currency_code);

        DB::table('products')->where('product_id', $this->productId)->update(['cost' => 200]);
        DB::table('variation_value_descriptions')->where('variation_value_id', $this->valueId)->update(['name' => '5 pieces']);
        $store->update(['store_name' => 'Renamed store']);
        DB::table('orders')->where('order_id', $order->order_id)->update(['currency_code' => 'PHP']);

        $order = $this->sync($this->shopeeOrder('COMPLETED', ['3PDT-5' => '5 pcs']));

        $line = DB::table('order_products')->where('order_id', $order->order_id)->first();
        $this->assertEquals(100, (float) $line->cost, 'a cost change after the sale never reaches the order');
        $this->assertSame([['Set', '5 pcs']], DB::table('order_variations')->where('order_product_id', $line->order_product_id)
            ->get(['name', 'value'])->map(fn ($v) => [$v->name, $v->value])->all());
        $this->assertSame('Main store', $order->store_name, 'a store rename never reaches an order already saved');

        $this->assertSame([['name' => 'Set', 'value' => '5 pcs']], \App\Support\OrderSnapshot::savedPairs($order->order_id, '3PDT-5'));
        $this->assertSame('Set:5 pcs', \App\Support\LineVariation::label('3PDT-5', '5 pcs', $order->order_id));

        $shopeeOrder = ShopeeOrder::query()->where('order_sn', 'SP-SNAP')->first();
        $app = (new \Extensions\Shopee\ShopeeExtension($this->app))->mobileShowResponse($shopeeOrder->id);
        $this->assertSame('Set:5 pcs', $app['products'][0]['variation']);
        $this->assertSame('Main store', $app['store_name']);
    }

    public function test_a_cost_left_blank_is_filled_once_the_sku_matches_and_lines_still_follow_the_marketplace(): void
    {
        ShopeeStore::create(['mode' => 'live', 'store_name' => 'Main store']);
        $order = $this->sync($this->shopeeOrder('READY_TO_SHIP', ['3PDT-9' => '9 pcs', '3PDT-5' => '5 pcs']));
        $this->assertEquals(0, (float) DB::table('order_products')->where('order_id', $order->order_id)->where('sku', '3PDT-9')->value('cost'));

        DB::table('product_variation_values')->where('sku', '3PDT-5')->update(['sku' => '3PDT-9']);
        $order = $this->sync($this->shopeeOrder('SHIPPED', ['3PDT-9' => '9 pcs']));

        $this->assertSame(['3PDT-9'], DB::table('order_products')->where('order_id', $order->order_id)->pluck('sku')->all(),
            'a line the marketplace dropped is dropped');
        $this->assertEquals(100, (float) DB::table('order_products')->where('order_id', $order->order_id)->value('cost'),
            'a blank cost is filled; a saved one is never changed');
    }

    public function test_keep_on_order_holds_the_store_name_and_currency_of_a_saved_order(): void
    {
        $kept = \App\Support\OrderSnapshot::keepOnOrder(
            (object) ['store_name' => 'Old', 'currency_code' => 'PHP'],
            ['store_name' => 'New', 'currency_id' => 9, 'currency_code' => 'PHP', 'currency_value' => 0.017, 'order_status_id' => 5]
        );
        $this->assertSame(['order_status_id' => 5], $kept);

        $corrected = \App\Support\OrderSnapshot::keepOnOrder(
            (object) ['store_name' => 'Old', 'currency_code' => 'USD'],
            ['currency_id' => 1, 'currency_code' => 'PHP', 'currency_value' => 1.0]
        );
        $this->assertSame(['currency_id' => 1, 'currency_code' => 'PHP', 'currency_value' => 1.0], $corrected, 'the store correcting its own currency is followed');

        $first = \App\Support\OrderSnapshot::keepOnOrder((object) ['store_name' => '', 'currency_code' => ''], ['store_name' => 'New', 'currency_code' => 'PHP']);
        $this->assertSame(['store_name' => 'New', 'currency_code' => 'PHP'], $first, 'a blank stamp is filled');
        $this->assertSame(['store_name' => 'New'], \App\Support\OrderSnapshot::keepOnOrder(null, ['store_name' => 'New']));
    }
}
