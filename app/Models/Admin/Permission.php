<?php

namespace App\Models\Admin;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Casts\Attribute;

class Permission extends Model
{
    public $timestamps = false;
    protected $fillable = ['key'];

    /**
     * Resolve label from lang file.
     */
    protected function label(): Attribute
    {
        return Attribute::get(function () {
            $langKey = "permissions.{$this->attributes['key']}.label";
            $translated = __($langKey);

            return $translated !== $langKey
                ? $translated
                : ucwords(str_replace('_', ' ', preg_replace('/^(manage|view)_/', '', $this->attributes['key'])));
        });
    }

    /**
     * Resolve description from lang file.
     */
    protected function description(): Attribute
    {
        return Attribute::get(function () {
            $langKey = "permissions.{$this->attributes['key']}.description";
            $translated = __($langKey);

            return $translated !== $langKey ? $translated : '';
        });
    }

    public function userGroups()
    {
        return $this->belongsToMany(UserGroup::class, 'user_group_permissions', 'permission_id', 'user_group_id');
    }
}
