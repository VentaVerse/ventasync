<?php

namespace Tests\Feature;

use App\Support\Database\SchemaSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SchemaWordsTest extends TestCase
{
    use RefreshDatabase;

    private const RETIRED = [
        'marketplace' => 'channel (marketplace means only Shopee, Lazada and TikTok)',
        'oc' => 'opencart',
        'inventory' => 'stock',
        'variant' => 'variation',
        'option' => 'variation',
        'vendor' => 'supplier',
        'manufacturer' => 'brand',
        'location' => 'warehouse',
        'platform' => 'channel',
        'model' => 'sku',
        'fulfilment' => 'fulfillment',
        'buyer' => 'customer',
        'erp' => 'sales_order (or say what it holds)',
        'notes' => 'note',
        'comment' => 'note, or customer_note for what the customer wrote',
        'phone' => 'telephone',
        'active' => 'enabled',
    ];

    private const CHANNELS = ['shopee', 'lazada', 'tiktok', 'shopify', 'woocommerce', 'ventacart', 'opencart', 'pedallion'];

    private const ALLOWED = [
        'ch_marketplace_reviews' => 'reviews are collected from Shopee and Lazada, both marketplaces',
        'ch_pedallion_manufacturers' => 'Pedallion is frozen until it gets its real name',
        'ch_pedallion_stores.last_manufacturer_sync_at' => 'Pedallion is frozen until it gets its real name',
        'device_tokens.platform' => 'the phone\'s operating system, not a channel',
        'ch_order_fetch_runs.options' => 'the settings of a run, not a variation',
        'job_batches.options' => 'Laravel\'s own table',
        'scheduled_jobs.options' => 'the settings of a job, not a variation',
        'ch_marketplace_reviews.comment' => 'the words of a review',
        'ch_pedallion_orders.buyer_name' => 'Pedallion is frozen until it gets its real name',
        'ch_pedallion_orders.erp_order_id' => 'Pedallion is frozen until it gets its real name',
    ];

    private const DROPPED = [
        'products' => ['model', 'location', 'stock_status_id', 'tax_class_id', 'weight_class_id', 'length_class_id', 'shipping', 'minimum', 'points', 'viewed', 'date_available', 'config_background_image', 'add_cart', 'product_additionals', 'meta_robots', 'seo_canonical'],
        'orders' => ['fax', 'custom_field', 'affiliate_id', 'marketing_id', 'commission', 'tracking', 'forwarded_ip', 'user_agent', 'accept_language', 'oe_import', 'invoice_prefix', 'store_url', 'customer_id', 'language_id'],
        'order_products' => ['model', 'reward', 'tax'],
    ];

    private function schema(): array
    {
        $schema = SchemaSnapshot::read();
        $this->assertArrayHasKey('products', $schema, 'the schema reader found no tables');

        return $schema;
    }

    public function test_no_table_or_column_uses_a_retired_word(): void
    {
        $offenders = [];

        foreach ($this->schema() as $table => $definition) {
            $names = [$table => $table];
            foreach ($definition['columns'] as $column) {
                $names[$table . '.' . $column['name']] = $column['name'];
            }

            foreach ($names as $where => $name) {
                if (isset(self::ALLOWED[$where]) || $this->isTheChannelsOwnName($name)) {
                    continue;
                }
                $parts = explode('_', $name);
                foreach (self::RETIRED as $word => $instead) {
                    if (in_array($word, $parts, true) || in_array($word . 's', $parts, true) || ($word === 'fulfilment' && str_contains($name, $word))) {
                        $offenders[] = "{$where}: say {$instead}";
                    }
                }
            }
        }

        $this->assertSame([], $offenders, "Retired words are back. See the Naming rules in CONTRIBUTING.md.\n" . implode("\n", $offenders));
    }

    public function test_the_opencart_columns_stay_dropped(): void
    {
        $schema = $this->schema();
        $back = [];

        foreach (self::DROPPED as $table => $columns) {
            $this->assertArrayHasKey($table, $schema);
            $present = array_column($schema[$table]['columns'], 'name');
            foreach (array_intersect($columns, $present) as $column) {
                $back[] = "{$table}.{$column}";
            }
        }

        $this->assertSame([], $back, 'OpenCart columns VentaSync does not read are back: ' . implode(', ', $back));
    }

    private function isTheChannelsOwnName(string $name): bool
    {
        foreach (self::CHANNELS as $channel) {
            if (str_starts_with($name, $channel . '_')) {
                return true;
            }
        }

        return false;
    }
}
