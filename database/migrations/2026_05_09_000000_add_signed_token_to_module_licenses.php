<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('module_licenses', function (Blueprint $table) {
            // The cryptographically-signed JWT issued by the license server.
            // Verified locally on every isModuleLicensed() call using the
            // embedded public key. Tampering with module_licenses rows no
            // longer bypasses the check because the signature won't match.
            $table->text('signed_token')->nullable()->after('license_key');
            $table->timestamp('token_issued_at')->nullable()->after('signed_token');
        });
    }

    public function down(): void
    {
        Schema::table('module_licenses', function (Blueprint $table) {
            $table->dropColumn(['signed_token', 'token_issued_at']);
        });
    }
};
