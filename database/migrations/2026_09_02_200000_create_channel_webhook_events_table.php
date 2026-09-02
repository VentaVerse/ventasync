<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The webhook inbox (Phase E, #184) - and the FIRST migration of the
 * whole channels rebuild: phases B through D shipped on columns that
 * already existed, and this table exists because webhooks are genuinely
 * new. Nothing else changes shape.
 *
 * Design: the public endpoint verifies and STORES, a scheduled command
 * processes. Two reasons. First, the marketplaces expect a fast 2xx and
 * the server runs no queue worker (HestiaCP, cron-only) - a store is
 * fast, a process might not be. Second, honesty: an event that fails
 * signature verification is kept with signature_ok = false rather than
 * dropped, so if a marketplace's signing scheme differs from the
 * documented one, the traffic is in the inbox to diagnose and nothing
 * was lost - only never acted on.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 24)->index();
            $table->string('event_type', 64)->nullable()->index();
            $table->json('payload');
            // The signature header as received, for diagnosing a mismatch.
            $table->string('signature', 512)->nullable();
            $table->boolean('signature_ok')->default(false)->index();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable()->index();
            $table->string('outcome', 255)->nullable();
        });

        // The processor rides the universal admin scheduler, every minute.
        if (Schema::hasTable('scheduled_jobs')) {
            $exists = DB::table('scheduled_jobs')->where('command', 'webhooks:process')->exists();
            if (!$exists) {
                DB::table('scheduled_jobs')->insert([
                    'command' => 'webhooks:process', 'display_name' => 'Process Channel Webhooks',
                    'integration' => 'core', 'store_id' => null,
                    'cadence_value' => 1, 'cadence_unit' => 'minute', 'cron_expression' => '* * * * *',
                    'enabled' => true, 'options' => json_encode([]),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('channel_webhook_events');
        if (Schema::hasTable('scheduled_jobs')) {
            DB::table('scheduled_jobs')->where('command', 'webhooks:process')->delete();
        }
    }
};
