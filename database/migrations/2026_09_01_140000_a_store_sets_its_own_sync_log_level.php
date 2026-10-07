<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('venta_settings', function (Blueprint $table) {
            $table->string('sync_log_level', 16)->nullable()->after('api_logging');
        });

        foreach (['venta_sync_logs', 'opencart_sync_log', 'shopify_sync_logs'] as $table) {
            if (Schema::hasTable($table) && ! self::hasIndex($table, $table . '_created_at_index')) {
                Schema::table($table, fn (Blueprint $t) => $t->index('created_at'));
            }
        }
    }

    public function down(): void
    {
        Schema::table('venta_settings', function (Blueprint $table) {
            $table->dropColumn('sync_log_level');
        });

        foreach (['venta_sync_logs', 'opencart_sync_log', 'shopify_sync_logs'] as $table) {
            if (Schema::hasTable($table) && self::hasIndex($table, $table . '_created_at_index')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropIndex($table . '_created_at_index'));
            }
        }
    }

    private static function hasIndex(string $table, string $index): bool
    {
        return collect(Schema::getIndexes($table))->contains(fn ($i) => ($i['name'] ?? '') === $index);
    }
};
