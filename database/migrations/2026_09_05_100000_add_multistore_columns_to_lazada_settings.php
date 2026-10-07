<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('lazada_settings', 'store_name')) {
            return;
        }

        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->string('store_name')->nullable()->after('id');
            $table->boolean('enabled')->default(true)->after('store_name');
        });

        DB::table('lazada_settings')->whereNull('store_name')->orderBy('id')->get()
            ->each(function ($row) {
                DB::table('lazada_settings')->where('id', $row->id)->update([
                    'store_name' => $row->account ?: ($row->region ? 'Lazada ' . strtoupper($row->region) : 'Main store'),
                    'enabled' => true,
                ]);
            });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('lazada_settings', 'store_name')) {
            return;
        }
        Schema::table('lazada_settings', function (Blueprint $table) {
            $table->dropColumn(['store_name', 'enabled']);
        });
    }
};
