<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $adminGroupId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        if (!$adminGroupId) {
            return;
        }

        DB::table('users')->updateOrInsert(
            ['username' => 'admin'],
            [
                'name'          => 'Admin',
                'username'      => 'admin',
                'email'         => 'admin@xenon.local',
                'user_group_id' => $adminGroupId,
                'password'      => Hash::make('admin'),
                'created_at'    => now(),
                'updated_at'    => now(),
            ]
        );
    }
}
