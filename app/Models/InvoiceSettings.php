<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class InvoiceSettings extends Model
{
    public const VAT = 'vat';
    public const NON_VAT = 'non_vat';

    protected $table = 'invoice_settings';

    protected $attributes = [
        'tax_status' => self::VAT,
        'tin' => '',
        'branch_code' => '00000',
        'business_style' => '',
        'prefix' => 'INV-',
        'print_logo' => true,
    ];

    protected $fillable = ['tax_status', 'tin', 'branch_code', 'business_style', 'prefix', 'print_logo'];

    protected $casts = [
        'print_logo' => 'boolean',
    ];

    public static function current(): self
    {
        return static::query()->first() ?? static::create([]);
    }

    public function isVat(): bool
    {
        return $this->tax_status === self::VAT;
    }

    public function fullTin(): string
    {
        $tin = preg_replace('/\D/', '', (string) $this->tin);
        if (strlen($tin) !== 9) {
            return '';
        }

        return substr($tin, 0, 3) . '-' . substr($tin, 3, 3) . '-' . substr($tin, 6, 3) . '-' . ($this->branch_code ?: '00000');
    }
}
