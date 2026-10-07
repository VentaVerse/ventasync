<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\lazada\Models\LazadaSetting;
use Extensions\lazada\Services\Lazada\LazadaClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LazadaVoucherTest extends TestCase
{
    use RefreshDatabase;

    private array $sentCalls = [];

    public array $callResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('lazada');
        $manager->enable('lazada');
        $this->app->register(\Extensions\lazada\LazadaExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        LazadaSetting::query()->create([
            'mode' => 'live', 'region' => 'ph',
            'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'refresh_token' => 'r',
        ]);

        $test = $this;
        $fake = new class($test) extends LazadaClient {
            public function __construct(private $test) {}
            public function get(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                return $this->test->answer('GET', $apiPath, $params);
            }
            public function post(string $region, string $apiPath, array $params, string $mode = 'live'): array
            {
                return $this->test->answer('POST', $apiPath, $params);
            }
            public function sign(string $apiPath, array $params, string $appSecret): string
            {
                return 'test-sign';
            }
        };
        $this->app->instance(LazadaClient::class, $fake);
    }

    public function answer(string $method, string $path, array $params): array
    {
        $this->sentCalls[] = ['method' => $method, 'path' => $path, 'params' => $params];

        $answer = $this->callResponses[$method . ' ' . $path] ?? null;
        if ($answer instanceof \Closure) {
            return $answer($params);
        }

        return $answer
            ?? ['ok' => true, 'status' => 200, 'body' => ['code' => 'ApiNotFound', 'message' => 'unexpected ' . $method . ' ' . $path]];
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
        $group = UserGroup::create(['name' => 'Lazada voucher group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $keys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        return $this->userWith(['manage_lazada/voucher', 'view_lazada/voucher']);
    }

    private function listAnswer(array $vouchers): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['voucher_list' => $vouchers]]];
    }

    private function sampleVoucher(array $overrides = []): array
    {
        return array_merge([
            'id' => 880011, 'name' => 'Payday treat', 'voucher_type' => 'COLLECTIBLE_VOUCHER', 'status' => 'ongoing',
            'discount_type' => 'MONEY_VALUE_OFF', 'discount_value' => '50.00',
            'criteria_over_money' => '500.00', 'issued' => 100,
            'period_start_time' => now()->subDay()->timestamp * 1000, 'period_end_time' => now()->addDay()->timestamp * 1000,
        ], $overrides);
    }

    public function test_the_page_lists_live_vouchers_with_the_verb_their_state_allows(): void
    {
        $this->callResponses['GET /promotion/vouchers/get'] = $this->listAnswer([
            $this->sampleVoucher(),
            $this->sampleVoucher(['id' => 880012, 'name' => 'Next week', 'status' => 'not_start',
                'discount_type' => 'PERCENTAGE_OFF', 'discount_value' => '10', 'max_discount_offering_money_value' => '100.00']),
            $this->sampleVoucher(['id' => 880013, 'name' => 'Old news', 'status' => 'expired']),
        ]);

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.vouchers.index'));

        $r->assertOk();
        $r->assertSee('Payday treat');
        $r->assertSee('Ongoing');
        $r->assertSee('Next week');
        $r->assertSee('10% off');
        $r->assertSee('Upcoming');
        $r->assertSee('Old news');
        $r->assertSee('Expired');
        $this->assertSame(1, substr_count($r->getContent(), '>End now<'));
        $this->assertSame(1, substr_count($r->getContent(), '>Cancel before it starts<'));
    }

    public function test_the_status_filter_rides_the_request_to_lazada(): void
    {
        $this->callResponses['GET /promotion/vouchers/get'] = $this->listAnswer([]);

        $this->actingAs($this->manager())->get(route('ext.lazada.vouchers.index', ['status' => 'ongoing']))->assertOk();

        $lists = $this->callsFor('GET', '/promotion/vouchers/get');
        $this->assertCount(1, $lists);
        $this->assertSame('ongoing', $lists[0]['params']['status']);
        $this->assertSame('COLLECTIBLE_VOUCHER', $lists[0]['params']['voucher_type']);
        $this->assertSame('t', $lists[0]['params']['access_token']);
    }

    public function test_when_lazada_does_not_answer_the_page_says_so_instead_of_guessing(): void
    {
        $this->callResponses['GET /promotion/vouchers/get'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => 'InsufficientPermission', 'message' => 'App does not have permission to access this api']];

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.vouchers.index'));

        $r->assertOk();
        $r->assertSee('Lazada did not answer');
        $r->assertSee('App does not have permission to access this api');
        $r->assertDontSee('Payday treat');
    }

    public function test_a_transport_failure_never_prints_the_signed_url(): void
    {
        $this->callResponses['GET /promotion/vouchers/get'] = function () {
            throw new \Illuminate\Http\Client\ConnectionException(
                'cURL error 28: Operation timed out after 10000 milliseconds for https://api.lazada.com.ph/rest/promotion/vouchers/get?app_key=114192&access_token=50000601137ySECRET&sign=abc'
            );
        };

        $r = $this->actingAs($this->manager())->get(route('ext.lazada.vouchers.index'));

        $r->assertOk();
        $r->assertSee('Lazada could not be reached; the request timed out.');
        $r->assertDontSee('50000601137ySECRET');
        $r->assertDontSee('app_key=');
    }

    public function test_create_sends_the_money_off_voucher_as_mapped(): void
    {
        $this->callResponses['POST /promotion/voucher/create'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['voucher_id' => 880099]]];

        $starts = now()->addHours(3)->startOfMinute();
        $ends = now()->addDays(7)->startOfMinute();

        $r = $this->actingAs($this->manager())->post(route('ext.lazada.vouchers.store'), [
            'name' => 'August treat',
            'money_off' => 50,
            'min_spend' => 500,
            'issued' => 100,
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $ends->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHas('status');
        $creates = $this->callsFor('POST', '/promotion/voucher/create');
        $this->assertCount(1, $creates);
        $p = $creates[0]['params'];
        $this->assertSame('August treat', $p['name']);
        $this->assertSame('COLLECTIBLE_VOUCHER', $p['voucher_type']);
        $this->assertSame('ENTIRE_SHOP', $p['apply']);
        $this->assertSame('MONEY_VALUE_OFF', $p['discount_type']);
        $this->assertSame('50.00', $p['discount_value']);
        $this->assertSame('500.00', $p['criteria_over_money']);
        $this->assertSame('100', $p['issued']);
        $this->assertSame((string) ($starts->timestamp * 1000), $p['period_start_time']);
        $this->assertSame((string) ($ends->timestamp * 1000), $p['period_end_time']);
        $this->assertArrayNotHasKey('max_discount_offering_money_value', $p);
    }

    public function test_create_sends_the_percentage_voucher_with_its_cap(): void
    {
        $this->callResponses['POST /promotion/voucher/create'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => ['voucher_id' => 880098]]];

        $r = $this->actingAs($this->manager())->post(route('ext.lazada.vouchers.store'), [
            'name' => 'Ten percent', 'percentage_off' => 10, 'max_discount' => 100, 'issued' => 50,
            'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHas('status');
        $p = $this->callsFor('POST', '/promotion/voucher/create')[0]['params'];
        $this->assertSame('PERCENTAGE_OFF', $p['discount_type']);
        $this->assertSame('10', $p['discount_value']);
        $this->assertSame('100.00', $p['max_discount_offering_money_value']);
        $this->assertSame('0.00', $p['criteria_over_money']);
    }

    public function test_both_reward_kinds_at_once_are_refused_before_any_call(): void
    {
        $r = $this->actingAs($this->manager())->post(route('ext.lazada.vouchers.store'), [
            'name' => 'Greedy', 'money_off' => 50, 'percentage_off' => 10, 'max_discount' => 100, 'issued' => 10,
            'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHasErrors('money_off');
        $this->assertSame([], $this->sentCalls);
    }

    public function test_a_failed_create_surfaces_lazadas_words_verbatim(): void
    {
        $this->callResponses['POST /promotion/voucher/create'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => 'IllegalRequest', 'message' => 'Voucher name already exists.']];

        $r = $this->actingAs($this->manager())->post(route('ext.lazada.vouchers.store'), [
            'name' => 'Dup', 'money_off' => 50, 'issued' => 10,
            'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Voucher name already exists.', session('error'));
    }

    public function test_deactivate_passes_the_voucher_id_through(): void
    {
        $this->callResponses['POST /promotion/voucher/deactivate'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => '0', 'data' => []]];

        $this->actingAs($this->manager())
            ->post(route('ext.lazada.vouchers.deactivate', 880011))
            ->assertSessionHas('status');

        $calls = $this->callsFor('POST', '/promotion/voucher/deactivate');
        $this->assertCount(1, $calls);
        $this->assertSame('880011', $calls[0]['params']['voucher_id']);
    }

    public function test_a_view_only_operator_reads_the_list_but_gets_no_verbs(): void
    {
        $this->callResponses['GET /promotion/vouchers/get'] = $this->listAnswer([$this->sampleVoucher()]);

        $viewer = $this->userWith(['view_lazada/voucher']);
        $r = $this->actingAs($viewer)->get(route('ext.lazada.vouchers.index'));

        $r->assertOk();
        $r->assertSee('Payday treat');
        $r->assertDontSee('>End now<', false);
        $r->assertDontSee('Create a voucher');

        $this->actingAs($viewer)
            ->post(route('ext.lazada.vouchers.deactivate', 880011))
            ->assertRedirect();
        $this->assertSame([], $this->callsFor('POST', '/promotion/voucher/deactivate'));
    }
}
