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
     *
     * Tier-aware (Phase 1 of the view/manage split):
     *   - hasPermission('manage_lazada')          → checks manage_lazada
     *   - hasPermission('manage_lazada', 'manage') → same, explicit
     *   - hasPermission('manage_lazada', 'view')   → also true if user has manage_lazada
     *                                                (manage-implies-view cascade)
     *   - hasPermission('view_lazada')            → true if view_lazada OR manage_lazada
     *   - hasPermission('view_lazada', 'view')    → same as above
     *   - hasPermission('view_lazada', 'manage')  → true ONLY if user has manage_lazada
     *
     * The "manage implies view" cascade lives here ONLY. Middleware, Blade
     * directives, and Policies all delegate to this method, so we never want
     * to replicate the rule elsewhere — that would risk drift.
     *
     * @param  string       $key   Permission key (may carry view_/manage_ prefix).
     * @param  string|null  $tier  Optional 'view' | 'manage'. When null, the tier
     *                             is inferred from the key prefix. Unknown values
     *                             fall through to a plain key check (defensive).
     */
    public function hasPermission(string $key, ?string $tier = null): bool
    {
        $group = $this->userGroup;
        if (!$group) {
            return false;
        }

        $hierarchy = app(PermissionHierarchy::class);

        // Resolve tier semantics. Tier is determined by:
        //   1. explicit $tier argument (when 'view' or 'manage')
        //   2. otherwise, infer from the key prefix (view_X → view, manage_X → manage)
        //   3. if neither, no tier expansion (legacy / top-level group keys)
        $effectiveTier = null;
        if ($tier === 'view' || $tier === 'manage') {
            $effectiveTier = $tier;
        } else {
            if (str_starts_with($key, 'view_')) {
                $effectiveTier = 'view';
            } elseif (str_starts_with($key, 'manage_')) {
                $effectiveTier = 'manage';
            }
        }

        $keysToCheck = [$key];

        // Tier-aware expansion: if the caller is asking for the VIEW tier of a
        // resource, manage_<base> also satisfies it ("manage implies view").
        // For the MANAGE tier we never accept view_<base> — read-only is not
        // a substitute for full control.
        $base = $hierarchy->baseOf($key);
        if ($effectiveTier === 'view' && $base !== null) {
            $keysToCheck[] = 'view_' . $base;
            $keysToCheck[] = 'manage_' . $base;
        } elseif ($effectiveTier === 'manage' && $base !== null) {
            // Caller may have passed the view_X key but asked explicitly for
            // the manage tier — only the manage_<base> grant counts.
            $keysToCheck = ['manage_' . $base];
        }

        // Parent grants access to all children (legacy hierarchical expansion).
        // Run this for each candidate key so manage_lazada → manage_lazada_orders etc.
        foreach ($keysToCheck as $candidate) {
            $children = $hierarchy->childrenOf($candidate);
            if (!empty($children)) {
                $keysToCheck = array_merge($keysToCheck, $children);
            }
        }

        // Child grants access to parent (for sidebar visibility). Only the
        // originally-requested key participates here — we don't want a user
        // who holds the parent of view_X to inadvertently satisfy manage_X.
        $parent = $hierarchy->parentOf($key);
        if ($parent !== null) {
            $keysToCheck[] = $parent;
            // Tier-aware parent cascade: granting view_<parent> (e.g. view_catalog)
            // means "view access to the whole domain" and must satisfy view-tier
            // checks on every child resource. Only add the parent's view sibling
            // when the caller is asking for the view tier — never widen a
            // manage-tier check to accept the parent's view-only grant.
            $parentBase = $hierarchy->baseOf($parent);
            if ($parentBase !== null && $effectiveTier === 'view') {
                $keysToCheck[] = 'view_' . $parentBase;
            }
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
