<?php

namespace Tests\Feature\Plans;

use App\Plans\FileRetention;
use App\Support\LogRetention;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class RetentionTest extends TestCase
{
    use RefreshDatabase;
    use SetsServerPlan;

    protected function tearDown(): void
    {
        File::deleteDirectory($this->root());
        foreach (glob(storage_path('logs/error-*.log')) ?: [] as $file) {
            @unlink($file);
        }
        parent::tearDown();
    }

    private function root(): string
    {
        return sys_get_temp_dir() . '/plantest-' . getmypid();
    }

    private function aged(string $path, int $daysOld): void
    {
        File::ensureDirectoryExists(dirname($path));
        file_put_contents($path, 'x');
        touch($path, now()->subDays($daysOld)->getTimestamp());
    }

    public function test_the_plan_caps_every_database_log(): void
    {
        $this->onServer([], ['log_days' => 7]);

        foreach (LogRetention::tables() as $table => $meta) {
            $this->assertLessThanOrEqual(7, $meta['keep_days'], $table);
        }
    }

    public function test_self_hosted_keeps_its_configured_log_days(): void
    {
        $this->selfHosted();

        $this->assertSame((int) config('logs.core.api_request_logs'), LogRetention::tables()['api_request_logs']['keep_days']);
    }

    public function test_the_activity_setting_cannot_pass_the_plan(): void
    {
        $admin = $this->admin();
        $this->onServer([], ['activity_days' => 30]);

        $this->actingAs($admin)->get(route('settings.website'))->assertSee('max="30"', false);
    }

    public function test_waybills_past_the_plan_days_are_deleted_and_newer_kept(): void
    {
        $old = $this->root() . '/plantest-awb/1/old.pdf';
        $new = $this->root() . '/plantest-awb/1/new.pdf';
        $this->aged($old, 100);
        $this->aged($new, 10);
        $this->onServer([], ['awb_days' => 90]);

        FileRetention::purgeWaybills($this->root());

        $this->assertFileDoesNotExist($old);
        $this->assertFileExists($new);
    }

    public function test_self_hosted_keeps_every_waybill(): void
    {
        $old = $this->root() . '/plantest-awb/1/old.pdf';
        $this->aged($old, 400);
        $this->selfHosted();

        FileRetention::purgeWaybills($this->root());

        $this->assertFileExists($old);
    }

    public function test_the_error_log_is_rotated_into_a_dated_file(): void
    {
        $current = storage_path('logs/error.log');
        $aside = $current . '.plantest';
        $had = is_file($current) && rename($current, $aside);
        file_put_contents($current, "[today] E_WARNING: test\n");

        try {
            FileRetention::rotateErrorLog(14);

            $this->assertSame('', (string) file_get_contents($current));
            $this->assertStringContainsString('E_WARNING: test', (string) file_get_contents(storage_path('logs/error-' . now()->toDateString() . '.log')));
        } finally {
            @unlink($current);
            if ($had) {
                rename($aside, $current);
            }
        }
    }
}
