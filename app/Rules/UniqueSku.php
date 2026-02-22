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

        // Check product.sku
        $productQuery = DB::table($p . 'product')->where('sku', $sku);
        if ($this->excludeProductId) {
            $productQuery->where('product_id', '!=', $this->excludeProductId);
        }
        if ($productQuery->exists()) {
            $fail("The SKU \"{$sku}\" is already used by another product.");
            return;
        }

        // Check product_option_value.sku
        $optionQuery = DB::table($p . 'product_option_value')->where('sku', $sku);
        if ($this->excludeProductId) {
            $optionQuery->where('product_id', '!=', $this->excludeProductId);
        }
        if ($optionQuery->exists()) {
            $fail("The SKU \"{$sku}\" is already used by another product's option value.");
            return;
        }
    }
}
