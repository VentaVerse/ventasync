<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('channel_webhook_events', function (Blueprint $table) {
            $table->id();
            $table->string('channel', 24)->index();
            $table->string('event_type', 64)->nullable()->index();
            $table->json('payload');
            $table->string('signature', 512)->nullable();
            $table->boolean('signature_ok')->default(false)->index();
            $table->timestamp('received_at');
            $table->timestamp('processed_at')->nullable()->index();
            $table->string('outcome', 255)->nullable();
        });

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
