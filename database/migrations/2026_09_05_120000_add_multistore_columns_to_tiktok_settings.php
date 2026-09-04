<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-store TikTok, step 1 (the owner, 2026-09-05): a settings row becomes a
 * STORE. Two columns the Integrations board needs - a human name the operator
 * and the MCP recognise the store by, and an enabled flag so a store can be
 * created disabled and switched on once configured. The one existing row keeps
 * working: it is named and enabled by the backfill. Mirrors the Lazada step
 * (add_multistore_columns_to_lazada_settings). See memory
 * lazada-tables-lacked-store-key.
 */
return new class extends Migration {
    public function up(): void
    {
        if (! Schema::hasTable('tiktok_settings')) {
            return;
        }

        Schema::table('tiktok_settings', function (Blueprint $table) {
            if (! Schema::hasColumn('tiktok_settings', 'store_name')) {
                $table->string('store_name', 128)->nullable()->after('id');
            }
            if (! Schema::hasColumn('tiktok_settings', 'enabled')) {
                $table->boolean('enabled')->default(true)->after('store_name');
            }
        });

        // Name and enable the existing row so the store list never shows an
        // anonymous, silently-off entry.
        DB::table('tiktok_settings')->get()->each(function ($row) {
            $name = trim((string) ($row->store_name ?? '')) !== ''
                ? $row->store_name
                : (trim((string) ($row->shop_name ?? '')) !== ''
                    ? $row->shop_name
                    : (trim((string) ($row->region ?? '')) !== '' ? 'TikTok ' . strtoupper((string) $row->region) : 'Main store'));

            DB::table('tiktok_settings')->where('id', $row->id)->update([
                'store_name' => $name,
                'enabled'    => true,
            ]);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('tiktok_settings')) {
            return;
        }

        Schema::table('tiktok_settings', function (Blueprint $table) {
            foreach (['enabled', 'store_name'] as $col) {
                if (Schema::hasColumn('tiktok_settings', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
