<?php

namespace Extensions\installer\Console\Commands;

use Extensions\installer\Middleware\InstallationMiddleware;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Cloud-mode install bypass.
 *
 * Skips the interactive /install web wizard. Reads AGILA_ADMIN_EMAIL +
 * AGILA_ADMIN_PASSWORD_HASH from the environment (set by AgilaCP when
 * provisioning a Coolify tenant), creates the first admin user, and drops
 * the installed.lock marker so InstallationMiddleware stops redirecting.
 *
 * Idempotent — if any users already exist, the command no-ops. Safe to
 * re-run on every container restart, which is exactly what the entrypoint
 * does.
 *
 * Invoked from docker/entrypoint.sh when AGILA_INSTALL_BYPASS=true.
 * Not exposed to humans; not documented in the web wizard flow.
 */
class InstallBypass extends Command
{
    protected $signature = 'agila:install-bypass';
    protected $description = 'Bootstrap admin user from AGILA_ADMIN_EMAIL + AGILA_ADMIN_PASSWORD_HASH env vars (Cloud entrypoint use only)';

    public function handle(): int
    {
        $adminEmail = env('AGILA_ADMIN_EMAIL');
        $adminPasswordHash = env('AGILA_ADMIN_PASSWORD_HASH');

        if (empty($adminEmail) || empty($adminPasswordHash)) {
            $this->error('AGILA_ADMIN_EMAIL and AGILA_ADMIN_PASSWORD_HASH must be set.');
            return self::FAILURE;
        }

        if (!str_starts_with($adminPasswordHash, '$2y$') && !str_starts_with($adminPasswordHash, '$2b$')) {
            $this->error('AGILA_ADMIN_PASSWORD_HASH does not look like a bcrypt hash. Expected $2y$ or $2b$ prefix.');
            return self::FAILURE;
        }

        if (!Schema::hasTable('users')) {
            $this->error('users table does not exist. Run migrations first (AGILA_AUTO_MIGRATE=true).');
            return self::FAILURE;
        }

        if (DB::table('users')->exists()) {
            $this->info('Users already exist — install bypass is a no-op.');
            $this->ensureMarker();
            return self::SUCCESS;
        }

        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');
        if (!$adminGroupId) {
            $adminGroupId = DB::table('user_groups')->insertGetId([
                'name' => 'Administrator',
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            $this->warn('Administrator user_group was missing — created it. Migrations may not have run.');
        }

        $username = strtolower(explode('@', $adminEmail)[0]);

        $userId = DB::table('users')->insertGetId([
            'name' => 'Administrator',
            'username' => $username,
            'email' => $adminEmail,
            'password' => $adminPasswordHash,
            'user_group_id' => $adminGroupId,
            'email_verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->info("Admin user created: {$adminEmail} (id={$userId}, group={$adminGroupId})");

        $this->ensureMarker();

        return self::SUCCESS;
    }

    /**
     * Drop storage/agila/installed.lock so InstallationMiddleware stops
     * redirecting to /install.
     */
    private function ensureMarker(): void
    {
        InstallationMiddleware::markInstalled('installed via Cloud bypass');
        $this->info('Marker file written: ' . InstallationMiddleware::markerPath());
    }
}
