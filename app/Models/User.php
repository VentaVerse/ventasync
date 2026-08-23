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
     * (for sidebar visibility). Keys derive from the route table — see
     * App\Services\PermissionCatalogue; manifests declare none.
     *
     * Tier-aware (view/manage split), shown with a derived key:
     *   - hasPermission('manage_lazada/order')  → checks manage_lazada/order
     *   - hasPermission('view_lazada/order')    → true if view_lazada/order
     *                                             OR manage_lazada/order
     *                                             (manage-implies-view cascade)
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

        // Parent/child cascades intentionally removed. Each permission row
        // is an independent grant — granting the parent does NOT grant the
        // children, and a child grant does NOT satisfy a parent check.
        // Admins toggle each row explicitly; if they Off the parent in the
        // editor, the save handler also revokes the children (kill switch).
        //
        // Only the within-base "manage-implies-view" cascade (handled above
        // by the $base/$effectiveTier expansion) stays — that's tier
        // conversion on the SAME resource key, not a parent/child relation.

        // Legacy compatibility for pre-Laravel-rebuild permission key
        if ($key === 'manage_settings' || $key === 'manage_users' || $key === 'manage_user_groups') {
            $keysToCheck[] = 'manage_users_groups';
        }

        /* TRANSITION BRIDGE - dies in Phase D with LegacyGrantMap.
         *
         * A derived key (the slash is the tell) is also satisfied by any
         * RETIRED key whose frozen translation covers it. Without this, a
         * group the grant migration never reached - restored from an old
         * backup, or built by a test the old way - would pass the door
         * (whose shim reads the same table) and then lose every menu entry
         * and button, because those ask this method directly. One
         * vocabulary bridge, in the one place the docblock above promises
         * tier rules live, so the door and the interface cannot disagree.
         *
         * Tier-aware, never widening: a view-tier ask accepts a legacy key
         * translating to either tier of the base (manage implies view); a
         * manage-tier ask accepts only a legacy key translating to the
         * manage side. */
        if (str_contains($key, '/') && $effectiveTier !== null && $base !== null) {
            $accept = $effectiveTier === 'view'
                ? ['view_' . $base, 'manage_' . $base]
                : ['manage_' . $base];

            foreach (\App\Services\LegacyGrantMap::TRANSLATIONS as $legacy => $derived) {
                if (array_intersect($accept, $derived) !== []) {
                    $keysToCheck[] = $legacy;
                }
            }
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
