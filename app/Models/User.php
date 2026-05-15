<?php

namespace App\Models;

use App\Models\Admin\UserGroup;
use App\Services\PermissionHierarchy;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'username',
        'email',
        'user_group_id',
        'password',
        'last_login_at',
        'last_login_ip',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected $casts = [
        'email_verified_at' => 'datetime',
        'last_login_at' => 'datetime',
        'password' => 'hashed',
    ];

    public function userGroup()
    {
        return $this->belongsTo(UserGroup::class, 'user_group_id');
    }

    /**
     * Permission expansion: having a parent permission grants the user
     * all its children; having any child satisfies the parent check
     * (for sidebar visibility). The hierarchy is built dynamically from
     * core config (config/permissions.php) plus each extension manifest's
     * permission declarations — see App\Services\PermissionHierarchy.
     */
    public function hasPermission(string $key): bool
    {
        $group = $this->userGroup;
        if (!$group) {
            return false;
        }

        $hierarchy = app(PermissionHierarchy::class);
        $keysToCheck = [$key];

        // Parent grants access to all children
        $children = $hierarchy->childrenOf($key);
        if (!empty($children)) {
            $keysToCheck = array_merge($keysToCheck, $children);
        }

        // Child grants access to parent (for sidebar visibility)
        $parent = $hierarchy->parentOf($key);
        if ($parent !== null) {
            $keysToCheck[] = $parent;
        }

        // Legacy compatibility for pre-Laravel-rebuild permission key
        if ($key === 'manage_settings' || $key === 'manage_users' || $key === 'manage_user_groups') {
            $keysToCheck[] = 'manage_users_groups';
        }

        return $group->permissions()->whereIn('key', array_unique($keysToCheck))->exists();
    }

    /**
     * Get all effective permissions for this user (with parent→child expansion).
     * Used by the mobile API to determine which nav items to show.
     */
    public function getEffectivePermissions(): array
    {
        $group = $this->userGroup;
        if (!$group) {
            return [];
        }

        $hierarchy = app(PermissionHierarchy::class);
        $assigned = $group->permissions()->pluck('key')->toArray();
        $effective = $assigned;

        foreach ($assigned as $key) {
            $effective = array_merge($effective, $hierarchy->childrenOf($key));
        }

        return array_values(array_unique($effective));
    }
}
