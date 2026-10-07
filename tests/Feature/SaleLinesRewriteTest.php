<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\MakesCoreOrders;
use Tests\TestCase;

class SaleLinesRewriteTest extends TestCase
{
    use RefreshDatabase;
    use MakesCoreOrders;

    protected function setUp(): void
    {
        parent::setUp();
        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
    }

    public function test_rewriting_replaces_only_the_sale_lines(): void
    {
        $pfx = (string) config('catalog.prefix');
        $store = DB::table('shopee_settings')->insertGetId(['mode' => 'live', 'store_name' => 'Main store', 'created_at' => now(), 'updated_at' => now()]);
        $orderId = $this->coreOrder(['marketplace_source' => 'shopee', 'store_id' => $store, 'total' => 1438]);
        $this->coreOrderLine($orderId, 1438, 900, 1);
        DB::table($pfx . 'order_total')->insert([
            ['order_id' => $orderId, 'code' => 'sub_total', 'title' => 'Sub-Total', 'value' => 1438, 'sort_order' => 1],
            ['order_id' => $orderId, 'code' => 'escrow_amount', 'title' => 'Escrow Amount', 'value' => 1141.26, 'sort_order' => 2],
            ['order_id' => $orderId, 'code' => 'total', 'title' => 'Total', 'value' => 1438, 'sort_order' => 3],
            ['order_id' => $orderId, 'code' => 'marketplace_commission', 'title' => 'Commission', 'value' => -151, 'sort_order' => 91],
        ]);
        DB::table('shopee_orders')->insert([
            'shopee_setting_id' => $store, 'region' => 'PH', 'order_sn' => 'SP-REWRITE', 'catalog_order_id' => $orderId,
            'status' => 'COMPLETED', 'raw' => '{}',
            'fees' => json_encode(['order_income' => [
                'order_original_price' => 1438, 'order_selling_price' => 1438, 'final_product_protection' => 54, 'buyer_total_amount' => 1492,
            ]]),
        ]);
        $sale = fn () => DB::table($pfx . 'order_total')->where('order_id', $orderId)->where('code', 'not like', 'marketplace_%')
            ->orderBy('sort_order')->get(['title', 'value'])->map(fn ($r) => [$r->title, round((float) $r->value, 2)])->all();

        $this->artisan('orders:rewrite-sale-lines')->assertSuccessful();
        $this->assertSame([['Sub-Total', 1438.0], ['Escrow Amount', 1141.26], ['Total', 1438.0]], $sale(), 'nothing changes without --apply');

        $this->artisan('orders:rewrite-sale-lines', ['--apply' => true])->assertSuccessful();

        $this->assertSame([
            ['Merchandise Subtotal', 1438.0], ['Shipping Fee', 0.0], ['Shopee Voucher', 0.0], ['Seller Voucher', 0.0],
            ['Product protection', 54.0], ['Total Buyer Payment', 1492.0],
        ], $sale());
        $this->assertSame(-151.0, round((float) DB::table($pfx . 'order_total')->where('order_id', $orderId)->where('code', 'marketplace_commission')->value('value'), 2), 'fee rows stay');
        $this->assertSame(900.0, round((float) DB::table($pfx . 'order_product')->where('order_id', $orderId)->value('cost'), 2), 'cost of goods is not re-read');
        $this->assertSame(1438.0, round((float) DB::table($pfx . 'order')->where('order_id', $orderId)->value('total'), 2), 'order.total is untouched');
    }
}
