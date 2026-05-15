<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Bootstraps a fresh install with a default admin user so the operator
 * can log in immediately after `php artisan migrate --seed`. The
 * Administrator user group itself is created in migrations; this seeder
 * only inserts the user row.
 *
 * DEFAULT CREDENTIALS (CHANGE THESE IMMEDIATELY AFTER FIRST LOGIN):
 *   username: admin
 *   email:    admin@admin.com
 *   password: admin
 *
 * The seeder is idempotent — re-running it does not overwrite an
 * existing admin user. To rotate the default password, log in and
 * change it through Settings → Users.
 */
class DatabaseSeeder extends Seeder
{
    public function run(): void
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
}
