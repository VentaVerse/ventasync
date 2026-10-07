<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokOrderStatusMap;
use Extensions\tiktok\Models\TikTokReturn;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TiktokReturnsTest extends TestCase
{
    use RefreshDatabase;

    private array $sentCalls = [];
    public array $callResponses = [];
    private int $groupSeq = 0;

    private TikTokSetting $tiktokStore;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        $this->tiktokStore = TikTokSetting::create(['mode' => 'production', 'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't', 'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(3)]);

        $test = $this;
        $fake = new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            { return $this->test->answer('POST', $path, $body); }
            public function get(string $appKey, string $appSecret, string $accessToken, string $path, array $extraParams = [], ?string $shopCipher = null): array
            { return $this->test->answer('GET', $path, $extraParams); }
        };
        $this->app->instance(TikTokClient::class, $fake);
    }

    public function answer(string $method, string $path, array $body): array
    {
        $this->sentCalls[] = ['method' => $method, 'path' => $path, 'body' => $body];
        $answer = $this->callResponses[$method . ' ' . $path] ?? null;

        return $answer instanceof \Closure ? $answer($body) : ($answer ?? ['ok' => false, 'status' => 500, 'body' => ['code' => -1, 'message' => 'unexpected ' . $path]]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'TikTok returns ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/order', 'view_tiktok/order'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function sampleReturn(array $overrides = []): array
    {
        return array_merge([
            'return_id' => 'ret-1', 'order_id' => 'ord-1', 'return_type' => 'RETURN_AND_REFUND',
            'return_status' => 'RETURN_OR_REFUND_REQUEST_PENDING', 'return_reason_text' => 'Wrong size',
            'refund_amount' => ['currency' => 'PHP', 'refund_total' => '450.00'],
            'create_time' => now()->subDay()->timestamp, 'update_time' => now()->timestamp,
            'return_tracking_number' => 'RTN123',
            'return_line_items' => [['seller_sku' => 'VP-RED', 'product_name' => 'Variation push product', 'sku_name' => 'Red', 'return_quantity' => 1,
                'product_image' => ['url' => 'https://img.example/red.jpg'], 'refund_amount' => ['refund_total' => '450.00', 'currency' => 'PHP']]],
        ], $overrides);
    }

    public function test_the_returns_job_stores_returns_over_its_own_window(): void
    {
        $this->callResponses['POST /return_refund/202309/returns/search'] = function (array $body) {
            $this->assertArrayHasKey('create_time_ge', $body, 'the job asks over a window');
            $this->assertArrayNotHasKey('create_time_lt', $body, 'up to now, never a cut-off before now');
            $this->assertEqualsWithDelta(now()->subDays(5)->startOfDay()->timestamp, $body['create_time_ge'], 86400);

            return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
                'data' => ['return_orders' => [$this->sampleReturn()]]]];
        };
        TikTokSetting::query()->update(['sync_last_days_returns' => 5]);

        $this->artisan('tiktok:sync-orders --returns')->assertExitCode(0);

        $this->assertSame(1, TikTokReturn::count());
        $this->assertCount(1, $this->sentCalls, 'returns only: the orders search was not called');
        $this->assertSame('/return_refund/202309/returns/search', $this->sentCalls[0]['path']);

        $this->sentCalls = [];
        $this->callResponses['POST /order/202309/orders/search'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'data' => ['orders' => []]]];
        $this->artisan('tiktok:sync-orders --no-returns')->assertExitCode(0);
        $this->assertSame(['/order/202309/orders/search'], array_column($this->sentCalls, 'path'));
    }

    public function test_fetch_stores_returns_and_the_page_lists_them_by_state(): void
    {
        $this->callResponses['POST /return_refund/202309/returns/search'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['return_orders' => [$this->sampleReturn(), $this->sampleReturn(['return_id' => 'ret-2', 'order_id' => 'ord-2', 'return_status' => 'RETURN_OR_REFUND_REQUEST_COMPLETE'])]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.orders.fetch_returns'))
            ->assertRedirect(route('ext.tiktok.orders.returns'))
            ->assertSessionHas('tiktok_returns_last_result');

        $this->assertSame(2, TikTokReturn::count());
        $r1 = TikTokReturn::where('return_id', 'ret-1')->first();
        $this->assertSame('ord-1', $r1->order_id);
        $this->assertSame(450.0, $r1->refund_amount);
        $this->assertSame('VP-RED', $r1->items[0]['seller_sku']);

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.orders.returns'));
        $r->assertOk();
        $r->assertSee('Waiting for your answer');
        $r->assertSee('Refunded');
        $r->assertSee('Variation push product');
        $r->assertSee('RTN123');

        $only = $this->actingAs($this->manager())->get(route('ext.tiktok.orders.returns', ['tab' => 'REFUNDED']));
        $only->assertOk();
        $only->assertSee('ret-2');
        $only->assertDontSee('ret-1');
    }

    public function test_a_mapped_return_status_moves_the_linked_sales_order(): void
    {
        $pfx = (string) config('catalog.prefix');
        $catalogOrderId = DB::table($pfx . 'order')->insertGetId($this->orderColumnDefaults() + ['order_status_id' => 5, 'total' => 450]);
        DB::table('tiktok_orders')->insert(['tiktok_setting_id' => $this->tiktokStore->id, 'region' => 'PH', 'order_id' => 'ord-1', 'status' => 'COMPLETED', 'catalog_order_id' => $catalogOrderId, 'raw' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        TikTokOrderStatusMap::create(['tiktok_status' => 'RETURN_OR_REFUND_REQUEST_COMPLETE', 'context' => 'return', 'order_status_id' => 11]);
        $this->callResponses['POST /return_refund/202309/returns/search'] = ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success',
            'data' => ['return_orders' => [$this->sampleReturn(['return_status' => 'RETURN_OR_REFUND_REQUEST_COMPLETE'])]]]];

        $this->actingAs($this->manager())->post(route('ext.tiktok.orders.fetch_returns'));

        $this->assertSame(11, (int) DB::table($pfx . 'order')->where('order_id', $catalogOrderId)->value('order_status_id'));
        $this->assertSame(1, DB::table($pfx . 'order_history')->where('order_id', $catalogOrderId)->where('order_status_id', 11)->count());
        $this->assertStringContainsString('1 sales order moved by the return status map', session('tiktok_returns_last_result')['message']);
    }

    public function test_the_settings_page_saves_the_return_status_map(): void
    {
        $group = UserGroup::create(['name' => 'TikTok settings ' . (++$this->groupSeq)]);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_tiktok/settings', 'view_tiktok/settings'])->pluck('id')->all());
        $admin = User::factory()->create(['user_group_id' => $group->id]);

        $this->actingAs($admin)->post(route('ext.tiktok.return_status_map'), ['map' => ['RETURN_OR_REFUND_REQUEST_COMPLETE' => 11, 'REJECTED' => '']])
            ->assertRedirect();

        $this->assertSame(11, (int) TikTokOrderStatusMap::where('context', 'return')->where('tiktok_status', 'RETURN_OR_REFUND_REQUEST_COMPLETE')->value('order_status_id'));
        $this->assertSame(0, TikTokOrderStatusMap::where('context', 'return')->where('tiktok_status', 'REJECTED')->count());
    }

    private function orderColumnDefaults(): array
    {
        return [
            'invoice_no' => 0, 'invoice_prefix' => '', 'store_id' => 0, 'store_name' => '',
            'store_url' => '', 'customer_id' => 0, 'customer_group_id' => 0,
            'firstname' => '', 'lastname' => '', 'email' => '', 'telephone' => '',
            'fax' => '', 'custom_field' => '',
            'payment_firstname' => '', 'payment_lastname' => '', 'payment_company' => '',
            'payment_address_1' => '', 'payment_address_2' => '', 'payment_city' => '',
            'payment_postcode' => '', 'payment_country' => '', 'payment_country_id' => 0,
            'payment_zone' => '', 'payment_zone_id' => 0, 'payment_address_format' => '',
            'payment_custom_field' => '', 'payment_method' => '', 'payment_code' => '',
            'shipping_firstname' => '', 'shipping_lastname' => '', 'shipping_company' => '',
            'shipping_address_1' => '', 'shipping_address_2' => '', 'shipping_city' => '',
            'shipping_postcode' => '', 'shipping_country' => '', 'shipping_country_id' => 0,
            'shipping_zone' => '', 'shipping_zone_id' => 0, 'shipping_address_format' => '',
            'shipping_custom_field' => '', 'shipping_method' => '', 'shipping_code' => '',
            'shipping_cost' => 0, 'comment' => '', 'total' => 0,
            'affiliate_id' => 0, 'commission' => 0, 'marketing_id' => 0, 'tracking' => '',
            'language_id' => (int) config('catalog.default_language_id', 1),
            'currency_id' => 0, 'currency_code' => 'PHP', 'currency_value' => 1,
            'ip' => '', 'forwarded_ip' => '', 'user_agent' => '', 'accept_language' => '',
            'courier_id' => 0, 'tracking_number' => '', 'oe_import' => 0,
            'order_status_id' => 1,
            'date_added' => now(), 'date_modified' => now(),
        ];
    }
}
