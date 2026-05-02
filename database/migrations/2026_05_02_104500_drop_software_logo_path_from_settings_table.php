<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('settings', 'software_logo_path')) {
            return;
        }

        // Preserve a previously-uploaded software logo by promoting it to logo_path
        // when no company logo was set.
        DB::table('settings')
            ->whereNull('logo_path')
            ->whereNotNull('software_logo_path')
            ->update([
                'logo_path' => DB::raw('`software_logo_path`'),
            ]);

        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn('software_logo_path');
        });
    }

    public function down(): void
    {
        if (Schema::hasColumn('settings', 'software_logo_path')) {
            return;
        }

        Schema::table('settings', function (Blueprint $table) {
            $table->string('software_logo_path')->nullable()->after('logo_path');
        });
    }
};
