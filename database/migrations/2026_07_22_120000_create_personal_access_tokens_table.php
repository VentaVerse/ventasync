<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sanctum ships this table as an opt-in publishable migration
 * (`vendor:publish --tag=sanctum-migrations`), not an auto-loaded one. This
 * app never published that stub to disk, but it did once run through
 * Sanctum's older auto-loaded migration path, so the `migrations` table
 * still carries `2019_12_14_000001_create_personal_access_tokens_table` at
 * batch 1 (confirmed on sandbox). The migration file behind that row is gone
 * from disk, so from this codebase's point of view the table's creation is
 * untracked, and a freshly migrated database, such as the test schema
 * RefreshDatabase builds, has no `personal_access_tokens` table at all, so
 * `App\Models\ApiClient::createToken()` fails outright.
 *
 * Dated one minute before `create_api_clients_table` (2026_07_22_120001),
 * the first migration that actually depends on this table existing.
 * Column shapes match the vendor stub at
 * vendor/laravel/sanctum/database/migrations/2019_12_14_000001_create_personal_access_tokens_table.php,
 * NOT the deployed table. Verified via SHOW CREATE TABLE on sandbox, the
 * deployed table has `name` as `varchar(255)` (this migration writes
 * `text`) and no index on `expires_at` (this migration adds one). Harmless
 * today because up() is guarded below and never runs its create branch
 * against sandbox/live, but a brand new install built from this migration
 * will end up with a `personal_access_tokens` table that differs from
 * sandbox/live in those two respects.
 * Guarded so this is a no-op wherever the table already exists.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('personal_access_tokens')) {
            return;
        }

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        // Deliberately a no-op, not Schema::dropIfExists('personal_access_tokens').
        //
        // up() only creates this table when it is missing (a fresh install).
        // On sandbox and live the table already exists from Sanctum's old
        // auto-loaded migration, so up() there is a no-op too, but Laravel
        // still records this migration in whatever batch `migrate` ran it
        // in. If down() dropped the table symmetrically, a later
        // `migrate:rollback` on live would DROP a table this migration
        // never created, one holding live ApiClient tokens for active API
        // consumers plus every mobile device session. Before this file
        // existed, no migration could drop that table on a rollback; that
        // safety property has to survive this migration too. Do not restore
        // the "symmetric" dropIfExists() here without first making up()
        // record whether it actually created the table, and gating the
        // drop on that.
    }
};
