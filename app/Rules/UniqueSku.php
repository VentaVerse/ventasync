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


        $holder = DB::table('products')->where('sku', $sku);
        if ($this->excludeProductId) {
            $holder->where('product_id', '!=', $this->excludeProductId);
        }
        if ($holderId = $holder->value('product_id')) {
            $fail("The SKU \"{$sku}\" is already used by " . $this->productName((int) $holderId) . '.');
            return;
        }

        $variationQuery = DB::table('product_variation_values as pov')
            ->join('products as pp', 'pp.product_id', '=', 'pov.product_id')
            ->where('pov.sku', $sku);
        if ($this->excludeProductId) {
            $variationQuery->where('pov.product_id', '!=', $this->excludeProductId);
        }
        if ($variationHolderId = $variationQuery->value('pov.product_id')) {
            $fail("The SKU \"{$sku}\" is already used by a variation of " . $this->productName((int) $variationHolderId) . '.');
            return;
        }

        $comboQuery = DB::table('product_variations as c')
            ->join('products as cp', 'cp.product_id', '=', 'c.product_id')
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
        $name = DB::table('product_descriptions')
            ->where('product_id', $productId)
            ->where('language_id', (int) config('catalog.default_language_id'))
            ->value('name');

        return $name !== null && trim((string) $name) !== ''
            ? '"' . trim((string) $name) . "\" (#{$productId})"
            : "product #{$productId}";
    }
}
