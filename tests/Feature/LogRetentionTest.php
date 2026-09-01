<?php

namespace Tests\Feature;

use App\Models\Admin\Permission;
use App\Models\Admin\UserGroup;
use App\Models\Setting;
use App\Models\User;
use App\Support\LogRetention;
use Extensions\venta\Models\VentaSyncLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Sync and API logs grow forever unless something stops them (the owner,
 * 2026-09-01: the live disk was being eaten, venta_sync_logs was the largest
 * table in the database at 310,120 rows). Two controls: how long rows are
 * kept, and whether a sync run is written down at all.
 */
class LogRetentionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Venta is registered because its Sync log tab is where the controls
        // live now; its routes and permissions do not exist otherwise.
        $manager = $this->app->make(\App\Extensions\ExtensionManager::class);
        $manager->install('venta');
        $manager->enable('venta');
        $this->app->register(\Extensions\venta\VentaExtension::class);
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
        // Written straight to the table so the level hook (which is about
        // NEW runs) cannot interfere with an age fixture.
        return (int) DB::table('venta_sync_logs')->insertGetId([
            'entity_type' => 'order', 'direction' => 'in', 'status' => $status,
            'records_processed' => 1, 'records_created' => 0, 'records_updated' => 0,
            'records_skipped' => 0, 'records_failed' => $failed,
            'created_at' => now()->subDays($daysAgo), 'updated_at' => now()->subDays($daysAgo),
        ]);
    }

    public function test_purging_removes_old_diagnostics_and_never_touches_business_records(): void
    {
        Setting::singleton()->update(['sync_log_retention_days' => 30, 'api_log_retention_days' => 30, 'sync_log_level' => 'all']);

        $old = $this->syncRow('completed', 45);
        $recent = $this->syncRow('completed', 3);
        DB::table('api_request_logs')->insert([
            ['method' => 'GET', 'path' => 'v1/products', 'status' => 200, 'created_at' => now()->subDays(60)],
            ['method' => 'GET', 'path' => 'v1/orders', 'status' => 200, 'created_at' => now()->subDay()],
        ]);

        // Business records of the same age, which must survive: these are
        // what the reports and the audit trail are made of.
        $stockRows = DB::table('stock_history')->count();
        DB::table('activity_logs')->insert(['action' => 'updated', 'subject_type' => 'Setting', 'subject_label' => 'old', 'source' => 'web', 'created_at' => now()->subDays(365)]);
        $activityRows = DB::table('activity_logs')->count();

        $removed = LogRetention::purge();

        $this->assertSame(1, $removed['venta_sync_logs'] ?? 0);
        $this->assertSame(1, $removed['api_request_logs'] ?? 0);
        $this->assertNull(DB::table('venta_sync_logs')->find($old));
        $this->assertNotNull(DB::table('venta_sync_logs')->find($recent), 'inside the retention, so kept');
        $this->assertSame(1, DB::table('api_request_logs')->count());

        $this->assertSame($stockRows, DB::table('stock_history')->count(), 'stock history is a business record');
        $this->assertSame($activityRows, DB::table('activity_logs')->count(), 'the activity log has its own retention and command');
    }

    public function test_the_level_decides_whether_a_finished_run_is_written_down_at_all(): void
    {
        // "Only runs that had a problem": a clean finish leaves nothing.
        Setting::singleton()->update(['sync_log_level' => 'failures']);

        $clean = VentaSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started', 'started_at' => now()]);
        $this->assertNotNull(VentaSyncLog::find($clean->id), 'a run in flight is always kept: that row is the evidence');
        $clean->update(['status' => 'completed', 'records_created' => 0, 'completed_at' => now()]);
        $this->assertNull(VentaSyncLog::find($clean->id), 'a clean finish is not worth a row');
        $this->assertSame('completed', $clean->status, 'the caller still reads what happened');

        $withFailures = VentaSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $withFailures->update(['status' => 'completed', 'records_failed' => 2, 'completed_at' => now()]);
        $this->assertNotNull(VentaSyncLog::find($withFailures->id), 'records failed, so it is kept');

        $crashed = VentaSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $crashed->update(['status' => 'failed', 'error_message' => 'boom', 'completed_at' => now()]);
        $this->assertNotNull(VentaSyncLog::find($crashed->id), 'a failure is exactly what this level keeps');

        // "Nothing": even a failure is not written, for a store that is noisy
        // by nature and watched another way.
        Setting::singleton()->update(['sync_log_level' => 'off']);
        $off = VentaSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $off->update(['status' => 'failed', 'completed_at' => now()]);
        $this->assertNull(VentaSyncLog::find($off->id));

        // "Every run" is today's behaviour and stays the default.
        Setting::singleton()->update(['sync_log_level' => 'all']);
        $kept = VentaSyncLog::create(['entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $kept->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertNotNull(VentaSyncLog::find($kept->id));
    }

    /**
     * The controls belong on the tab that shows the log, and a store's own
     * level beats the global default: one busy channel should not have to
     * make every other channel quiet (the owner, 2026-09-01).
     */
    public function test_a_store_sets_its_own_level_and_it_beats_the_default(): void
    {
        Setting::singleton()->update(['sync_log_level' => 'all']);
        $store = \Extensions\venta\Models\VentaSetting::create([
            'store_name' => 'Loud store', 'base_url' => 'https://loud.test', 'api_token' => 't', 'enabled' => true,
        ]);
        $quiet = \Extensions\venta\Models\VentaSetting::create([
            'store_name' => 'Quiet store', 'base_url' => 'https://quiet.test', 'api_token' => 't', 'enabled' => true,
            'sync_log_level' => 'off',
        ]);

        // The default says keep everything, so the store with no opinion does.
        $follows = VentaSyncLog::create(['venta_setting_id' => $store->id, 'entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $follows->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertNotNull(VentaSyncLog::find($follows->id), 'no opinion, so it follows the default');

        // The store that chose 'off' keeps nothing, default or not.
        $own = VentaSyncLog::create(['venta_setting_id' => $quiet->id, 'entity_type' => 'order', 'direction' => 'in', 'status' => 'started']);
        $own->update(['status' => 'completed', 'completed_at' => now()]);
        $this->assertNull(VentaSyncLog::find($own->id), "the store's own level wins");
    }

    public function test_the_sync_tab_carries_the_same_controls_as_the_api_log_tab(): void
    {
        $store = \Extensions\venta\Models\VentaSetting::create([
            'store_name' => 'Depot', 'base_url' => 'https://depot.test', 'api_token' => 't', 'enabled' => true,
        ]);
        DB::table('venta_sync_logs')->insert([
            'venta_setting_id' => $store->id, 'entity_type' => 'order', 'direction' => 'in', 'status' => 'completed',
            'records_processed' => 0, 'records_created' => 0, 'records_updated' => 0, 'records_skipped' => 0, 'records_failed' => 0,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        $group = UserGroup::create(['name' => 'Venta managers']);
        $group->permissions()->attach(Permission::whereIn('key', ['view_venta/settings', 'manage_venta/settings'])->pluck('id')->all());
        $manager = User::factory()->create(['user_group_id' => $group->id]);

        $page = $this->actingAs($manager)->get(route('ext.venta.settings.show', [$store->id, 'tab' => 'sync']))->assertOk();
        $page->assertSee('Recording');
        $page->assertSee('name="sync_log_level"', false);
        $page->assertSee('Follow the default');
        $page->assertSee('Delete all');
        $page->assertSee(route('ext.venta.clear_sync_logs', $store->id), false);

        // Setting a level, then clearing the log, both from that tab.
        $this->actingAs($manager)->post(route('ext.venta.set_sync_log_level', $store->id), ['sync_log_level' => 'failures'])
            ->assertRedirect(route('ext.venta.settings.show', [$store->id, 'tab' => 'sync']));
        $this->assertSame('failures', $store->fresh()->sync_log_level);

        $this->actingAs($manager)->delete(route('ext.venta.clear_sync_logs', $store->id))->assertRedirect();
        $this->assertSame(0, DB::table('venta_sync_logs')->where('venta_setting_id', $store->id)->count());

        // Blank means follow the default again.
        $this->actingAs($manager)->post(route('ext.venta.set_sync_log_level', $store->id), ['sync_log_level' => ''])->assertRedirect();
        $this->assertNull($store->fresh()->sync_log_level);
    }

    public function test_the_settings_page_offers_the_controls_and_purge_now_runs_them(): void
    {
        Setting::singleton()->update(['sync_log_retention_days' => 30, 'api_log_retention_days' => 30]);
        $this->syncRow('completed', 90);
        $manager = $this->manager();

        $page = $this->actingAs($manager)->get(route('settings.website'))->assertOk();
        $page->assertSee('Sync and API logs');
        $page->assertSee('name="sync_log_level"', false);
        $page->assertSee('name="sync_log_retention_days"', false);
        $page->assertSee('name="api_log_retention_days"', false);
        $page->assertSee('Purge now');

        $this->actingAs($manager)->post(route('settings.logs.purge'))
            ->assertRedirect()
            ->assertSessionHas('success');
        $this->assertSame(0, DB::table('venta_sync_logs')->count());

        // Saving from another tab still works: the new required fields are
        // carried, the way every other required field on a tab is.
        $this->actingAs($manager)->get(route('settings.mail'))->assertOk()
            ->assertSee('name="sync_log_level"', false);
    }

    public function test_the_command_honours_an_override_and_reports_what_it_removed(): void
    {
        Setting::singleton()->update(['sync_log_retention_days' => 365]);
        $this->syncRow('completed', 10);

        $this->artisan('logs:purge')->assertSuccessful();
        $this->assertSame(1, DB::table('venta_sync_logs')->count(), 'inside a 365 day retention');

        $this->artisan('logs:purge', ['--sync-days' => 5])->assertSuccessful();
        $this->assertSame(0, DB::table('venta_sync_logs')->count());
    }
}
