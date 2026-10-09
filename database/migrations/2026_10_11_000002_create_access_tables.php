<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_groups', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['name'], 'user_groups_name_unique');
        });

        Schema::create('permissions', function (Blueprint $table) {
            $table->id();
            $table->string('key', 255);
            $table->unique(['key'], 'permissions_key_unique');
        });

        Schema::create('user_group_permissions', function (Blueprint $table) {
            $table->unsignedBigInteger('user_group_id');
            $table->unsignedBigInteger('permission_id');
            $table->primary(['user_group_id', 'permission_id']);
            $table->index(['permission_id'], 'user_group_permissions_permission_id_index');
        });

        Schema::create('api_clients', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('description', 255)->nullable();
            $table->boolean('active')->default(1);
            $table->boolean('mcp_enabled')->default(0);
            $table->string('mcp_client', 32)->nullable();
            $table->char('oauth_client_id', 36)->nullable();
            $table->json('allowed_ips')->nullable();
            $table->unsignedSmallInteger('calls_per_minute')->nullable();
            $table->text('token_secret')->nullable();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['active'], 'api_clients_active_index');
            $table->unique(['oauth_client_id'], 'api_clients_oauth_client_id_unique');
        });

        Schema::create('api_request_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('api_client_id')->nullable();
            $table->string('method', 10);
            $table->string('path', 255);
            $table->string('scope', 255)->nullable();
            $table->unsignedSmallInteger('status')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->index(['api_client_id'], 'api_request_logs_api_client_id_index');
            $table->index(['created_at'], 'api_request_logs_created_at_index');
        });

        Schema::create('app_idempotency_keys', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('idempotency_key', 100);
            $table->char('fingerprint', 64);
            $table->unsignedSmallInteger('status_code')->nullable();
            $table->longText('response')->nullable();
            $table->timestamp('created_at')->useCurrent();
            $table->index(['created_at'], 'app_idempotency_keys_created_at_index');
            $table->unique(['user_id', 'idempotency_key'], 'app_idempotency_keys_user_id_idempotency_key_unique');
        });

        Schema::create('device_tokens', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id');
            $table->string('token', 255);
            $table->string('platform', 20)->default('android');
            $table->string('device_name', 80)->nullable();
            $table->string('os_version', 40)->nullable();
            $table->string('app_version', 20)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['token'], 'device_tokens_token_unique');
        });

        Schema::create('activity_logs', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('user_name', 100)->nullable();
            $table->unsignedBigInteger('api_client_id')->nullable();
            $table->string('action', 50);
            $table->string('subject_type', 100)->nullable();
            $table->unsignedBigInteger('subject_id')->nullable();
            $table->string('subject_label', 255)->nullable();
            $table->json('changes')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('source', 20)->default('user');
            $table->timestamp('created_at')->useCurrent();
            $table->index(['action'], 'activity_logs_action_index');
            $table->index(['created_at'], 'activity_logs_created_at_index');
            $table->index(['source'], 'activity_logs_source_index');
            $table->index(['subject_type', 'subject_id'], 'activity_logs_subject_type_subject_id_index');
            $table->index(['user_id'], 'activity_logs_user_id_index');
        });

        Schema::table('device_tokens', function (Blueprint $table) {
            $table->foreign('user_id', 'device_tokens_user_id_foreign')->references('id')->on('users')->onDelete('cascade');
        });

        Schema::table('activity_logs', function (Blueprint $table) {
            $table->foreign('api_client_id', 'activity_logs_api_client_id_foreign')->references('id')->on('api_clients')->onDelete('set null');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('activity_logs');
        Schema::dropIfExists('device_tokens');
        Schema::dropIfExists('app_idempotency_keys');
        Schema::dropIfExists('api_request_logs');
        Schema::dropIfExists('api_clients');
        Schema::dropIfExists('user_group_permissions');
        Schema::dropIfExists('permissions');
        Schema::dropIfExists('user_groups');
        Schema::enableForeignKeyConstraints();
    }
};
