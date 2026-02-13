<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'username')) {
                $table->string('username')->nullable()->unique()->after('name');
            }
            if (!Schema::hasColumn('users', 'user_group_id')) {
                $table->unsignedBigInteger('user_group_id')->nullable()->after('email');
                $table->index('user_group_id');
            }
        });

        // Assign existing users to Administrator group if empty
        $adminId = DB::table('user_groups')->where('name','Administrator')->value('id');
        if ($adminId) {
            DB::table('users')->whereNull('user_group_id')->update(['user_group_id' => $adminId]);
        }

        // Backfill username from email (before @) when missing
        $users = DB::table('users')->whereNull('username')->get(['id','email']);
        foreach ($users as $u) {
            $base = $u->email ? explode('@', $u->email)[0] : ('user'.$u->id);
            $candidate = $base;
            $i = 1;
            while (DB::table('users')->where('username', $candidate)->where('id','!=',$u->id)->exists()) {
                $candidate = $base.$i;
                $i++;
            }
            DB::table('users')->where('id',$u->id)->update(['username' => $candidate]);
        }
    }

    public function down(): void
    {
        // no auto-drop
    }
};
