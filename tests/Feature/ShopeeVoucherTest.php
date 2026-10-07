<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\shopee\Models\ShopeeSetting;
use Extensions\shopee\Services\Shopee\ShopeeClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShopeeVoucherTest extends TestCase
{
    use RefreshDatabase;

    private array $sentCalls = [];

    public array $callResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('shopee');
        $manager->enable('shopee');
        $this->app->register(\Extensions\shopee\ShopeeExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        ShopeeSetting::query()->create([
            'partner_id' => 1001, 'partner_key' => 'k', 'shop_id' => 2002,
            'access_token' => 't', 'refresh_token' => 'r', 'mode' => 'production',
        ]);

        $test = $this;
        $fake = new class($test) extends ShopeeClient {
            public function __construct(private $test) {}
            public function shopGet(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = []): array
            {
                return $this->test->answer('GET', $path, $extraQuery);
            }
            public function shopPost(string $mode, int $partnerId, string $partnerKey, string $accessToken, int $shopId, string $path, array $extraQuery = [], array $body = []): array
            {
                return $this->test->answer('POST', $path, $body);
            }
            public function signShop(int $partnerId, string $partnerKey, string $path, int $timestamp, string $accessToken, int $shopId): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(ShopeeClient::class, $fake);
    }

    public function answer(string $method, string $path, array $payload): array
    {
        $this->sentCalls[] = ['method' => $method, 'path' => $path, 'payload' => $payload];

        $answer = $this->callResponses[$method . ' ' . $path] ?? null;
        if ($answer instanceof \Closure) {
            return $answer($payload);
        }

        return $answer
            ?? ['ok' => false, 'status' => 500, 'body' => ['error' => 'unexpected', 'message' => 'unexpected ' . $method . ' ' . $path]];
    }

    private function callsFor(string $method, string $path): array
    {
        return array_values(array_filter(
            $this->sentCalls,
            fn ($c) => $c['method'] === $method && $c['path'] === $path
        ));
    }

    private int $groupSeq = 0;

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Voucher group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $keys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        return $this->userWith(['manage_shopee/voucher', 'view_shopee/voucher']);
    }

    private function listAnswer(array $vouchers): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['voucher_list' => $vouchers]]];
    }

    private function sampleVoucher(array $overrides = []): array
    {
        return array_merge([
            'voucher_id' => 660011, 'voucher_name' => 'Payday treat', 'voucher_code' => 'SAVE5',
            'voucher_type' => 1, 'reward_type' => 1, 'discount_amount' => 50.0,
            'min_basket_price' => 500.0, 'usage_quantity' => 100, 'current_usage' => 7,
            'start_time' => now()->subDay()->timestamp, 'end_time' => now()->addDay()->timestamp,
        ], $overrides);
    }

    public function test_the_page_lists_live_vouchers_with_the_verb_their_state_allows(): void
    {
        $this->callResponses['GET /api/v2/voucher/get_voucher_list'] = $this->listAnswer([
            $this->sampleVoucher(),
            $this->sampleVoucher([
                'voucher_id' => 660012, 'voucher_name' => 'Next week', 'voucher_code' => 'NEXT9',
                'reward_type' => 2, 'percentage' => 10, 'max_price' => 100.0, 'discount_amount' => null,
                'start_time' => now()->addDays(2)->timestamp, 'end_time' => now()->addDays(9)->timestamp,
            ]),
        ]);

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.vouchers.index'));

        $r->assertOk();
        $r->assertSee('Payday treat');
        $r->assertSee('SAVE5');
        $r->assertSee('Ongoing');
        $r->assertSee('End now');
        $r->assertSee('Next week');
        $r->assertSee('10% off');
        $r->assertSee('Upcoming');
        $r->assertSee('Delete');
        $this->assertSame(1, substr_count($r->getContent(), 'End now'));
    }

    public function test_when_shopee_does_not_answer_the_page_says_so_instead_of_guessing(): void
    {
        $this->callResponses['GET /api/v2/voucher/get_voucher_list'] =
            ['ok' => false, 'status' => 500, 'body' => ['error' => 'server', 'message' => 'Shopee fell over.']];

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.vouchers.index'));

        $r->assertOk();
        $r->assertSee('Shopee did not answer');
        $r->assertSee('Shopee fell over.');
        $r->assertDontSee('SAVE5');
    }

    public function test_a_transport_failure_never_prints_the_signed_url(): void
    {
        $this->callResponses['GET /api/v2/voucher/get_voucher_list'] = function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 7: Failed to connect: Connection refused for https://partner.shopeemobile.com/api/v2/voucher/get_voucher_list?partner_id=1001&access_token=tSECRET&sign=abc'
            );
        };

        $r = $this->actingAs($this->manager())->get(route('ext.shopee.vouchers.index'));

        $r->assertOk();
        $r->assertSee('Shopee could not be reached; the connection was refused.');
        $r->assertDontSee('tSECRET');
        $r->assertDontSee('access_token=');
    }

    public function test_create_sends_the_fixed_amount_voucher_shopee_expects(): void
    {
        $this->callResponses['GET /api/v2/voucher/get_voucher_list'] = $this->listAnswer([]);
        $this->callResponses['POST /api/v2/voucher/add_voucher'] =
            ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['voucher_id' => 770099]]];

        $starts = now()->addHours(3)->startOfMinute();
        $ends = now()->addDays(7)->startOfMinute();

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.vouchers.store'), [
            'voucher_name' => 'August treat',
            'voucher_code' => 'aug50',
            'discount_amount' => 50,
            'min_basket_price' => 500,
            'usage_quantity' => 100,
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $ends->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHas('status');
        $creates = $this->callsFor('POST', '/api/v2/voucher/add_voucher');
        $this->assertCount(1, $creates);
        $body = $creates[0]['payload'];
        $this->assertSame('August treat', $body['voucher_name']);
        $this->assertSame('AUG50', $body['voucher_code']);
        $this->assertSame(1, $body['voucher_type']);
        $this->assertSame(1, $body['reward_type']);
        $this->assertEqualsWithDelta(50.0, $body['discount_amount'], 0.001);
        $this->assertArrayNotHasKey('percentage', $body);
        $this->assertSame($starts->timestamp, $body['start_time']);
        $this->assertSame($ends->timestamp, $body['end_time']);
    }

    public function test_create_sends_the_percentage_voucher_with_its_cap(): void
    {
        $this->callResponses['POST /api/v2/voucher/add_voucher'] =
            ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['voucher_id' => 770100]]];

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.vouchers.store'), [
            'voucher_name' => 'Ten percent',
            'voucher_code' => 'TEN10',
            'percentage' => 10,
            'max_price' => 100,
            'usage_quantity' => 50,
            'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHas('status');
        $body = $this->callsFor('POST', '/api/v2/voucher/add_voucher')[0]['payload'];
        $this->assertSame(2, $body['reward_type']);
        $this->assertSame(10, $body['percentage']);
        $this->assertEqualsWithDelta(100.0, $body['max_price'], 0.001);
        $this->assertArrayNotHasKey('discount_amount', $body);
    }

    public function test_both_reward_kinds_at_once_are_refused_before_any_call(): void
    {
        $r = $this->actingAs($this->manager())
            ->from(route('ext.shopee.vouchers.index'))
            ->post(route('ext.shopee.vouchers.store'), [
                'voucher_name' => 'Greedy', 'voucher_code' => 'BOTH1',
                'discount_amount' => 50, 'percentage' => 10, 'max_price' => 100,
                'usage_quantity' => 10,
                'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
                'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
            ]);

        $r->assertSessionHasErrors('discount_amount');
        $this->assertSame([], $this->sentCalls);
    }

    public function test_a_percentage_without_its_cap_is_refused(): void
    {
        $r = $this->actingAs($this->manager())->post(route('ext.shopee.vouchers.store'), [
            'voucher_name' => 'No cap', 'voucher_code' => 'NOCAP',
            'percentage' => 10, 'usage_quantity' => 10,
            'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHasErrors('max_price');
        $this->assertSame([], $this->sentCalls);
    }

    public function test_a_failed_create_surfaces_shopees_words_verbatim(): void
    {
        $this->callResponses['POST /api/v2/voucher/add_voucher'] =
            ['ok' => true, 'status' => 200, 'body' => ['error' => 'voucher.code_taken', 'message' => 'The voucher code already exists.']];

        $r = $this->actingAs($this->manager())->post(route('ext.shopee.vouchers.store'), [
            'voucher_name' => 'Dup', 'voucher_code' => 'SAVE5',
            'discount_amount' => 50, 'usage_quantity' => 10,
            'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('The voucher code already exists.', session('error'));
    }

    public function test_end_and_delete_pass_the_voucher_id_through(): void
    {
        $this->callResponses['POST /api/v2/voucher/end_voucher'] =
            ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['voucher_id' => 660011]]];
        $this->callResponses['POST /api/v2/voucher/delete_voucher'] =
            ['ok' => true, 'status' => 200, 'body' => ['error' => '', 'response' => ['voucher_id' => 660012]]];

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.vouchers.end', 660011))
            ->assertSessionHas('status');
        $this->assertSame(['voucher_id' => 660011], $this->callsFor('POST', '/api/v2/voucher/end_voucher')[0]['payload']);

        $this->actingAs($this->manager())
            ->post(route('ext.shopee.vouchers.delete', 660012))
            ->assertSessionHas('status');
        $this->assertSame(['voucher_id' => 660012], $this->callsFor('POST', '/api/v2/voucher/delete_voucher')[0]['payload']);
    }

    public function test_a_view_only_operator_reads_the_list_but_gets_no_verbs(): void
    {
        $this->callResponses['GET /api/v2/voucher/get_voucher_list'] = $this->listAnswer([$this->sampleVoucher()]);

        $viewer = $this->userWith(['view_shopee/voucher']);
        $r = $this->actingAs($viewer)->get(route('ext.shopee.vouchers.index'));

        $r->assertOk();
        $r->assertSee('SAVE5');
        $r->assertDontSee('End now');
        $r->assertDontSee('Create a voucher');

        $this->actingAs($viewer)
            ->post(route('ext.shopee.vouchers.end', 660011))
            ->assertRedirect();
        $this->assertSame([], $this->callsFor('POST', '/api/v2/voucher/end_voucher'));
    }
}
