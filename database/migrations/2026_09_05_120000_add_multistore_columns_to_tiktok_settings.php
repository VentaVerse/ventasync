<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

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
