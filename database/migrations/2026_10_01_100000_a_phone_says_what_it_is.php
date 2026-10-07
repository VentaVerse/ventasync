<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            if (! Schema::hasColumn('device_tokens', 'device_name')) {
                $table->string('device_name', 80)->nullable()->after('platform');
            }
            if (! Schema::hasColumn('device_tokens', 'os_version')) {
                $table->string('os_version', 40)->nullable()->after('device_name');
            }
            if (! Schema::hasColumn('device_tokens', 'app_version')) {
                $table->string('app_version', 20)->nullable()->after('os_version');
            }
        });
    }

    public function down(): void
    {
        Schema::table('device_tokens', function (Blueprint $table) {
            $table->dropColumn(['device_name', 'os_version', 'app_version']);
        });
    }
};
