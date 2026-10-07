<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ResetAdminPermissions extends Command
{
    protected $signature = 'permission:reset-admin';
    protected $description = 'Grant all permissions to the Administrator group (emergency recovery)';

    public function handle(): int
    {
        $adminId = DB::table('user_groups')->where('name', 'Administrator')->value('id');

        if (!$adminId) {
            $this->error('Administrator group not found.');
            return 1;
        }

        $permIds = DB::table('permissions')->pluck('id')->all();

        foreach ($permIds as $pid) {
            DB::table('user_group_permissions')->updateOrInsert([
                'user_group_id' => $adminId,
                'permission_id' => $pid,
            ]);
        }

        $this->info('Granted ' . count($permIds) . ' permissions to Administrator group.');

        return 0;
    }
}
