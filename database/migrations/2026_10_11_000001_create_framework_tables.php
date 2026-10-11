<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('name', 255);
            $table->string('username', 255)->nullable();
            $table->string('email', 255);
            $table->unsignedBigInteger('user_group_id')->nullable();
            $table->timestamp('email_verified_at')->nullable();
            $table->string('password', 255);
            $table->unsignedSmallInteger('failed_logins')->default(0);
            $table->timestamp('locked_until')->nullable();
            $table->string('remember_token', 100)->nullable();
            $table->timestamp('last_login_at')->nullable();
            $table->string('last_login_ip', 45)->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->unique(['email'], 'users_email_unique');
            $table->index(['user_group_id'], 'users_user_group_id_index');
            $table->unique(['username'], 'users_username_unique');
        });

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email', 255);
            $table->string('token', 255);
            $table->timestamp('created_at')->nullable();
            $table->primary(['email']);
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id', 255);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity');
            $table->primary(['id']);
            $table->index(['last_activity'], 'sessions_last_activity_index');
            $table->index(['user_id'], 'sessions_user_id_index');
        });

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key', 255);
            $table->mediumText('value');
            $table->integer('expiration');
            $table->primary(['key']);
            $table->index(['expiration'], 'cache_expiration_index');
        });

        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key', 255);
            $table->string('owner', 255);
            $table->integer('expiration');
            $table->primary(['key']);
            $table->index(['expiration'], 'cache_locks_expiration_index');
        });

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue', 255);
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
            $table->index(['queue'], 'jobs_queue_index');
        });

        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id', 255);
            $table->string('name', 255);
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
            $table->primary(['id']);
        });

        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid', 255);
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
            $table->unique(['uuid'], 'failed_jobs_uuid_unique');
        });

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('tokenable_type', 255);
            $table->unsignedBigInteger('tokenable_id');
            $table->text('name');
            $table->string('token', 64);
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->index(['expires_at'], 'personal_access_tokens_expires_at_index');
            $table->unique(['token'], 'personal_access_tokens_token_unique');
            $table->index(['tokenable_type', 'tokenable_id'], 'personal_access_tokens_tokenable_type_tokenable_id_index');
        });

        Schema::create('oauth_clients', function (Blueprint $table) {
            $table->char('id', 36);
            $table->string('owner_type', 255)->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('name', 255);
            $table->string('secret', 255)->nullable();
            $table->string('provider', 255)->nullable();
            $table->text('redirect_uris');
            $table->text('grant_types');
            $table->boolean('revoked');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->primary(['id']);
            $table->index(['owner_type', 'owner_id'], 'oauth_clients_owner_type_owner_id_index');
        });

        Schema::create('oauth_auth_codes', function (Blueprint $table) {
            $table->char('id', 80);
            $table->unsignedBigInteger('user_id');
            $table->char('client_id', 36);
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
            $table->primary(['id']);
            $table->index(['user_id'], 'oauth_auth_codes_user_id_index');
        });

        Schema::create('oauth_access_tokens', function (Blueprint $table) {
            $table->char('id', 80);
            $table->unsignedBigInteger('user_id')->nullable();
            $table->char('client_id', 36);
            $table->string('name', 255)->nullable();
            $table->text('scopes')->nullable();
            $table->boolean('revoked');
            $table->timestamp('created_at')->nullable();
            $table->timestamp('updated_at')->nullable();
            $table->dateTime('expires_at')->nullable();
            $table->primary(['id']);
            $table->index(['user_id'], 'oauth_access_tokens_user_id_index');
        });

        Schema::create('oauth_refresh_tokens', function (Blueprint $table) {
            $table->char('id', 80);
            $table->char('access_token_id', 80);
            $table->boolean('revoked');
            $table->dateTime('expires_at')->nullable();
            $table->primary(['id']);
            $table->index(['access_token_id'], 'oauth_refresh_tokens_access_token_id_index');
        });
    }

    public function down(): void
    {
        Schema::disableForeignKeyConstraints();
        Schema::dropIfExists('oauth_refresh_tokens');
        Schema::dropIfExists('oauth_access_tokens');
        Schema::dropIfExists('oauth_auth_codes');
        Schema::dropIfExists('oauth_clients');
        Schema::dropIfExists('personal_access_tokens');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('cache_locks');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('sessions');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
        Schema::enableForeignKeyConstraints();
    }
};
