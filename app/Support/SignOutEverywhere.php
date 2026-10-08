<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class SignOutEverywhere
{
    public static function apps(User $user): void
    {
        $user->tokens()->delete();

        if (! Schema::hasTable('oauth_access_tokens')) {
            return;
        }

        $ids = DB::table('oauth_access_tokens')->where('user_id', $user->getKey())->where('revoked', false)->pluck('id');
        if ($ids->isEmpty()) {
            return;
        }

        DB::table('oauth_access_tokens')->whereIn('id', $ids)->update(['revoked' => true]);
        if (Schema::hasTable('oauth_refresh_tokens')) {
            DB::table('oauth_refresh_tokens')->whereIn('access_token_id', $ids)->update(['revoked' => true]);
        }
    }
}
