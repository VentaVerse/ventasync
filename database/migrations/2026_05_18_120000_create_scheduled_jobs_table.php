<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Universal admin-editable scheduler. One OS cron entry runs
 * `php artisan schedule:run` every minute; Console\Kernel reads rows
 * from this table and registers each enabled job with Laravel's
 * scheduler at boot, so operators can change cadence in the UI without
 * touching code or the OS crontab.
 *
 * Seeds default jobs for every enabled marketplace extension on first
 * apply, copying `sync_last_days` / `sync_last_days_returns` from each
 * extension's settings table into the matching job's options JSON so
 * the value has a single home going forward.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_jobs', function (Blueprint $table) {
            $table->id();
            // The artisan signature, e.g. "shopee:sync-orders".
            $table->string('command', 128);
            // Human-readable label rendered in the Automations tab.
            $table->string('display_name', 128);
            // Logical grouping for the UI ("shopee", "lazada", "opencart"...).
            // Multi-store integrations also stamp store_id for per-store scoping.
            $table->string('integration', 64);
            $table->unsignedBigInteger('store_id')->nullable();

            // Simple cadence model (the UI inputs "every N {minutes|hours|days}").
            $table->unsignedSmallInteger('cadence_value')->default(15);
            $table->enum('cadence_unit', ['minute', 'hour', 'day'])->default('minute');
            // Derived cron expression (e.g. "*/15 * * * *"). We persist this so
            // schedule registration is a constant-time read instead of recomputing
            // every minute.
            $table->string('cron_expression', 64)->default('*/15 * * * *');

            $table->boolean('enabled')->default(true);
            // Free-form per-job arguments — sync-orders uses {"days": 7},
            // sync-reviews uses {"days_returns": 30}, refresh-token has none.
            $table->json('options')->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->boolean('last_run_ok')->nullable();
            $table->text('last_run_output')->nullable();

            $table->timestamps();

            $table->index(['integration', 'store_id']);
            $table->index('enabled');
        });

        // Seed default jobs for every enabled marketplace extension.
        // We only seed for extensions that are currently enabled so a fresh
        // install with everything disabled doesn't get a wall of inert rows.
        $enabledExtensions = Schema::hasTable('extensions')
            ? \DB::table('extensions')->where('enabled', true)->pluck('id')->toArray()
            : [];

        $now = now();

        // Sync Orders and Sync Returns share one artisan command but are
        // exposed as TWO jobs (each with its own cadence) — operators
        // typically want orders pulled more aggressively than returns. The
        // lookback windows (`--days`, `--days-returns`) are NOT seeded into
        // job options because they live globally on the integration's
        // settings table (the sync command falls back to those when no CLI
        // override is passed); the Automations tab exposes them as a
        // dedicated "Sync windows" block at the top of the page.

        // Shopee (single-store).
        if (in_array('shopee', $enabledExtensions, true)) {
            $this->seedJobs('shopee', null, [
                ['shopee:sync-orders',   'Sync Orders',          15, 'minute', ['no_returns' => true]],
                ['shopee:sync-orders',   'Sync Returns',         30, 'minute', ['returns'    => true]],
                ['shopee:push-stock',    'Push Stock & Price',   30, 'minute', null],
                ['shopee:sync-reviews',  'Sync Reviews',         1,  'hour',   ['days' => 7]],
                ['shopee:refresh-token', 'Refresh Access Token', 12, 'hour',   null],
            ], $now);
        }

        // Lazada (single-store).
        if (in_array('lazada', $enabledExtensions, true)) {
            $this->seedJobs('lazada', null, [
                ['lazada:sync-orders',   'Sync Orders',          15, 'minute', ['no_returns' => true]],
                ['lazada:sync-orders',   'Sync Returns',         30, 'minute', ['returns'    => true]],
                ['lazada:push-stock',    'Push Stock & Price',   30, 'minute', null],
                ['lazada:sync-reviews',  'Sync Reviews',         1,  'hour',   ['days' => 7]],
                ['lazada:refresh-token', 'Refresh Access Token', 12, 'hour',   null],
            ], $now);
        }

        // TikTok (single-store).
        if (in_array('tiktok', $enabledExtensions, true)) {
            $this->seedJobs('tiktok', null, [
                ['tiktok:sync-orders',   'Sync Orders',          15, 'minute', null],
                ['tiktok:push-stock',    'Push Stock & Price',   30, 'minute', null],
                ['tiktok:refresh-token', 'Refresh Access Token', 12, 'hour',   null],
            ], $now);
        }
    }

    /**
     * Inserts a batch of jobs for one integration/store, skipping any that
     * already exist (so re-running the migration on a partial DB is safe)
     * and any whose underlying artisan command isn't registered (so
     * extensions that haven't shipped a given command don't get phantom
     * rows).
     */
    protected function seedJobs(string $integration, ?int $storeId, array $rows, $now): void
    {
        $registered = array_keys(\Illuminate\Support\Facades\Artisan::all());
        foreach ($rows as [$cmd, $label, $value, $unit, $options]) {
            if (!in_array($cmd, $registered, true)) {
                continue;
            }
            // Dedupe by (command, integration, store_id, display_name) so the
            // same artisan command can produce two distinct rows (e.g.,
            // "Sync Orders" + "Sync Returns" both invoke `*:sync-orders`).
            $exists = \DB::table('scheduled_jobs')
                ->where('command', $cmd)
                ->where('integration', $integration)
                ->where('store_id', $storeId)
                ->where('display_name', $label)
                ->exists();
            if ($exists) {
                continue;
            }
            \DB::table('scheduled_jobs')->insert([
                'command'         => $cmd,
                'display_name'    => $label,
                'integration'     => $integration,
                'store_id'        => $storeId,
                'cadence_value'   => $value,
                'cadence_unit'    => $unit,
                'cron_expression' => self::expressionFor($value, $unit),
                'enabled'         => true,
                'options'         => $options ? json_encode($options) : null,
                'created_at'      => $now,
                'updated_at'      => $now,
            ]);
        }
    }

    public static function expressionFor(int $value, string $unit): string
    {
        $value = max(1, $value);
        switch ($unit) {
            case 'minute':
                return $value === 1 ? '* * * * *' : "*/{$value} * * * *";
            case 'hour':
                return $value === 1 ? '0 * * * *' : "0 */{$value} * * *";
            case 'day':
                return $value === 1 ? '0 3 * * *' : "0 3 */{$value} * *";
            default:
                return '*/15 * * * *';
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('scheduled_jobs');
    }
};
