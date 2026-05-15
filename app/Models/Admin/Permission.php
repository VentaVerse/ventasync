<?php

namespace App\Models\Admin;

use App\Services\PermissionHierarchy;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Model;

class Permission extends Model
{
    public $timestamps = false;
    protected $fillable = ['key'];

    /**
     * Resolve label. Precedence:
     *   1. Owning extension's lang file (`ext-{owner}::permissions.{key}.label`)
     *      — extension fully owns its display strings, multi-locale ready.
     *   2. Core lang file (`permissions.{key}.label`) — used only for
     *      core-declared permissions (Catalog, Sales, Settings, etc).
     *   3. Humanized key as final fallback.
     */
    protected function label(): Attribute
    {
        return Attribute::get(function () {
            $key = $this->attributes['key'];
            $owner = app(PermissionHierarchy::class)->ownerOf($key);

            if ($owner !== null) {
                $nsKey = "ext-{$owner}::permissions.{$key}.label";
                $translated = __($nsKey);
                if ($translated !== $nsKey) {
                    return $translated;
                }
            }

            $coreKey = "permissions.{$key}.label";
            $translated = __($coreKey);
            if ($translated !== $coreKey) {
                return $translated;
            }

            return ucwords(str_replace('_', ' ', preg_replace('/^(manage|view)_/', '', $key)));
        });
    }

    /**
     * Resolve description with the same precedence as label().
     */
    protected function description(): Attribute
    {
        return Attribute::get(function () {
            $key = $this->attributes['key'];
            $owner = app(PermissionHierarchy::class)->ownerOf($key);

            if ($owner !== null) {
                $nsKey = "ext-{$owner}::permissions.{$key}.description";
                $translated = __($nsKey);
                if ($translated !== $nsKey) {
                    return $translated;
                }
            }

            $coreKey = "permissions.{$key}.description";
            $translated = __($coreKey);
            if ($translated !== $coreKey) {
                return $translated;
            }

            return '';
        });
    }

    public function userGroups()
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_permissions', 'permission_id', 'user_group_id');
    }
}
