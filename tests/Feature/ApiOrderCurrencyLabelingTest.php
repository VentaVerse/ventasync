<?php

namespace Tests\Feature;

use App\Models\ApiClient;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Catalog\Order;
use App\Models\User;
use App\Support\Money;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Fix 1: `total` is always the default currency now, but every order-facing
 * API endpoint used to echo `currency_code` straight from the row, which
 * names the as-transacted currency instead. A USD order returned
 * {"total": 308071.47, "currency_code": "USD"}, which reads as $308,071.47
 * when the figure is actually PHP308,071.47.
 *
 * These tests build their own USD and PHP orders through the real
 * order-creation route (the same one OrderControllerCurrencyTest exercises,
 * so `total` / `foreign_total` land exactly as production writes them), then
 * assert the corrected response shape on every endpoint that exposes an
 * order total: the external v1 API, the legacy mobile API, and the mobile
 * dashboard's recent-orders list.
 */
class ApiOrderCurrencyLabelingTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsOrderManagerWeb(): User
    {
        $group = UserGroup::create(['name' => 'Order Managers']);
        $permission = Permission::where('key', 'manage_sales/order')->firstOrFail();
        $group->permissions()->attach($permission->id);

        $user = User::factory()->create(['user_group_id' => $group->id]);
        $this->actingAs($user);

        return $user;
    }

    private function baseOrderPayload(array $overrides = []): array
    {
        return array_merge([
            'firstname' => 'Juan',
            'lastname' => 'Dela Cruz',
            'order_status_id' => 1,
            'products' => [
                [
                    'product_id' => 1,
                    'name' => 'Test Widget',
                    'model' => 'TW-1',
                    'quantity' => 1,
                    'price' => 100,
                    'cost' => 40,
                ],
            ],
        ], $overrides);
    }

    /** Creates a real USD order and a real PHP order via the web create route. */
    private function seedUsdAndPhpOrders(): array
    {
        $this->actingAsOrderManagerWeb();

        $rate = (float) DB::table('currencies')->where('code', 'USD')->value('exchange_rate');

        $usdPayload = $this->baseOrderPayload(['currency_code' => 'USD']);
        $usdPayload['products'][0]['price'] = 100;
        $this->post(route('orders.store'), $usdPayload)->assertRedirect(route('orders.index'));
        $usdOrder = Order::orderByDesc('order_id')->first();

        $phpPayload = $this->baseOrderPayload(['currency_code' => 'PHP']);
        $phpPayload['products'][0]['price'] = 500;
        $this->post(route('orders.store'), $phpPayload)->assertRedirect(route('orders.index'));
        $phpOrder = Order::orderByDesc('order_id')->first();

        // Sanity: this whole test suite is meaningless if the standardisation
        // this branch already landed did not actually happen.
        $this->assertNotNull($usdOrder->foreign_total);
        $this->assertEqualsWithDelta(100.0, (float) $usdOrder->foreign_total, 0.0001);
        $this->assertNull($phpOrder->foreign_total);

        return [$usdOrder, $phpOrder, $rate];
    }

    /**
     * ApiClient does not implement Authenticatable (only Sanctum's
     * HasApiTokens contract), so Sanctum::actingAs() - which calls the
     * guard's setUser() under a strict Authenticatable type hint - cannot
     * be used here. A real bearer token exercises the same Guard::__invoke()
     * path production traffic takes (Sanctum's RequestGuard resolves the
     * user without going through setUser()), so it is both the fix and the
     * more faithful test.
     */
    /**
     * Sanctum's guard checks `config('sanctum.guard', 'web')` BEFORE it ever
     * looks at the bearer token (see Laravel\Sanctum\Guard::__invoke) - so a
     * web session left behind by seedUsdAndPhpOrders()'s actingAs() calls
     * would otherwise outrank the bearer token entirely and resolve
     * `$request->user()` to the web User, not the ApiClient. Logging the web
     * guard out here is what makes the bearer token actually take effect.
     */
    private function bearerTokenWithScope(string $scope): string
    {
        \Illuminate\Support\Facades\Auth::guard('web')->logout();

        $client = ApiClient::create(['name' => 'Test Client', 'active' => true]);

        return $client->createToken('test', [$scope])->plainTextToken;
    }

    private function orderManagerApiUser(): User
    {
        $group = UserGroup::create(['name' => 'Order Managers API']);
        $permission = Permission::where('key', 'manage_sales/order')->firstOrFail();
        $group->permissions()->attach($permission->id);

        $user = User::factory()->create(['user_group_id' => $group->id]);
        Sanctum::actingAs($user, ['*']);

        return $user;
    }

    public function test_v1_orders_index_labels_currency_correctly(): void
    {
        [$usdOrder, $phpOrder] = $this->seedUsdAndPhpOrders();

        $token = $this->bearerTokenWithScope('orders:read');

        $response = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders?per_page=200');
        $response->assertOk();

        $rows = collect($response->json('data'));
        $usdRow = $rows->firstWhere('order_id', $usdOrder->order_id);
        $phpRow = $rows->firstWhere('order_id', $phpOrder->order_id);

        $this->assertNotNull($usdRow);
        $this->assertNotNull($phpRow);

        $this->assertSame(Money::defaultCode(), $usdRow['currency_code']);
        $this->assertSame('USD', $usdRow['transaction_currency']);
        $this->assertEqualsWithDelta(100.0, (float) $usdRow['transaction_total'], 0.0001);
        $this->assertEqualsWithDelta((float) $usdOrder->total, (float) $usdRow['total'], 0.0001);

        $this->assertSame(Money::defaultCode(), $phpRow['currency_code']);
        $this->assertNull($phpRow['transaction_currency']);
        $this->assertNull($phpRow['transaction_total']);
    }

    public function test_v1_order_show_labels_currency_correctly(): void
    {
        [$usdOrder, $phpOrder] = $this->seedUsdAndPhpOrders();

        $token = $this->bearerTokenWithScope('orders:read');

        $usdResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders/' . $usdOrder->order_id);
        $usdResponse->assertOk();
        $usdResponse->assertJsonPath('order.currency_code', Money::defaultCode());
        $usdResponse->assertJsonPath('order.transaction_currency', 'USD');
        $this->assertEqualsWithDelta(100.0, (float) $usdResponse->json('order.transaction_total'), 0.0001);

        $phpResponse = $this->withHeader('Authorization', 'Bearer ' . $token)
            ->getJson('/api/v1/orders/' . $phpOrder->order_id);
        $phpResponse->assertOk();
        $phpResponse->assertJsonPath('order.currency_code', Money::defaultCode());
        $phpResponse->assertJsonPath('order.transaction_currency', null);
        $phpResponse->assertJsonPath('order.transaction_total', null);
    }

    public function test_mobile_api_orders_index_and_show_label_currency_correctly(): void
    {
        [$usdOrder, $phpOrder] = $this->seedUsdAndPhpOrders();

        $this->orderManagerApiUser();

        $index = $this->getJson('/api/orders?per_page=200');
        $index->assertOk();

        $rows = collect($index->json('data'));
        $usdRow = $rows->firstWhere('order_id', $usdOrder->order_id);
        $phpRow = $rows->firstWhere('order_id', $phpOrder->order_id);

        $this->assertSame(Money::defaultCode(), $usdRow['currency_code']);
        $this->assertSame('USD', $usdRow['transaction_currency']);
        $this->assertEqualsWithDelta(100.0, (float) $usdRow['transaction_total'], 0.0001);

        $this->assertSame(Money::defaultCode(), $phpRow['currency_code']);
        $this->assertNull($phpRow['transaction_currency']);
        $this->assertNull($phpRow['transaction_total']);

        $show = $this->getJson('/api/orders/' . $usdOrder->order_id);
        $show->assertOk();
        $show->assertJsonPath('order.currency_code', Money::defaultCode());
        $show->assertJsonPath('order.transaction_currency', 'USD');
        $this->assertEqualsWithDelta(100.0, (float) $show->json('order.transaction_total'), 0.0001);
    }

    public function test_mobile_dashboard_recent_orders_label_currency_correctly(): void
    {
        [$usdOrder, $phpOrder] = $this->seedUsdAndPhpOrders();

        $this->orderManagerApiUser();

        $response = $this->getJson('/api/dashboard');
        $response->assertOk();
        $this->assertSame(Money::defaultCode(), $response->json('stats.currency_code'));

        $rows = collect($response->json('recent_orders'));
        $usdRow = $rows->firstWhere('order_id', $usdOrder->order_id);
        $phpRow = $rows->firstWhere('order_id', $phpOrder->order_id);

        $this->assertNotNull($usdRow, 'the just-created USD order must appear in the 5 most recent orders');
        $this->assertNotNull($phpRow, 'the just-created PHP order must appear in the 5 most recent orders');

        $this->assertSame(Money::defaultCode(), $usdRow['currency_code']);
        $this->assertSame('USD', $usdRow['transaction_currency']);
        $this->assertEqualsWithDelta(100.0, (float) $usdRow['transaction_total'], 0.0001);

        $this->assertSame(Money::defaultCode(), $phpRow['currency_code']);
        $this->assertNull($phpRow['transaction_currency']);
        $this->assertNull($phpRow['transaction_total']);
    }
}
