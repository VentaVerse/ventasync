<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaOrder;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LazadaOrderActionsTest extends TestCase
{
    use RefreshDatabase;

    public array $sent = [];

    public array $answers = [];

    private LazadaSetting $store;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        $this->store = LazadaSetting::query()->create([
            'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r',
        ]);

        $test = $this;
        $this->app->instance(LazadaClient::class, new class($test) extends LazadaClient {
            public function __construct(private $test) {}

            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                return $this->test->answer('POST', $apiPath, $params);
            }

            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                return $this->test->answer('GET', $apiPath, $params);
            }

            public function sign(string $apiPath, array $params, string $appSecret): string
            {
                return 'test-sign';
            }
        });

        $this->answers['GET /order/items/get'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => [
            ['order_item_id' => 1001, 'package_id' => 'FP1'],
            ['order_item_id' => 1002, 'package_id' => 'FP1'],
        ]]];
    }

    public function answer(string $method, string $path, array $params): array
    {
        $this->sent[] = ['method' => $method, 'path' => $path, 'params' => $params];
        $answer = $this->answers[$method . ' ' . $path] ?? null;

        return $answer instanceof \Closure
            ? $answer($params)
            : ($answer ?? ['ok' => false, 'status' => 500, 'body' => ['code' => '500', 'message' => 'unexpected ' . $method . ' ' . $path]]);
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Lazada orders ' . uniqid()]);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_lazada/order', 'view_lazada/order'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function order(string $status = 'pending'): LazadaOrder
    {
        return LazadaOrder::query()->create([
            'lazada_setting_id' => $this->store->id, 'region' => 'ph', 'order_id' => '555001',
            'status' => $status, 'raw' => json_encode(['order_id' => '555001', 'package_id' => 'FP1']),
        ]);
    }

    private function calls(string $path): array
    {
        return array_values(array_filter($this->sent, fn ($c) => $c['path'] === $path));
    }

    public function test_cancel_is_one_request_for_the_whole_order_with_the_chosen_reason(): void
    {
        $order = $this->order();
        $this->answers['GET /order/reverse/cancel/create'] = ['ok' => true, 'status' => 200,
            'body' => ['code' => '0', 'data' => ['tip_type' => 'warn', 'tip_content' => 'The buyer will be notified.']]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.orders.cancel', ['store' => $this->store->id, 'orderId' => '555001']), ['reason_id' => 10000021])
            ->assertRedirect()
            ->assertSessionHas('lazada_orders_last_result', fn ($r) => $r['ok'] === true);

        $calls = $this->calls('/order/reverse/cancel/create');
        $this->assertCount(1, $calls, 'one request, not one per item');
        $this->assertSame('GET', $calls[0]['method']);
        $this->assertSame('555001', $calls[0]['params']['order_id']);
        $this->assertSame('["1001","1002"]', $calls[0]['params']['order_item_id_list']);
        $this->assertSame('10000021', $calls[0]['params']['reason_id']);
        $this->assertSame([], $this->calls('/order/cancel'), 'the undocumented per-item cancel is gone');

        $this->assertSame('canceled', $order->fresh()->status);
    }

    public function test_a_refused_cancel_says_why_and_leaves_the_order(): void
    {
        $order = $this->order();
        $this->answers['GET /order/reverse/cancel/create'] = ['ok' => true, 'status' => 200,
            'body' => ['code' => '0', 'data' => ['tip_type' => 'error', 'tip_content' => 'This order has already shipped.']]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.orders.cancel', ['store' => $this->store->id, 'orderId' => '555001']), ['reason_id' => 10000021])
            ->assertRedirect()
            ->assertSessionHas('lazada_orders_last_result', fn ($r) => $r['ok'] === false
                && str_contains($r['message'], 'This order has already shipped.'));

        $this->assertSame('pending', $order->fresh()->status);

        $this->answers['GET /order/reverse/cancel/create'] = ['ok' => true, 'status' => 200,
            'body' => ['code' => '104', 'message' => 'E0104: reason is empty or invalid']];
        $this->actingAs($this->manager())
            ->post(route('ext.lazada.orders.cancel', ['store' => $this->store->id, 'orderId' => '555001']), ['reason_id' => 1])
            ->assertSessionHas('lazada_orders_last_result', fn ($r) => $r['ok'] === false && str_contains($r['message'], 'E0104'));
    }

    public function test_repack_sends_the_packages_and_reads_each_package_answer(): void
    {
        $this->order('ready_to_ship');
        $this->answers['POST /order/package/repack'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0',
            'result' => ['success' => true, 'data' => ['packages' => [['package_id' => 'FP1', 'item_err_code' => '0', 'msg' => '', 'retry' => false]]]]]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.orders.recreate_package', ['store' => $this->store->id, 'orderId' => '555001']))
            ->assertRedirect()
            ->assertSessionHas('lazada_orders_last_result', fn ($r) => $r['ok'] === true);

        $calls = $this->calls('/order/package/repack');
        $this->assertCount(1, $calls);
        $this->assertSame('{"packages":[{"package_id":"FP1"}]}', $calls[0]['params']['rePackReq']);
        $this->assertSame([], $this->calls('/order/repack'), 'the undocumented repack is gone');
    }

    public function test_a_package_lazada_refuses_fails_the_repack_even_when_the_batch_says_success(): void
    {
        $this->order('ready_to_ship');
        $this->answers['POST /order/package/repack'] = ['ok' => true, 'status' => 200, 'body' => ['code' => '0',
            'result' => ['success' => true, 'data' => ['packages' => [['package_id' => 'FP1', 'item_err_code' => '600001', 'msg' => 'package not found']]]]]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.orders.recreate_package', ['store' => $this->store->id, 'orderId' => '555001']))
            ->assertSessionHas('lazada_orders_last_result', fn ($r) => $r['ok'] === false
                && str_contains($r['message'], 'package not found'));
    }

    public function test_no_lazada_removal_names_skus_by_the_retired_list(): void
    {
        foreach ([
            'extensions/lazada/Controllers/LazadaProductController.php',
            'extensions/lazada/Controllers/LazadaProductGroupController.php',
        ] as $file) {
            $src = file_get_contents(base_path($file));
            $this->assertIsString($src, $file);
            $this->assertStringNotContainsString("['seller_sku_list']", $src, "{$file} still sends seller_sku_list");
        }
    }
}
