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
        'activity_log_retention_days',
        'mail_mailer',
        'mail_host',
        'mail_port',
        'mail_username',
        'mail_password',
        'mail_encryption',
        'mail_from_address',
        'mail_from_name',
    ];

    protected $casts = [
        'mail_password' => 'encrypted',
    ];

    public static function singleton(): self
    {
        return static::query()->first() ?? static::query()->create([]);
    }
}
