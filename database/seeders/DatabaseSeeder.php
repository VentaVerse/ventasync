<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Reference data is created via migrations (default order statuses,
     * Administrator user_group, etc.). The admin USER is created by the
     * web installer wizard at /install/admin (or the agila:install CLI),
     * which prompts for real credentials — not seeded here.
     *
     * If you need to add seed data later, register it via Artisan
     * `Artisan::call('db:seed', ['--class' => 'YourSeeder'])` from the
     * appropriate context, or call $this->call(YourSeeder::class) here.
     */
    public function run(): void
    {
        // Intentionally empty. The installer creates the admin user.
    }
}
