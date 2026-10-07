<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueSku implements ValidationRule
{
    public function __construct(private ?int $excludeProductId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $sku = trim((string) $value);
        if ($sku === '') {
            return;
        }

        $p = (string) config('catalog.prefix');

        $holder = DB::table($p . 'product')->where('sku', $sku);
        if ($this->excludeProductId) {
            $holder->where('product_id', '!=', $this->excludeProductId);
        }
        if ($holderId = $holder->value('product_id')) {
            $fail("The SKU \"{$sku}\" is already used by " . $this->productName((int) $holderId) . '.');
            return;
        }

        $optionQuery = DB::table($p . 'product_option_value as pov')
            ->join($p . 'product as pp', 'pp.product_id', '=', 'pov.product_id')
            ->where('pov.sku', $sku);
        if ($this->excludeProductId) {
            $optionQuery->where('pov.product_id', '!=', $this->excludeProductId);
        }
        if ($optionHolderId = $optionQuery->value('pov.product_id')) {
            $fail("The SKU \"{$sku}\" is already used by a variation of " . $this->productName((int) $optionHolderId) . '.');
            return;
        }

        $comboQuery = DB::table('product_option_combinations as c')
            ->join($p . 'product as cp', 'cp.product_id', '=', 'c.product_id')
            ->where('c.sku', $sku);
        if ($this->excludeProductId) {
            $comboQuery->where('c.product_id', '!=', $this->excludeProductId);
        }
        if ($comboHolderId = $comboQuery->value('c.product_id')) {
            $fail("The SKU \"{$sku}\" is already used by a variation of " . $this->productName((int) $comboHolderId) . '.');
            return;
        }
    }

    private function productName(int $productId): string
    {
        $name = DB::table((string) config('catalog.prefix') . 'product_description')
            ->where('product_id', $productId)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->value('name');

        return $name !== null && trim((string) $name) !== ''
            ? '"' . trim((string) $name) . "\" (#{$productId})"
            : "product #{$productId}";
    }
}
