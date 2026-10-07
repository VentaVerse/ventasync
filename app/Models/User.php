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

    public function hasPermission(string $key, ?string $tier = null): bool
    {
        $group = $this->userGroup;
        if (!$group) {
            return false;
        }

        $hierarchy = app(PermissionHierarchy::class);

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

        $base = $hierarchy->baseOf($key);
        if ($effectiveTier === 'view' && $base !== null) {
            $keysToCheck[] = 'view_' . $base;
            $keysToCheck[] = 'manage_' . $base;
        } elseif ($effectiveTier === 'manage' && $base !== null) {
            $keysToCheck = ['manage_' . $base];
        }

        return $group->permissions()->whereIn('key', array_unique($keysToCheck))->exists();
    }

    public function getEffectivePermissions(): array
    {
        $group = $this->userGroup;
        if (!$group) {
            return [];
        }

        $effective = $group->permissions()->pluck('key')->toArray();

        $aliases = [];

        foreach ($effective as $key) {
            if (! str_contains($key, '/')) {
                continue;
            }

            [$tier, $base] = explode('_', $key, 2);

            $aliases[] = match ($base) {
                'sales/order' => $tier . '_orders',
                'catalog/product' => $tier . '_products',
                default => null,
            };

            if (str_ends_with($base, '/order')) {
                $channel = substr($base, 0, -strlen('/order'));

                if (! in_array($channel, ['sales', 'api', 'api_v1'], true)) {
                    $aliases[] = $tier . '_' . $channel . '_orders';
                    $aliases[] = 'view_marketplace_api';
                }
            }
        }

        return array_values(array_unique(array_merge($effective, array_filter($aliases))));
    }
}
