<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Setting extends Model
{
    protected $fillable = [
        'company_name',
        'company_email',
        'phone',
        'address_line1',
        'address_line2',
        'city',
        'state',
        'postal_code',
        'country',
        'from_email',
        'timezone',
        'logo_path',
        'favicon_path',
        'activity_log_retention_days',
        'logo_nav_h', 'logo_login_h',
        'image_thumb_size',
        'image_preview_size',
        'image_push_size',
        'mail_mailer',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
        'mcp_sign_in',
        'packing_check',
    ];

    protected $casts = [
        'mail_password' => 'encrypted',
        'mcp_sign_in' => 'boolean',
        'packing_check' => 'boolean',
    ];

    public static function singleton(): self
    {
        return static::query()->first() ?? static::query()->create([]);
    }
}
