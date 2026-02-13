<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            // Keep it nullable; Lazada returns a short-lived code that can be overwritten on each authorization.
            if (!Schema::hasColumn('lazada_settings', 'auth_code')) {
                $table->text('auth_code')->nullable()->after('redirect_uri');
            }
        });
    }

    public function down(): void
    {
        Schema::table('lazada_settings', function (Blueprint $table) {
            if (Schema::hasColumn('lazada_settings', 'auth_code')) {
                $table->dropColumn('auth_code');
            }
        });
    }
};
