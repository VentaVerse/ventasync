<?php

namespace Tests\Feature;

use App\Extensions\ExtensionManager;
use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\User;
use Extensions\tiktok\Models\TikTokSetting;
use Extensions\tiktok\Services\TikTok\TikTokClient;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TiktokCouponTest extends TestCase
{
    use RefreshDatabase;

    private array $sentCalls = [];

    public array $callResponses = [];

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(ExtensionManager::class);
        $manager->install('tiktok');
        $manager->enable('tiktok');
        $this->app->register(\Extensions\tiktok\TikTokExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();
        $this->artisan('permissions:sync-catalogue');

        TikTokSetting::create([
            'mode' => 'production', 'app_key' => 'k', 'app_secret' => 's',
            'access_token' => 't', 'shop_cipher' => 'c',
        ]);

        $test = $this;
        $fake = new class($test) extends TikTokClient {
            public function __construct(private $test) {}
            public function post(string $appKey, string $appSecret, string $accessToken, string $path, array $queryParams = [], array $body = [], ?string $shopCipher = null): array
            {
                return $this->test->answer($path, $body, $queryParams);
            }
        };
        $this->app->instance(TikTokClient::class, $fake);
    }

    public function answer(string $path, array $body, array $query): array
    {
        $this->sentCalls[] = ['path' => $path, 'body' => $body, 'query' => $query];

        $answer = $this->callResponses[$path] ?? null;
        if ($answer instanceof \Closure) {
            return $answer($body, $query);
        }

        return $answer
            ?? ['ok' => false, 'status' => 500, 'body' => ['code' => -1, 'message' => 'unexpected POST ' . $path]];
    }

    private function callsFor(string $path): array
    {
        return array_values(array_filter($this->sentCalls, fn ($c) => $c['path'] === $path));
    }

    private int $groupSeq = 0;

    private function userWith(array $keys): User
    {
        $group = UserGroup::create(['name' => 'Coupon group ' . (++$this->groupSeq)]);
        $group->permissions()->attach(
            Permission::whereIn('key', $keys)->pluck('id')->all()
        );

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function manager(): User
    {
        return $this->userWith(['manage_tiktok/coupon', 'view_tiktok/coupon']);
    }

    private function listAnswer(array $coupons): array
    {
        return ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['coupons' => $coupons]]];
    }

    private function sampleCoupon(array $overrides = []): array
    {
        return array_merge([
            'id' => '7700110001', 'title' => 'Payday treat', 'display_type' => 'REGULAR', 'status' => 'ONGOING',
            'discount' => ['type' => 'MONEY_OFF', 'money_off' => '50.00'],
            'threshold' => ['type' => 'MIN_SPEND', 'min_spend' => '500.00'],
            'usage_limits' => ['total_claim_count' => 100],
            'claimed_count' => 7,
            'redemption_duration' => ['start_time' => now()->subDay()->timestamp, 'end_time' => now()->addDay()->timestamp],
        ], $overrides);
    }

    public function test_the_page_lists_live_coupons_with_the_verb_their_state_allows(): void
    {
        $this->callResponses['/promotion/202406/coupons/search'] = $this->listAnswer([
            $this->sampleCoupon(),
            $this->sampleCoupon(['id' => '7700110002', 'title' => 'Next week', 'status' => 'NOT_START',
                'discount' => ['type' => 'PERCENTAGE_OFF', 'percentage_off' => 10, 'max_discount' => '100.00']]),
            $this->sampleCoupon(['id' => '7700110003', 'title' => 'Old news', 'status' => 'EXPIRED']),
        ]);

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.coupons.index'));

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

    public function test_the_status_filter_is_passed_to_tiktok_not_applied_after_the_fact(): void
    {
        $this->callResponses['/promotion/202406/coupons/search'] = $this->listAnswer([]);

        $this->actingAs($this->manager())->get(route('ext.tiktok.coupons.index', ['status' => 'ongoing']))->assertOk();

        $search = $this->callsFor('/promotion/202406/coupons/search');
        $this->assertCount(1, $search);
        $this->assertSame(['status' => ['ONGOING']], $search[0]['body']);
        $this->assertSame(100, $search[0]['query']['page_size']);
    }

    public function test_when_tiktok_does_not_answer_the_page_says_so_instead_of_guessing(): void
    {
        $this->callResponses['/promotion/202406/coupons/search'] =
            ['ok' => false, 'status' => 500, 'body' => ['code' => 500, 'message' => 'TikTok fell over.']];

        $r = $this->actingAs($this->manager())->get(route('ext.tiktok.coupons.index'));

        $r->assertOk();
        $r->assertSee('TikTok did not answer');
        $r->assertSee('TikTok fell over.');
        $r->assertDontSee('Payday treat');
    }

    public function test_create_sends_the_money_off_coupon_tiktok_expects(): void
    {
        $this->callResponses['/promotion/202406/coupons'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['coupon_id' => '7700119999']]];

        $starts = now()->addHours(3)->startOfMinute();
        $ends = now()->addDays(7)->startOfMinute();

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.coupons.store'), [
            'title' => 'August treat',
            'money_off' => 50,
            'min_spend' => 500,
            'total_claim_count' => 100,
            'starts_at' => $starts->format('Y-m-d\TH:i'),
            'ends_at' => $ends->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHas('status');
        $creates = $this->callsFor('/promotion/202406/coupons');
        $this->assertCount(1, $creates);
        $body = $creates[0]['body'];
        $this->assertSame('August treat', $body['title']);
        $this->assertSame('REGULAR', $body['display_type']);
        $this->assertSame(['type' => 'MONEY_OFF', 'money_off' => '50.00'], $body['discount']);
        $this->assertSame(['type' => 'MIN_SPEND', 'min_spend' => '500.00'], $body['threshold']);
        $this->assertSame(100, $body['usage_limits']['total_claim_count']);
        $this->assertSame($starts->timestamp, $body['claim_duration']['start_time']);
        $this->assertSame($ends->timestamp, $body['redemption_duration']['end_time']);
    }

    public function test_create_sends_the_percentage_coupon_with_its_cap_and_no_threshold(): void
    {
        $this->callResponses['/promotion/202406/coupons'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => ['coupon_id' => '7700119998']]];

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.coupons.store'), [
            'title' => 'Ten percent',
            'percentage_off' => 10,
            'max_discount' => 100,
            'total_claim_count' => 50,
            'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHas('status');
        $body = $this->callsFor('/promotion/202406/coupons')[0]['body'];
        $this->assertSame(['type' => 'PERCENTAGE_OFF', 'percentage_off' => 10, 'max_discount' => '100.00'], $body['discount']);
        $this->assertSame(['type' => 'NO_THRESHOLD'], $body['threshold']);
    }

    public function test_both_reward_kinds_at_once_are_refused_before_any_call(): void
    {
        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.coupons.store'), [
            'title' => 'Greedy', 'money_off' => 50, 'percentage_off' => 10, 'max_discount' => 100,
            'total_claim_count' => 10,
            'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHasErrors('money_off');
        $this->assertSame([], $this->sentCalls);
    }

    public function test_a_failed_create_surfaces_tiktoks_words_verbatim(): void
    {
        $this->callResponses['/promotion/202406/coupons'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => 12052901, 'message' => 'Coupon title already exists.']];

        $r = $this->actingAs($this->manager())->post(route('ext.tiktok.coupons.store'), [
            'title' => 'Dup', 'money_off' => 50, 'total_claim_count' => 10,
            'starts_at' => now()->addHours(3)->format('Y-m-d\TH:i'),
            'ends_at' => now()->addDays(7)->format('Y-m-d\TH:i'),
        ]);

        $r->assertSessionHas('error');
        $this->assertStringContainsString('Coupon title already exists.', session('error'));
    }

    public function test_deactivate_passes_the_coupon_id_through_the_path(): void
    {
        $this->callResponses['/promotion/202406/coupons/7700110001/deactivate'] =
            ['ok' => true, 'status' => 200, 'body' => ['code' => 0, 'message' => 'Success', 'data' => []]];

        $this->actingAs($this->manager())
            ->post(route('ext.tiktok.coupons.deactivate', '7700110001'))
            ->assertSessionHas('status');

        $this->assertCount(1, $this->callsFor('/promotion/202406/coupons/7700110001/deactivate'));
    }

    public function test_a_view_only_operator_reads_the_list_but_gets_no_verbs(): void
    {
        $this->callResponses['/promotion/202406/coupons/search'] = $this->listAnswer([$this->sampleCoupon()]);

        $viewer = $this->userWith(['view_tiktok/coupon']);
        $r = $this->actingAs($viewer)->get(route('ext.tiktok.coupons.index'));

        $r->assertOk();
        $r->assertSee('Payday treat');
        $r->assertDontSee('>End now<', false);
        $r->assertDontSee('Create a coupon');

        $this->actingAs($viewer)
            ->post(route('ext.tiktok.coupons.deactivate', '7700110001'))
            ->assertRedirect();
        $this->assertSame([], $this->callsFor('/promotion/202406/coupons/7700110001/deactivate'));
    }
}
