<?php

namespace Tests\Feature;

use App\Http\Controllers\Concerns\DrivesGroupSendRuns;
use App\Http\Controllers\Controller;
use App\Models\AutomationRun;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class GroupSendRunTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        FakeGroupSendController::reset();
        Route::middleware('web')->group(function () {
            Route::post('/_group-send/{store}/groups/{group}/send-runs', [FakeGroupSendController::class, 'sendRunBegin'])->name('test.group_send.begin');
            Route::post('/_group-send/{store}/groups/{group}/send-runs/{run}/step', [FakeGroupSendController::class, 'sendRunStep'])->name('test.group_send.step');
            Route::post('/_group-send/{store}/groups/{group}/send-runs/{run}/stop', [FakeGroupSendController::class, 'sendRunStop'])->name('test.group_send.stop');
        });
        $this->app['router']->getRoutes()->refreshNameLookups();
    }

    private function begin(int $store = 1, int $group = 7)
    {
        return $this->postJson(route('test.group_send.begin', ['store' => $store, 'group' => $group]))->assertOk();
    }

    private function step(int $run, int $store = 1, int $group = 7)
    {
        return $this->postJson(route('test.group_send.step', ['store' => $store, 'group' => $group, 'run' => $run]));
    }

    public function test_a_whole_group_is_walked_ten_at_a_time_and_counted(): void
    {
        FakeGroupSendController::$members = range(101, 123);
        FakeGroupSendController::$failing = [105, 121];

        $first = $this->begin()->json();
        $this->assertSame('running', $first['run']['status']);
        $this->assertSame(23, $first['run']['total']);
        $this->assertSame(10, $first['run']['done']);
        $this->assertSame([range(101, 110)], FakeGroupSendController::$chunks);

        $this->step($first['run']['id'])->assertOk()->assertJsonPath('run.done', 20);
        $last = $this->step($first['run']['id'])->assertOk()->json();

        $this->assertSame('done', $last['run']['status']);
        $this->assertSame([range(101, 110), range(111, 120), range(121, 123)], FakeGroupSendController::$chunks);
        $this->assertSame('23 products: 21 ok, 2 failed.', $last['outcome']);
        $run = AutomationRun::query()->findOrFail($first['run']['id']);
        $this->assertNull($run->scheduled_job_id);
        $this->assertSame('group:7', $run->subject);
        $this->assertSame(1, (int) $run->store_id);
    }

    public function test_a_product_that_left_the_group_is_not_sent(): void
    {
        FakeGroupSendController::$members = range(1, 12);
        $run = $this->begin()->json('run.id');

        FakeGroupSendController::$members = [11];
        $last = $this->step($run)->assertOk()->json();

        $this->assertSame([range(1, 10), [11]], FakeGroupSendController::$chunks);
        $this->assertSame('12 products: 12 ok.', $last['outcome']);
    }

    public function test_a_refusal_that_touched_nothing_ends_the_run_with_its_words(): void
    {
        FakeGroupSendController::$members = range(1, 30);
        FakeGroupSendController::$stop = 'Missing store settings.';

        $answer = $this->begin()->json();

        $this->assertSame('failed', $answer['run']['status']);
        $this->assertSame('Missing store settings.', $answer['outcome']);
        $this->assertSame(0, $answer['run']['done']);
    }

    public function test_stop_keeps_what_was_sent(): void
    {
        FakeGroupSendController::$members = range(1, 25);
        $run = $this->begin()->json('run.id');

        $answer = $this->postJson(route('test.group_send.stop', ['store' => 1, 'group' => 7, 'run' => $run]))->assertOk()->json();

        $this->assertSame('stopped', $answer['run']['status']);
        $this->assertSame('Stopped after 10 products of 25: 10 ok.', $answer['outcome']);
        $this->step($run)->assertOk()->assertJsonPath('run.done', 10);
    }

    public function test_an_empty_group_has_nothing_to_send(): void
    {
        FakeGroupSendController::$members = [];

        $answer = $this->begin()->json();

        $this->assertSame('done', $answer['run']['status']);
        $this->assertSame('Nothing to do: no products to push.', $answer['outcome']);
        $this->assertSame([], FakeGroupSendController::$chunks);
    }

    public function test_a_run_is_found_only_under_its_own_store_and_group(): void
    {
        FakeGroupSendController::$members = range(1, 25);
        $run = $this->begin()->json('run.id');

        $this->step($run, 2, 7)->assertNotFound();
        $this->step($run, 1, 8)->assertNotFound();
    }

    public function test_sending_the_group_again_stops_the_open_run(): void
    {
        FakeGroupSendController::$members = range(1, 25);
        $first = $this->begin()->json('run.id');
        $second = $this->begin()->json('run.id');

        $this->assertSame(AutomationRun::STOPPED, AutomationRun::query()->findOrFail($first)->status);
        $this->assertSame(AutomationRun::RUNNING, AutomationRun::query()->findOrFail($second)->status);
    }
}

class FakeGroupSendController extends Controller
{
    use DrivesGroupSendRuns;

    public static array $members = [];
    public static array $failing = [];
    public static ?string $stop = null;
    public static array $chunks = [];

    public static function reset(): void
    {
        self::$members = [];
        self::$failing = [];
        self::$stop = null;
        self::$chunks = [];
    }

    protected function groupSendIntegration(): string
    {
        return 'fake';
    }

    protected function groupSendStoreId(Request $request): int
    {
        return (int) $request->route('store');
    }

    protected function groupSendMembers(int $groupId): array
    {
        return self::$members;
    }

    protected function groupSendChunk(int $groupId, array $productIds): array
    {
        if (self::$stop !== null) {
            return ['tone' => 'error', 'summary' => self::$stop, 'failed' => 0, 'stop' => true];
        }
        self::$chunks[] = $productIds;

        return ['tone' => 'status', 'summary' => 'Sent.', 'failed' => count(array_intersect($productIds, self::$failing)), 'stop' => false];
    }
}
