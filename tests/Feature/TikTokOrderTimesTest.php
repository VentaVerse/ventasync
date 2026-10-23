<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Catalog\Order;
use Extensions\TikTok\Models\TikTokOrder;
use Extensions\TikTok\TikTokExtension;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TikTokOrderTimesTest extends TestCase
{
    use RefreshDatabase;

    private const PLACED = 1784151791;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(TikTokExtension::class);

        config(['app.timezone' => 'Asia/Manila']);
        date_default_timezone_set('Asia/Manila');
    }

    protected function tearDown(): void
    {
        date_default_timezone_set('UTC');
        parent::tearDown();
    }

    public function test_every_unix_time_becomes_a_date_in_the_store_timezone(): void
    {
        $found = 0;
        foreach (['app', 'extensions'] as $root) {
            $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(base_path($root), \FilesystemIterator::SKIP_DOTS));
            foreach ($files as $file) {
                if ($file->getExtension() !== 'php') {
                    continue;
                }
                $source = (string) file_get_contents($file->getPathname());
                preg_match_all('/createFromTimestamp(?:Ms)?\((?!0\))[^;]*/', $source, $calls);
                foreach ($calls[0] as $call) {
                    $found++;
                    $this->assertStringContainsString("config('app.timezone')", $call, $file->getPathname() . ' turns a Unix time into a UTC date');
                }
            }
        }
        $this->assertGreaterThan(0, $found, 'no timestamp conversion found to check');
    }

    public function test_the_repair_moves_saved_utc_times_to_the_store_timezone_once(): void
    {
        $tiktok = TikTokOrder::create([
            'order_id' => 'TT5001',
            'status' => 'AWAITING_SHIPMENT',
            'order_created_at' => '2026-07-15 21:43:11',
            'order_updated_at' => '2026-07-15 21:43:11',
            'raw' => ['id' => 'TT5001', 'create_time' => self::PLACED, 'update_time' => self::PLACED],
        ]);
        $sale = $this->sale('TT5001', '2026-07-15 21:43:11');
        $handEdited = $this->sale('TT5002', '2026-07-01 09:00:00');

        $this->artisan('tiktok:fix-order-times')->assertSuccessful();
        $this->assertSame('2026-07-15 21:43:11', $tiktok->fresh()->order_created_at->format('Y-m-d H:i:s'), 'a dry run writes nothing');

        $this->artisan('tiktok:fix-order-times', ['--apply' => true])
            ->expectsOutputToContain('Corrected 1 TikTok orders, 2 sales order dates and 0 returns.')
            ->assertSuccessful();

        $this->assertSame('2026-07-16 05:43:11', $tiktok->fresh()->order_created_at->format('Y-m-d H:i:s'));
        $this->assertSame('2026-07-16 05:43:11', (string) DB::table('orders')->where('order_id', $sale)->value('date_added'));
        $this->assertSame('2026-07-01 09:00:00', (string) DB::table('orders')->where('order_id', $handEdited)->value('date_added'));

        $this->artisan('tiktok:fix-order-times', ['--apply' => true])
            ->expectsOutputToContain('Corrected 0 TikTok orders, 0 sales order dates and 0 returns.')
            ->assertSuccessful();
    }

    private function sale(string $channelOrderId, string $at): int
    {
        return (int) Order::create([
            'store_id' => 0, 'store_name' => '', 'firstname' => 'Ada', 'lastname' => 'Lovelace', 'payment_method' => '', 'shipping_method' => '', 'courier_id' => 0, 'tracking_number' => '',
            'email' => '', 'telephone' => '',
            'payment_firstname' => 'Ada', 'payment_lastname' => 'Lovelace', 'payment_company' => '',
            'payment_address_1' => '', 'payment_address_2' => '', 'payment_city' => '', 'payment_postcode' => '',
            'payment_country' => '', 'payment_zone' => '', 'payment_cost' => 0,
            'shipping_firstname' => 'Ada', 'shipping_lastname' => 'Lovelace', 'shipping_company' => '',
            'shipping_address_1' => '', 'shipping_address_2' => '', 'shipping_city' => '', 'shipping_postcode' => '',
            'shipping_country' => '', 'shipping_zone' => '', 'shipping_cost' => 0,
            'customer_note' => '', 'total' => 100, 'foreign_total' => null, 'extra_cost' => 0, 'order_status_id' => 1,
            'currency_id' => 1, 'currency_code' => 'PHP', 'currency_value' => 1, 'ip' => '',
            'channel' => 'tiktok', 'channel_order_id' => $channelOrderId,
            'date_added' => $at, 'date_modified' => $at, 'track_payments' => true,
        ])->order_id;
    }
}
