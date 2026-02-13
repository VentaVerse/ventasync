<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Add indexes defensively; ignore if they already exist.
        // NOTE: No global DB prefixing is used here by design.

        $statements = [
            // option_description
            "ALTER TABLE `option_description` ADD INDEX `idx_option_description_option_id` (`option_id`)",
            "ALTER TABLE `option_description` ADD INDEX `idx_option_description_language_id` (`language_id`)",

            // option_value
            "ALTER TABLE `option_value` ADD INDEX `idx_option_value_option_id` (`option_id`)",

            // option_value_description
            "ALTER TABLE `option_value_description` ADD INDEX `idx_ovd_option_value_id` (`option_value_id`)",
            "ALTER TABLE `option_value_description` ADD INDEX `idx_ovd_option_id` (`option_id`)",
            "ALTER TABLE `option_value_description` ADD INDEX `idx_ovd_language_id` (`language_id`)",

            // product_option
            "ALTER TABLE `product_option` ADD INDEX `idx_product_option_product_id` (`product_id`)",
            "ALTER TABLE `product_option` ADD INDEX `idx_product_option_option_id` (`option_id`)",

            // product_option_value
            "ALTER TABLE `product_option_value` ADD INDEX `idx_pov_product_option_id` (`product_option_id`)",
            "ALTER TABLE `product_option_value` ADD INDEX `idx_pov_product_id` (`product_id`)",
            "ALTER TABLE `product_option_value` ADD INDEX `idx_pov_option_id` (`option_id`)",
            "ALTER TABLE `product_option_value` ADD INDEX `idx_pov_option_value_id` (`option_value_id`)",
        ];

        foreach ($statements as $sql) {
            try {
                DB::statement($sql);
            } catch (\Throwable $e) {
                // Intentionally ignore:
                // - duplicate key name
                // - table missing (in dev)
                // Keeps migrations idempotent and production-safe.
            }
        }
    }

    public function down(): void
    {
        // Safe no-op: we don't drop indexes automatically to avoid breaking existing installs.
    }
};
