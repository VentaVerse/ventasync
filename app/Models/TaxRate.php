<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class TaxRate extends Model
{
    protected $table = 'tax_rates';

    protected $fillable = ['name', 'rate', 'is_default'];

    protected $casts = [
        'rate' => 'decimal:4',
        'is_default' => 'boolean',
    ];

    public static function default(): ?self
    {
        return static::where('is_default', true)->first();
    }

    public function percent(): string
    {
        return rtrim(rtrim(number_format((float) $this->rate, 4, '.', ''), '0'), '.') . '%';
    }
}
