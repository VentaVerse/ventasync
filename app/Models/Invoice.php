<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Invoice extends Model
{
    protected $table = 'invoices';

    protected $fillable = ['order_id', 'prefix', 'number', 'issued_at', 'issued_by'];

    protected $casts = [
        'number' => 'integer',
        'issued_at' => 'datetime',
    ];

    public static function format(string $prefix, int $number): string
    {
        return $prefix . str_pad((string) $number, 6, '0', STR_PAD_LEFT);
    }

    public function label(): string
    {
        return self::format((string) $this->prefix, (int) $this->number);
    }
}
