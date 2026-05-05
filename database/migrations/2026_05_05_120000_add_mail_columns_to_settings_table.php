<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->string('mail_mailer', 16)->default('sendmail')->after('from_email');
            $table->string('mail_host', 190)->nullable()->after('mail_mailer');
            $table->unsignedSmallInteger('mail_port')->nullable()->after('mail_host');
            $table->string('mail_username', 190)->nullable()->after('mail_port');
            $table->text('mail_password')->nullable()->after('mail_username');
            $table->string('mail_encryption', 8)->nullable()->after('mail_password');
            $table->string('mail_from_address', 190)->nullable()->after('mail_encryption');
            $table->string('mail_from_name', 190)->nullable()->after('mail_from_address');
        });
    }

    public function down(): void
    {
        Schema::table('settings', function (Blueprint $table) {
            $table->dropColumn([
                'mail_mailer', 'mail_host', 'mail_port', 'mail_username',
                'mail_password', 'mail_encryption', 'mail_from_address', 'mail_from_name',
            ]);
        });
    }
};
