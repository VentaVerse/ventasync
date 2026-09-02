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

        // Check product_option_value.sku
        $optionQuery = DB::table($p . 'product_option_value')->where('sku', $sku);
        if ($this->excludeProductId) {
            $optionQuery->where('product_id', '!=', $this->excludeProductId);
        }
        if ($optionHolderId = $optionQuery->value('product_id')) {
            $fail("The SKU \"{$sku}\" is already used by a variation of " . $this->productName((int) $optionHolderId) . '.');
            return;
        }

        // Check product_option_combinations.sku - the third place a SKU
        // lives, and the one the old rule never looked at.
        $comboQuery = DB::table('product_option_combinations')->where('sku', $sku);
        if ($this->excludeProductId) {
            $comboQuery->where('product_id', '!=', $this->excludeProductId);
        }
        if ($comboHolderId = $comboQuery->value('product_id')) {
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
