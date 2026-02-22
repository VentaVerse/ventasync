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

    /**
     * Parent → children mapping for permission hierarchy.
     * Having a parent grants access to all its children.
     * Having a child grants access to that child (and satisfies the parent check for sidebar visibility).
     */
    public const PERMISSION_GROUPS = [
        'manage_catalog' => ['manage_products', 'manage_categories', 'manage_manufacturers', 'manage_options'],
        'manage_sales' => ['manage_orders', 'manage_order_statuses'],
        'manage_marketplace_api' => ['manage_shopee', 'manage_lazada', 'manage_opencart', 'manage_shopee_orders', 'manage_lazada_orders'],
        'manage_settings' => ['manage_users', 'manage_user_groups', 'manage_website_settings', 'manage_error_log'],
    ];

    public function hasPermission(string $key): bool
    {
        $group = $this->userGroup;
        if (!$group) {
            return false;
        }

        $keysToCheck = [$key];

        // If checking a parent, also accept any of its children
        if (isset(self::PERMISSION_GROUPS[$key])) {
            $keysToCheck = array_merge($keysToCheck, self::PERMISSION_GROUPS[$key]);
        }

        // If checking a child, also accept the parent
        foreach (self::PERMISSION_GROUPS as $parent => $children) {
            if (in_array($key, $children, true)) {
                $keysToCheck[] = $parent;
                break;
            }
        }

        // Legacy compatibility
        if ($key === 'manage_settings' || $key === 'manage_users' || $key === 'manage_user_groups') {
            $keysToCheck[] = 'manage_users_groups';
        }

        return $group->permissions()->whereIn('key', array_unique($keysToCheck))->exists();
    }
}
