<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class TiktokPayoutSyncTest extends TestCase
{
    use RefreshDatabase;

    private array $sentCalls = [];

    public array $callResponses = [];

    private TikTokSetting $tiktokStore;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);

        $this->tiktokStore = TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's', 'access_token' => 't',
            'refresh_token' => 'r', 'shop_cipher' => 'c', 'expires_at' => now()->addDays(5), 'enabled' => true,
        ]);

        $test = $this;
        $this->app->instance(TikTokClient::class, new class($test) extends TikTokClient {
            public function __construct(private $test) {}

            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            {
                return $this->test->answer('POST', $path, $body);
            }

            public function get(string $appKey, string $appSecret, string $accessToken, string $path, array $extraParams = [], ?string $shopCipher = null): array
            {
                return $this->test->answer('GET', $path, $extraParams);
            }
        });

        $this->callResponses['POST /order/202309/orders/search'] = $this->ok(['orders' => []]);
    }

    public function answer(string $method, string $path, array $params): array
    {
        $this->sentCalls[] = ['method' => $method, 'path' => $path, 'params' => $params];
        $answer = $this->callResponses[$method . ' ' . $path] ?? null;

        return $answer instanceof \Closure
            ? $answer($params)
            : ($answer ?? ['ok' => false, 'status' => 500, 'body' => ['code' => -1, 'message' => 'unexpected ' . $path]]);
    }

    private function ok(array $data): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'request_id' => 'req', 'data' => $data]];
    }

    private function order(string $orderId, string $status, \DateTimeInterface $created, ?string $payout = null): void
    {
        DB::table('tiktok_orders')->insert([
            'tiktok_setting_id' => $this->tiktokStore->id, 'order_id' => $orderId, 'status' => $status,
            'order_created_at' => $created, 'order_updated_at' => $created, 'payout_status' => $payout,
            'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    private function payoutOf(string $orderId): array
    {
        $row = DB::table('tiktok_orders')->where('order_id', $orderId)->first(['payout_status', 'paid_at']);

        return [$row->payout_status, $row->paid_at];
    }

    private function statement(string $id, string $paymentStatus, int $statementTime, ?int $paymentTime = null): array
    {
        return array_filter([
            'id' => $id, 'statement_time' => $statementTime, 'settlement_amount' => '100', 'currency' => 'PHP',
            'revenue_amount' => '200', 'fee_amount' => '-30', 'adjustment_amount' => '0',
            'payment_status' => $paymentStatus, 'payment_id' => 'pay-' . $id, 'payment_time' => $paymentTime,
        ], fn ($v) => $v !== null);
    }

    private function orderLine(string $orderId): array
    {
        return ['id' => 'trx-' . $orderId, 'type' => 'ORDER', 'order_id' => $orderId, 'order_create_time' => now()->subDays(10)->timestamp,
            'settlement_amount' => '130', 'revenue_amount' => '200'];
    }

    public function test_each_order_takes_the_payout_of_its_statement(): void
    {
        $paidAt = now()->subDays(3)->startOfDay()->addHours(9)->timestamp;
        $this->order('A', 'COMPLETED', now()->subDays(20));
        $this->order('B', 'DELIVERED', now()->subDays(12));
        $this->order('C', 'COMPLETED', now()->subDays(15));
        $this->order('D', 'COMPLETED', now()->subDays(2));
        $this->order('E', 'CANCELLED', now()->subDays(20));
        $this->order('F', 'COMPLETED', now()->subDays(30), 'Paid');

        $this->callResponses['GET /finance/202309/statements'] = function (array $params) use ($paidAt) {
            $this->assertSame('statement_time', $params['sort_field'], 'sort_field is required by the API');
            $this->assertSame(100, $params['page_size']);
            $this->assertGreaterThanOrEqual(now()->subDays(45)->timestamp - 5, $params['statement_time_ge'], 'a scheduled run reads 45 days');

            return empty($params['page_token'])
                ? $this->ok(['next_page_token' => 'page-2', 'statements' => [
                    $this->statement('S1', 'PAID', now()->subDays(5)->startOfDay()->timestamp, $paidAt),
                    $this->statement('S2', 'FAILED', now()->subDays(4)->startOfDay()->timestamp),
                ]])
                : $this->ok(['next_page_token' => '', 'statements' => [
                    $this->statement('S3', 'PROCESSING', now()->subDays(1)->startOfDay()->timestamp),
                ]]);
        };
        $this->callResponses['GET /finance/202501/statements/S1/statement_transactions'] = function (array $params) {
            $this->assertSame('order_create_time', $params['sort_field'], 'sort_field is required by the API');

            return empty($params['page_token'])
                ? $this->ok(['id' => 'S1', 'status' => 'SETTLED', 'next_page_token' => 'more', 'transactions' => [
                    $this->orderLine('A'),
                    ['id' => 'adj-1', 'type' => 'PLATFORM_COMMISSION_ADJUSTMENT', 'adjustment_order_id' => 'C', 'adjustment_amount' => '-5'],
                ]])
                : $this->ok(['id' => 'S1', 'status' => 'SETTLED', 'next_page_token' => '', 'transactions' => [$this->orderLine('somebody-else')]]);
        };
        $this->callResponses['GET /finance/202501/statements/S2/statement_transactions'] = $this->ok(['transactions' => [$this->orderLine('C')]]);
        $this->callResponses['GET /finance/202501/statements/S3/statement_transactions'] = $this->ok(['transactions' => [$this->orderLine('B')]]);

        $this->artisan('tiktok:sync-payouts')
            ->expectsOutputToContain('Payouts: 1 paid, 1 releasing, 1 payment failed, from 3 statements; 1 not settled yet.')
            ->assertExitCode(0);

        [$status, $at] = $this->payoutOf('A');
        $this->assertSame('Paid', $status);
        $this->assertSame(date('Y-m-d H:i:s', $paidAt), (string) $at, 'paid at the statement\'s payment time');

        $this->assertSame(['Releasing', null], $this->payoutOf('B'), 'a statement still processing is releasing');
        $this->assertSame(['Payment failed', null], $this->payoutOf('C'), 'an adjustment line is not the settlement');
        $this->assertSame([null, null], $this->payoutOf('D'), 'not settled yet: left alone');
        $this->assertSame([null, null], $this->payoutOf('E'), 'a cancelled order is never read');
        $this->assertSame('Paid', $this->payoutOf('F')[0]);

        $this->assertCount(2, array_filter($this->sentCalls, fn ($c) => $c['path'] === '/finance/202501/statements/S1/statement_transactions'));

        $this->assertSame(6, DB::table('tiktok_api_logs')->where('pack', 'payout-sync')->where('ok', 1)->count());
    }

    public function test_a_releasing_order_turns_paid_when_its_statement_is_paid(): void
    {
        $this->order('B', 'COMPLETED', now()->subDays(12), 'Releasing');
        DB::table('tiktok_orders')->where('order_id', 'B')->update(['fees' => json_encode(['commission' => 10, 'revenue' => 100, 'settlement_amount' => 90])]);
        $this->callResponses['GET /finance/202309/statements'] = $this->ok(['statements' => [
            $this->statement('S3', 'PAID', now()->subDays(1)->startOfDay()->timestamp, now()->subHours(3)->timestamp),
        ]]);
        $this->callResponses['GET /finance/202501/statements/S3/statement_transactions'] = $this->ok(['transactions' => [$this->orderLine('B')]]);

        $this->artisan('tiktok:sync-payouts')->assertExitCode(0);

        $this->assertSame('Paid', $this->payoutOf('B')[0]);

        $this->sentCalls = [];
        $this->artisan('tiktok:sync-payouts')->assertExitCode(0);
        $this->assertSame(['/finance/202309/withdrawals'], array_values(array_unique(array_column($this->sentCalls, 'path'))));
    }

    public function test_a_settled_statement_pays_its_orders_out(): void
    {
        $this->order('B', 'COMPLETED', now()->subDays(12), 'Releasing');
        DB::table('tiktok_orders')->where('order_id', 'B')->update(['fees' => json_encode(['commission' => 10, 'revenue' => 100, 'settlement_amount' => 90])]);
        $paidAt = now()->subHours(3)->timestamp;
        $this->callResponses['GET /finance/202309/statements'] = $this->ok(['statements' => [
            $this->statement('S4', 'SETTLED', now()->subDays(1)->startOfDay()->timestamp, $paidAt),
        ]]);
        $this->callResponses['GET /finance/202501/statements/S4/statement_transactions'] = $this->ok(['transactions' => [$this->orderLine('B')]]);

        $this->artisan('tiktok:sync-payouts')->assertExitCode(0);

        $this->assertSame(['Paid', date('Y-m-d H:i:s', $paidAt)], array_map(fn ($v) => $v === null ? null : (string) $v, $this->payoutOf('B')));
    }

    public function test_the_backfill_reads_from_the_oldest_waiting_order(): void
    {
        $created = now()->subDays(200);
        $this->order('OLD', 'COMPLETED', $created);
        $this->order('NEW', 'COMPLETED', now()->subDays(3));

        $this->callResponses['GET /finance/202309/statements'] = function (array $params) use ($created) {
            $this->assertSame($created->getTimestamp() - 86400, $params['statement_time_ge'], 'from a day before the oldest order');

            return $this->ok(['statements' => [$this->statement('S9', 'PAID', $created->copy()->addDays(10)->timestamp, $created->copy()->addDays(11)->timestamp)]]);
        };
        $this->callResponses['GET /finance/202501/statements/S9/statement_transactions'] = $this->ok(['transactions' => [$this->orderLine('OLD')]]);

        $this->artisan('tiktok:backfill-payouts')
            ->expectsOutputToContain('Payouts: 1 paid, 0 releasing, from 1 statements; 1 not settled yet.')
            ->assertExitCode(0);

        $this->assertSame('Paid', $this->payoutOf('OLD')[0]);
        $this->assertSame([null, null], $this->payoutOf('NEW'));
    }

    public function test_a_refusal_changes_nothing_and_says_what_tiktok_said(): void
    {
        $this->order('A', 'COMPLETED', now()->subDays(20));
        $this->callResponses['GET /finance/202309/statements'] = ['ok' => true, 'status' => 200,
            'body' => ['code' => 36009003, 'message' => 'Internal error. Please try again.']];

        $this->artisan('tiktok:backfill-payouts')
            ->expectsOutputToContain('TikTok Shop did not list the statements: Internal error. Please try again.')
            ->assertExitCode(1);

        $this->assertSame([null, null], $this->payoutOf('A'));
        $this->assertSame(0, DB::table('tiktok_api_logs')->where('pack', 'payout-sync')->where('ok', 1)->count(), 'a refused call is logged red');
    }

    public function test_an_expired_token_is_not_used(): void
    {
        $this->order('A', 'COMPLETED', now()->subDays(20));
        $this->tiktokStore->forceFill(['expires_at' => now()->subHour()])->save();

        $this->artisan('tiktok:backfill-payouts')->assertExitCode(1);

        $this->assertSame([], $this->sentCalls);
    }

    private function orderTransactions(string $orderId, array $lines): array
    {
        return $this->ok([
            'order_id' => $orderId, 'order_create_time' => now()->subDays(10)->timestamp, 'currency' => 'PHP',
            'revenue_amount' => '1500', 'fee_and_tax_amount' => '-160', 'shipping_cost_amount' => '-45', 'settlement_amount' => '1295',
            'sku_transactions' => $lines,
        ]);
    }

    private function skuLine(string $commission, string $transactionFee, string $shipping): array
    {
        return [
            'sku_id' => 'sku-' . $transactionFee, 'statement_id' => 'S1', 'quantity' => 1,
            'settlement_amount' => '600', 'revenue_amount' => '750',
            'fee_tax_breakdown' => ['fee' => ['platform_commission_amount' => $commission, 'transaction_fee_amount' => $transactionFee, 'affiliate_commission_amount' => '-5']],
            'shipping_cost_breakdown' => ['actual_shipping_fee_amount' => $shipping, 'customer_paid_shipping_fee_amount' => '20'],
        ];
    }

    public function test_fees_are_read_from_the_202501_transactions_by_order(): void
    {
        $this->order('SETTLED', 'COMPLETED', now()->subDays(10));
        $this->order('NOTYET', 'DELIVERED', now()->subDays(2));

        $this->callResponses['GET /finance/202501/orders/SETTLED/statement_transactions'] =
            $this->orderTransactions('SETTLED', [$this->skuLine('-60', '-15.5', '-30'), $this->skuLine('-60', '-14.5', '-15')]);
        $this->callResponses['GET /finance/202501/orders/NOTYET/statement_transactions'] = $this->orderTransactions('NOTYET', []);
        $this->callResponses['GET /finance/202309/statements'] = $this->ok(['statements' => []]);

        $this->artisan('tiktok:sync-orders --no-returns')->assertExitCode(0);

        $fees = json_decode((string) DB::table('tiktok_orders')->where('order_id', 'SETTLED')->value('fees'), true);
        $this->assertSame(120.0, (float) $fees['commission']);
        $this->assertSame(30.0, (float) $fees['transaction_fee']);
        $this->assertSame(45.0, (float) $fees['shipping_fee']);
        $this->assertSame(1500.0, (float) $fees['revenue']);
        $this->assertSame(1295.0, (float) $fees['settlement_amount']);
        $this->assertSame('SETTLED', $fees['statement']['order_id'], 'the answer is kept whole');

        $this->assertNull(DB::table('tiktok_orders')->where('order_id', 'NOTYET')->value('fees'), 'unsettled: asked again next run');

        $this->assertSame([], array_values(array_filter($this->sentCalls, fn ($c) => str_contains($c['path'], '/finance/202309/orders'))),
            'the retired 202309 version is never called');
        $this->assertSame(2, DB::table('tiktok_api_logs')->where('pack', 'fee-sync')->where('ok', 1)->count());

        $this->assertNotNull(\Extensions\tiktok\Services\TikTokOrderBreakdown::from($fees));
    }

    public function test_a_refused_fee_call_is_logged_red(): void
    {
        $this->order('SETTLED', 'COMPLETED', now()->subDays(10));
        $this->callResponses['GET /finance/202501/orders/SETTLED/statement_transactions'] = ['ok' => true, 'status' => 200,
            'body' => ['code' => 36009003, 'message' => 'Internal error. Please try again.']];
        $this->callResponses['GET /finance/202309/statements'] = $this->ok(['statements' => []]);

        $this->artisan('tiktok:sync-orders --no-returns')->assertExitCode(0);

        $this->assertNull(DB::table('tiktok_orders')->where('order_id', 'SETTLED')->value('fees'));
        $this->assertSame(1, DB::table('tiktok_api_logs')->where('pack', 'fee-sync')->where('ok', 0)->count(), 'HTTP 200 with a refusal in the body is red');
    }

    public function test_run_now_on_sync_orders_no_longer_walks_payouts(): void
    {
        $job = new \App\Models\ScheduledJob(['command' => 'tiktok:sync-orders']);
        $units = app(\Extensions\tiktok\Commands\TikTokSyncOrders::class)->automationUnits($job)['units'];

        $id = $this->tiktokStore->id;
        $this->assertSame(["{$id}:orders", "{$id}:returns"], $units, 'the same order Shopee and Lazada walk');
    }
}
