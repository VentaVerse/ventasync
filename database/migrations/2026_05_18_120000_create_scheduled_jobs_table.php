<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('scheduled_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('command', 128);
            $table->string('display_name', 128);
            $table->string('integration', 64);
            $table->unsignedBigInteger('store_id')->nullable();

            $table->unsignedSmallInteger('cadence_value')->default(15);
            $table->enum('cadence_unit', ['minute', 'hour', 'day'])->default('minute');
            $table->string('cron_expression', 64)->default('*/15 * * * *');

            $table->boolean('enabled')->default(true);
            $table->json('options')->nullable();

            $table->timestamp('last_run_at')->nullable();
            $table->boolean('last_run_ok')->nullable();
            $table->text('last_run_output')->nullable();

            $table->timestamps();

            $table->index(['integration', 'store_id']);
            $table->index('enabled');
        });

        $enabledExtensions = Schema::hasTable('extensions')
            ? \DB::table('extensions')->where('enabled', true)->pluck('id')->toArray()
            : [];

        $now = now();

        if (in_array('shopee', $enabledExtensions, true)) {
            $this->seedJobs('shopee', null, [
                ['shopee:sync-orders',   'Sync Orders',          15, 'minute', ['no_returns' => true]],
                ['shopee:sync-orders',   'Sync Returns',         30, 'minute', ['returns'    => true]],
                ['shopee:push-stock',    'Push Stock & Price',   30, 'minute', null],
                ['shopee:sync-reviews',  'Sync Reviews',         1,  'hour',   ['days' => 7]],
                ['shopee:refresh-token', 'Refresh Access Token', 12, 'hour',   null],
            ], $now);
        }

        if (in_array('lazada', $enabledExtensions, true)) {
            $this->seedJobs('lazada', null, [
                ['lazada:sync-orders',   'Sync Orders',          15, 'minute', ['no_returns' => true]],
                ['lazada:sync-orders',   'Sync Returns',         30, 'minute', ['returns'    => true]],
                ['lazada:push-stock',    'Push Stock & Price',   30, 'minute', null],
                ['lazada:sync-reviews',  'Sync Reviews',         1,  'hour',   ['days' => 7]],
                ['lazada:refresh-token', 'Refresh Access Token', 12, 'hour',   null],
            ], $now);
        }

        if (in_array('tiktok', $enabledExtensions, true)) {
            $this->seedJobs('tiktok', null, [
                ['tiktok:sync-orders',   'Sync Orders',          15, 'minute', null],
                ['tiktok:push-stock',    'Push Stock & Price',   30, 'minute', null],
                ['tiktok:refresh-token', 'Refresh Access Token', 12, 'hour',   null],
            ], $now);
        }
    }

    protected function seedJobs(string $integration, ?int $storeId, array $rows, $now): void
    {
        $registered = array_keys(\Illuminate\Support\Facades\Artisan::all());
        foreach ($rows as [$cmd, $label, $value, $unit, $options]) {
            if (!in_array($cmd, $registered, true)) {
                continue;
            }
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
