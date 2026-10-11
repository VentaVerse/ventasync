<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->id();
            $table->string('company_name', 255)->default('Company');
            $table->string('company_email', 255)->nullable();
            $table->string('phone', 32)->nullable();
            $table->string('address_line1', 255)->nullable();
            $table->string('address_line2', 255)->nullable();
            $table->string('city', 128)->nullable();
            $table->string('state', 128)->nullable();
            $table->string('postal_code', 20)->nullable();
            $table->string('country', 128)->nullable();
            $table->string('from_email', 255)->default('no-reply@example.com');
            $table->string('mail_mailer', 16)->default('sendmail');
            $table->string('mail_host', 190)->nullable();
            $table->unsignedSmallInteger('mail_port')->nullable();
            $table->string('mail_username', 190)->nullable();
            $table->text('mail_password')->nullable();
            $table->string('mail_encryption', 8)->nullable();
            $table->string('mail_from_address', 190)->nullable();
            $table->string('mail_from_name', 190)->nullable();
            $table->string('timezone', 64)->default('Asia/Manila');
            $table->string('logo_path', 255)->nullable();
            $table->string('favicon_path', 255)->nullable();
            $table->unsignedSmallInteger('activity_log_retention_days')->default(90);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unsignedSmallInteger('image_thumb_size')->default(200);
            $table->unsignedSmallInteger('image_preview_size')->default(500);
            $table->unsignedSmallInteger('image_push_size')->default(1000);
            $table->unsignedSmallInteger('logo_nav_h')->default(44);
            $table->unsignedSmallInteger('logo_login_h')->default(96);
            $table->boolean('mcp_sign_in')->default(0);
            $table->boolean('packing_check')->default(0);
        });

        Schema::create('currencies', function (Blueprint $table) {
            $table->id();
            $table->string('code', 3);
            $table->string('name', 64);
            $table->string('symbol', 8);
            $table->decimal('exchange_rate', 15, 8)->default(1);
            $table->boolean('is_default')->default(0);
            $table->tinyInteger('status')->default(1);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['code'], 'currencies_code_unique');
        });

        Schema::create('extensions', function (Blueprint $table) {
            $table->string('id', 64);
            $table->string('name', 128);
            $table->string('version', 32)->default('1.0.0');
            $table->text('description')->nullable();
            $table->string('author', 128)->nullable();
            $table->boolean('enabled')->default(0);
            $table->string('license_key', 255)->nullable();
            $table->json('manifest')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
        });

        Schema::create('scheduled_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('command', 128);
            $table->string('display_name', 128);
            $table->string('extension', 64);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->unsignedSmallInteger('cadence_value')->default(15);
            $table->enum('cadence_unit', ['minute', 'hour', 'day'])->default('minute');
            $table->string('cron_expression', 64)->default('*/15 * * * *');
            $table->boolean('enabled')->default(1);
            $table->json('options')->nullable();
            $table->timestamp('last_run_at')->nullable();
            $table->boolean('last_run_ok')->nullable();
            $table->text('last_run_output')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['enabled'], 'scheduled_jobs_enabled_index');
            $table->index(['extension', 'store_id'], 'scheduled_jobs_extension_store_id_index');
        });

        Schema::create('automation_runs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('scheduled_job_id')->nullable();
            $table->string('extension', 32);
            $table->unsignedBigInteger('store_id')->nullable();
            $table->string('subject', 64)->nullable();
            $table->string('label', 32)->default('products');
            $table->json('units')->nullable();
            $table->unsignedInteger('total')->default(0);
            $table->unsignedInteger('done')->default(0);
            $table->unsignedInteger('ok')->default(0);
            $table->unsignedInteger('failed')->default(0);
            $table->string('status', 16)->default('running');
            $table->text('last_error')->nullable();
            $table->json('ledger')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['extension', 'store_id', 'subject', 'status'], 'automation_runs_extension_store_id_subject_status_index');
            $table->index(['scheduled_job_id', 'status'], 'automation_runs_scheduled_job_id_status_index');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('automation_runs');
        Schema::dropIfExists('scheduled_jobs');
        Schema::dropIfExists('extensions');
        Schema::dropIfExists('currencies');
        Schema::dropIfExists('settings');
        Schema::enableForeignKeyConstraints();
    }
};
