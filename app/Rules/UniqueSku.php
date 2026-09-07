<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Support\Facades\DB;

class UniqueSku implements ValidationRule
{
    /**
     * @param int|null $excludeProductId Product ID to exclude (for update scenarios)
     */
    public function __construct(private ?int $excludeProductId = null)
    {
    }

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        $sku = trim((string) $value);
        if ($sku === '') {
            return; // Empty SKUs are allowed (nullable)
        }

        $p = (string) config('catalog.prefix');

        // Check product.sku - naming WHO holds it (#185): "another
        // product" sent the operator searching; the name lets them decide.
        $holder = DB::table($p . 'product')->where('sku', $sku);
        if ($this->excludeProductId) {
            $holder->where('product_id', '!=', $this->excludeProductId);
        }
        if ($holderId = $holder->value('product_id')) {
            $fail("The SKU \"{$sku}\" is already used by " . $this->productName((int) $holderId) . '.');
            return;
        }

        // Check product_option_value.sku. A variation row whose product no
        // longer exists holds nothing (a delete used to leave them behind,
        // 2026-09-07); only a living product's variation counts.
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

        // Check product_option_combinations.sku - the third place a SKU
        // lives, and the one the old rule never looked at.
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
