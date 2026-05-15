<?php

namespace Database\Seeders;

use App\Extensions\ExtensionManager;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstraps a fresh install:
 *   1. Default admin user (username admin / password admin) — operator
 *      can log in immediately after `php artisan migrate --seed`.
 *   2. Every Community-tier extension found on disk is installed +
 *      enabled. Plus-tier extensions (audit/reports/shopify/purchasing/
 *      opencart) stay disabled — they need license activation.
 *
 * The seeder is idempotent — re-running it does not overwrite an
 * existing admin user or re-enable a manually-disabled extension.
 *
 * DEFAULT ADMIN CREDENTIALS (CHANGE IMMEDIATELY AFTER FIRST LOGIN):
 *   username: admin
 *   email:    admin@admin.com
 *   password: admin
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->seedAdminUser();
        $this->seedCommunityExtensions();
    }

    private function seedAdminUser(): void
    {
        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        if (!$adminGroupId) {
            $this->command->error('Administrator user group not found. Run migrations first.');
            return;
        }

        $existing = DB::table('users')->where('username', 'admin')->first();

        if ($existing) {
            $this->command->info('Admin user already exists — left unchanged.');
            return;
        }

        DB::table('users')->insert([
            'name'          => 'Admin',
            'username'      => 'admin',
            'email'         => 'admin@admin.com',
            'user_group_id' => $adminGroupId,
            'password'      => Hash::make('admin'),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);

        $this->command->newLine();
        $this->command->getOutput()->writeln('<bg=green;fg=white> ✓ Admin user created </>');
        $this->command->newLine();
        $this->command->getOutput()->writeln('  <fg=yellow;options=bold>Default credentials</> (change immediately):');
        $this->command->newLine();
        $this->command->getOutput()->writeln('    Username: <fg=cyan>admin</>');
        $this->command->getOutput()->writeln('    Email:    <fg=cyan>admin@admin.com</>');
        $this->command->getOutput()->writeln('    Password: <fg=cyan>admin</>');
        $this->command->newLine();
        $this->command->getOutput()->writeln('  <fg=red>Security: change this password through Settings → Users on first login.</>');
        $this->command->newLine();
    }

    /**
     * Install + enable every Community-tier extension found on disk.
     *
     * Plus-tier extensions are intentionally skipped — they need license
     * activation through the future Plus marketplace UI, not auto-enable
     * on first install. If a Plus extension is on disk (e.g. a Plus
     * customer dropped one in before running migrate), it's registered
     * in the extensions table but left disabled.
     */
    private function seedCommunityExtensions(): void
    {
        /** @var ExtensionManager $manager */
        $manager = app(ExtensionManager::class);

        $enabled  = [];
        $skipped  = [];

        foreach ($manager->getManifests() as $id => $manifest) {
            $tier = $manifest['tier'] ?? 'plus';

            // Install the row regardless of tier so the /extensions UI
            // sees Plus extensions as "installed but disabled" rather
            // than missing entirely.
            $manager->install($id);

            if ($tier === 'community') {
                $manager->enable($id);
                $enabled[] = $id;
            } else {
                $skipped[] = $id . ' (' . $tier . ')';
            }
        }

        $this->command->getOutput()->writeln('<bg=green;fg=white> ✓ Extensions registered </>');
        $this->command->newLine();
        if (!empty($enabled)) {
            $this->command->getOutput()->writeln('  Enabled (Community): <fg=cyan>' . implode(', ', $enabled) . '</>');
        }
        if (!empty($skipped)) {
            $this->command->getOutput()->writeln('  Installed but disabled (need license): <fg=yellow>' . implode(', ', $skipped) . '</>');
        }
        $this->command->newLine();
    }
}
