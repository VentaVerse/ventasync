<?php

namespace App\Console\Commands;

use App\Support\Database\SchemaAligner;
use App\Support\Database\SchemaSnapshot;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class UpgradeDatabase extends Command
{
    protected $signature = 'ventasync:upgrade-database
                            {--check : Only check that this database can be upgraded; change nothing}
                            {--force : Run without asking}';

    private const FRAMEWORK_TABLES = ['jobs', 'job_batches', 'failed_jobs', 'sessions', 'cache', 'cache_locks', 'password_reset_tokens'];

    protected $description = 'Move a database built by the old migrations to the clean setup: rename tables and columns, align the schema, and record the new setup files as run';

    public function handle(): int
    {
        $ran = DB::table('migrations')->pluck('migration')->all();
        $baseline = require database_path('upgrades/baseline.php');
        $history = require database_path('upgrades/history.php');

        $done = array_intersect($baseline, $ran);
        if (count($done) === count($baseline)) {
            $this->info('This database already uses the clean setup. Nothing to do.');

            return self::SUCCESS;
        }
        if ($done !== []) {
            $this->error('Some of the new setup files are recorded as run and some are not. Restore the backup taken before the upgrade.');

            return self::FAILURE;
        }

        $missing = array_values(array_diff($history, $ran));
        if ($missing !== []) {
            $this->error(count($missing) . ' earlier migrations have not run here. Deploy the last release before this one, run php artisan migrate, then run this command again. Missing:');
            foreach ($missing as $name) {
                $this->line('  ' . $name);
            }

            return self::FAILURE;
        }

        $this->info('All ' . count($history) . ' earlier migrations have run. This database can be upgraded.');
        if ($this->option('check')) {
            return self::SUCCESS;
        }
        if (! $this->option('force') && ! $this->confirm('Upgrade this database now? Take a backup and put the site in maintenance mode (php artisan down) first.')) {
            return self::FAILURE;
        }

        foreach (glob(database_path('upgrades/steps/*.php')) as $step) {
            $this->line('Step ' . basename($step, '.php'));
            (require $step)->up();
        }

        $target = json_decode((string) file_get_contents(database_path('upgrades/schema.json')), true);
        foreach (SchemaAligner::createMissing(SchemaSnapshot::read(), $target, self::FRAMEWORK_TABLES) as $sql) {
            DB::statement($sql);
            $this->line('Created ' . explode('`', $sql)[1]);
        }
        try {
            $statements = SchemaAligner::statements(SchemaSnapshot::read(), $target);
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());
            $this->error('The renames ran but the schema was not aligned. Restore the backup and report this output.');

            return self::FAILURE;
        }
        foreach ($statements as $sql) {
            DB::statement($sql);
        }
        $this->line('Schema aligned with ' . max(0, count($statements) - 2) . ' changes.');

        $left = SchemaSnapshot::differences(SchemaSnapshot::read(), $target);
        if ($left !== []) {
            foreach ($left as $line) {
                $this->error($line);
            }
            $this->error('The schema still differs from the clean setup. Restore the backup and report this output.');

            return self::FAILURE;
        }

        DB::transaction(function () use ($history, $baseline) {
            DB::table('migrations')->whereIn('migration', $history)->delete();
            $batch = (int) DB::table('migrations')->max('batch') + 1;
            DB::table('migrations')->insert(array_map(fn ($name) => ['migration' => $name, 'batch' => $batch], $baseline));
        });

        $this->info('Done. The database matches the clean setup. Run php artisan migrate for anything newer, then php artisan optimize:clear.');

        return self::SUCCESS;
    }
}
