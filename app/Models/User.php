<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use App\Models\Admin\UserGroup;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'user_group_id',
        'password',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function userGroup()
    {
        return $this->belongsTo(UserGroup::class, 'user_group_id');
    }

    public function hasPermission(string $key): bool
    {
        $group = $this->userGroup;
        if (!$group) {
            return false;
        }

        // Backward-compatible parent permissions
        $map = [
            'manage_catalog' => ['manage_catalog','manage_products','manage_categories','manage_manufacturers','manage_options'],
            'manage_marketplace_api' => ['manage_marketplace_api','manage_shopee','manage_lazada'],
            'manage_settings' => ['manage_settings','manage_users','manage_user_groups','manage_users_groups'],
            'manage_sales' => ['manage_sales'],
        ];

        $keysToCheck = $map[$key] ?? [$key];

        return $group->permissions()->whereIn('key', $keysToCheck)->exists();
    }
}
