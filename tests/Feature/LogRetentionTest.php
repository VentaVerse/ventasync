<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Setting;
use App\Models\User;
use App\Support\LogRetention;
use Extensions\ventacart\Models\VentaCartSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class LogRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $manager = $this->app->make(\App\Extensions\ExtensionManager::class);
        $manager->install('ventacart');
        $manager->enable('ventacart');
        $this->app->register(\Extensions\ventacart\VentaCartExtension::class);
        $this->app['router']->getRoutes()->refreshNameLookups();

        $this->artisan('permissions:sync-catalogue');
    }

    private function manager(): User
    {
        $group = UserGroup::create(['name' => 'Settings managers']);
        $group->permissions()->attach(Permission::whereIn('key', ['manage_settings/setting', 'manage_settings/settings_hub'])->pluck('id')->all());

        return User::factory()->create(['user_group_id' => $group->id]);
    }

    private function syncRow(string $status, int $daysAgo, int $failed = 0): int
    {
        return (int) DB::table('ventacart_sync_logs')->insertGetId([
            'entity_type' => 'order', 'direction' => 'in', 'status' => $status,
            'records_processed' => 1, 'records_created' => 0, 'records_updated' => 0,
            'records_skipped' => 0, 'records_failed' => $failed,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_purging_removes_old_diagnostics_and_never_touches_business_records(): void
    {
        config(['logs.sync_level' => 'all']);

        $old = $this->syncRow('completed', 45);
        $recent = $this->syncRow('completed', 3);
        DB::table('api_request_logs')->insert([
            ['method' => 'GET', 'path' => 'v1/products', 'status' => 200, 'created_at' => now()->subDays(60)],
            ['method' => 'GET', 'path' => 'v1/orders', 'status' => 200, 'created_at' => now()->subDay()],
        ]);

        $stockRows = DB::table('stock_history')->count();
        DB::table('activity_logs')->insert(['action' => 'updated', 'subject_type' => 'Setting', 'subject_label' => 'old', 'source' => 'web', 'created_at' => now()->subDays(365)]);
        $activityRows = DB::table('activity_logs')->count();

        $removed = LogRetention::purge();

        $this->assertSame(1, $removed['ventacart_sync_logs'] ?? 0);
        $this->assertSame(1, $removed['api_request_logs'] ?? 0);
        $this->assertNull(DB::table('ventacart_sync_logs')->find($old));
        $this->assertNotNull(DB::table('ventacart_sync_logs')->find($recent), 'inside the retention, so kept');
        $this->assertSame(1, DB::table('api_request_logs')->count());

        $this->assertSame($stockRows, DB::table('stock_history')->count(), 'stock history is a business record');
        $this->assertSame($activityRows, DB::table('activity_logs')->count(), 'the activity log has its own retention and command');
    }

    public function test_the_level_decides_whether_a_finished_run_is_written_down_at_all(): void
    {
        config(['logs.sync_level' => 'failures']);

        $clean = VentaCartSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started', 'started_at' => now()]);
        $this->assertNotNull(VentaCartSyncLog::find($clean->id), 'a run in flight is always kept: that row is the evidence');
        $clean->update(['status' => 'completed', 'records_created' => 0, 'completed_at' => now()]);
        $this->assertNull(VentaCartSyncLog::find($clean->id), 'a clean finish is not worth a row');
        $this->assertSame('completed', $clean->status, 'the caller still reads what happened');

        $withFailures = VentaCartSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $withFailures->update(['status' => 'completed', 'records_failed' => 2, 'completed_at' => now()]);
        $this->assertNotNull(VentaCartSyncLog::find($withFailures->id), 'records failed, so it is kept');

        $crashed = VentaCartSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $crashed->update(['status' => 'failed', 'error_message' => 'boom', 'completed_at' => now()]);
        $this->assertNotNull(VentaCartSyncLog::find($crashed->id), 'a failure is exactly what this level keeps');

        config(['logs.sync_level' => 'off']);
        $off = VentaCartSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $off->update(['status' => 'failed', 'completed_at' => now()]);
        $this->assertNull(VentaCartSyncLog::find($off->id));

        config(['logs.sync_level' => 'all']);
        $kept = VentaCartSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $kept->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertNotNull(VentaCartSyncLog::find($kept->id));
    }

    public function test_a_store_sets_its_own_level_and_it_beats_the_default(): void
    {
        config(['logs.sync_level' => 'all']);
        $store = \Extensions\ventacart\Models\VentaCartSetting::create([
            'store_name' => 'Loud store', 'base_url' => 'https://loud.test', 'api_token' => 't', 'enabled' => true,
        ]);
        $quiet = \Extensions\ventacart\Models\VentaCartSetting::create([
            'store_name' => 'Quiet store', 'base_url' => 'https://quiet.test', 'api_token' => 't', 'enabled' => true,
            'sync_log_level' => 'off',
        ]);

        $follows = VentaCartSyncLog::create(['ventacart_setting_id' => $store->id, 'entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $follows->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertNotNull(VentaCartSyncLog::find($follows->id), 'no opinion, so it follows the default');

        $own = VentaCartSyncLog::create(['ventacart_setting_id' => $quiet->id, 'entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $own->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertNull(VentaCartSyncLog::find($own->id), "the store's own level wins");
    }

    public function test_the_sync_tab_carries_the_same_controls_as_the_api_log_tab(): void
    {
        $store = \Extensions\ventacart\Models\VentaCartSetting::create([
            'store_name' => 'Depot', 'base_url' => 'https://depot.test', 'api_token' => 't', 'enabled' => true,
        ]);
        DB::table('ventacart_sync_logs')->insert([
            'ventacart_setting_id' => $store->id, 'entity_type' => 'order', 'direction' => 'in', 'status' => 'completed',
            'records_processed' => 0, 'records_created' => 0, 'records_updated' => 0, 'records_skipped' => 0, 'records_failed' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $group = UserGroup::create(['name' => 'VentaCart managers']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_ventacart/settings', 'manage_ventacart/settings'])->pluck('id')->all());
        $manager = User::factory()->create(['user_group_id' => $group->id]);

        $page = $this->actingAs($manager)->get(route('ext.ventacart.settings.show', [$store->id, 'tab' => 'sync']))->assertOk();
        $page->assertSee('Recording');
        $page->assertSee('name="sync_log_level"', false);
        $page->assertSee('Follow the default');
        $page->assertSee('Delete all');
        $page->assertSee(route('ext.ventacart.clear_sync_logs', $store->id), false);

        $this->actingAs($manager)->post(route('ext.ventacart.set_sync_log_level', $store->id), ['sync_log_level' => 'failures'])
            ->assertRedirect(route('ext.ventacart.settings.show', [$store->id, 'tab' => 'sync']));
        $this->assertSame('failures', $store->fresh()->sync_log_level);

        $this->actingAs($manager)->delete(route('ext.ventacart.clear_sync_logs', $store->id))->assertRedirect();
        $this->assertSame(0, DB::table('ventacart_sync_logs')->where('ventacart_setting_id', $store->id)->count());

        $this->actingAs($manager)->post(route('ext.ventacart.set_sync_log_level', $store->id), ['sync_log_level' => ''])->assertRedirect();
        $this->assertNull($store->fresh()->sync_log_level);
    }

    public function test_core_settings_hold_no_control_over_an_extensions_logs(): void
    {
        $manager = $this->manager();

        $page = $this->actingAs($manager)->get(route('settings.website'))->assertOk();
        $page->assertDontSee('Sync and API logs');
        $page->assertDontSee('name="sync_log_level"', false);
        $page->assertDontSee('Purge now');
        $page->assertSee('name="activity_log_retention_days"', false);

        $this->assertFalse(\Illuminate\Support\Facades\Route::has('settings.logs.purge'), 'core purges no extension table');
    }

    public function test_every_swept_table_is_declared_by_the_extension_that_owns_it(): void
    {
        $tables = LogRetention::tables();

        $this->assertSame('core', $tables['api_request_logs']['owner'] ?? null, 'calls into this ERP are core\'s own record');
        $this->assertSame('ventacart', $tables['ventacart_sync_logs']['owner'] ?? null, 'declared in extensions/ventacart/extension.json');
        $this->assertSame('ventacart', $tables['ventacart_api_logs']['owner'] ?? null);

        foreach (['order_history', 'stock_history', 'purchase_order_logs', 'activity_logs'] as $safe) {
            $this->assertArrayNotHasKey($safe, $tables, $safe . ' is a business record, not a diagnostic');
        }
    }

    public function test_the_command_honours_an_override_and_reports_what_it_removed(): void
    {
        config(['logs.default_keep_days' => 365]);
        $this->syncRow('completed', 10);

        $this->artisan('logs:purge')->assertSuccessful();
        $this->assertSame(1, DB::table('ventacart_sync_logs')->count(), 'inside a 365 day retention');

        $this->artisan('logs:purge', ['--days' => 5])->assertSuccessful();
        $this->assertSame(0, DB::table('ventacart_sync_logs')->count());
    }
}
