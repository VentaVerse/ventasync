<?php

namespace App\Services\OpenCart;

use App\Models\OpenCartSetting;
use App\Models\OpenCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenCartOrderSync
{
    private OpenCartClient $client;
    private OpenCartSetting $setting;

    public function __construct(OpenCartClient $client, OpenCartSetting $setting)
    {
        $this->client = $client;
        $this->setting = $setting;
    }

    /**
     * Pull orders from OpenCart into the local catalog tables.
     * Uses auto-increment IDs. Matches existing orders by marketplace_source + marketplace_order_id.
     * Resolves product_id via SKU lookup. Maps order_status by name.
     */
    public function pull(
        ?string $modifiedSince = null,
        int $startPage = 1,
        ?callable $onProgress = null,
        bool $full = false,
        int $maxPages = 0
    ): OpenCartSyncLog {
        $log = OpenCartSyncLog::create([
            'opencart_setting_id' => $this->setting->id,
            'entity_type' => 'order',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $since = $full ? null : ($modifiedSince ?? $this->setting->last_order_sync_at?->toIso8601String());
            $page = $startPage;
            $limit = 100;
            $created = 0;
            $updated = 0;
            $failed = 0;
            $errors = [];
            $total = 0;

            // Pre-build order status name → id map from core catalog
            $statusMap = $this->buildStatusMap();

            do {
                $result = $this->client->getOrders($page, $limit, $since);

                if (!$result['ok']) {
                    throw new \RuntimeException('API error: ' . json_encode($result['body']));
                }

                $orders = $result['body']['data'] ?? [];
                $pagination = $result['body']['pagination'] ?? [];
                $total = (int) ($pagination['total'] ?? 0);

                foreach ($orders as $raw) {
                    try {
                        $wasCreated = $this->upsertOrder($raw, $statusMap);
                        if ($wasCreated) {
                            $created++;
                        } else {
                            $updated++;
                        }
                    } catch (\Throwable $e) {
                        $failed++;
                        if (count($errors) < 50) {
                            $errors[] = [
                                'order_id' => $raw['order_id'] ?? '?',
                                'error'    => $e->getMessage(),
                            ];
                        }
                    }
                }

                $this->setting->update(['last_order_page' => $page]);

                if ($onProgress) {
                    $onProgress($created + $updated + $failed, $total);
                }

                $pagesProcessed = $page - $startPage + 1;
                $page++;
                $hasMore = $page <= ($pagination['total_pages'] ?? 0);
                if ($maxPages > 0 && $pagesProcessed >= $maxPages) {
                    $hasMore = false;
                }
            } while ($hasMore);

            $this->setting->update([
                'last_order_sync_at' => now(),
                'last_order_page'    => 0,
            ]);

            $log->update([
                'status'            => 'completed',
                'records_processed' => $created + $updated + $failed,
                'records_created'   => $created,
                'records_updated'   => $updated,
                'records_failed'    => $failed,
                'details'           => $errors ?: null,
                'completed_at'      => now(),
            ]);
        } catch (\Throwable $e) {
            $log->update([
                'status'        => 'failed',
                'error_message' => $e->getMessage(),
                'completed_at'  => now(),
            ]);
            Log::error('OpenCart order sync failed', ['error' => $e->getMessage()]);
        }

        return $log;
    }

    /**
     * Build a map of order status name → order_status_id from core catalog.
     */
    private function buildStatusMap(): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $rows = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->select('order_status_id', 'name')
            ->get();

        $map = [];
        foreach ($rows as $row) {
            $map[strtolower(trim($row->name))] = (int) $row->order_status_id;
        }

        return $map;
    }

    /**
     * Upsert a single order from OC API response.
     * Auto-increments order_id. Links by marketplace_source + marketplace_order_id.
     *
     * @return bool True if created, false if updated.
     */
    private function upsertOrder(array $raw, array $statusMap): bool
    {
        $pfx = (string) config('catalog.prefix');
        $ocOrderId = (int) ($raw['order_id'] ?? 0);
        if ($ocOrderId <= 0) {
            throw new \RuntimeException('Missing order_id');
        }

        // Map order_status_id by name if available, fall back to OC's status_id
        $ocStatusId = (int) ($raw['order_status_id'] ?? 0);
        $statusName = trim($raw['order_status_name'] ?? '');
        $coreStatusId = $ocStatusId;
        if ($statusName !== '') {
            $coreStatusId = $statusMap[strtolower($statusName)] ?? $ocStatusId;
        }

        $created = false;

        DB::transaction(function () use ($raw, $pfx, $ocOrderId, $coreStatusId, &$created) {
            // Check if we already have this order via marketplace link
            $existing = DB::table($pfx . 'order')
                ->where('marketplace_source', 'opencart')
                ->where('marketplace_order_id', (string) $ocOrderId)
                ->first();

            $orderData = [
                'invoice_no'             => (int) ($raw['invoice_no'] ?? 0),
                'invoice_prefix'         => $raw['invoice_prefix'] ?? '',
                'store_id'               => (int) ($raw['store_id'] ?? 0),
                'store_name'             => $raw['store_name'] ?? '',
                'store_url'              => $raw['store_url'] ?? '',
                'customer_id'            => (int) ($raw['customer_id'] ?? 0),
                'customer_group_id'      => (int) ($raw['customer_group_id'] ?? 0),
                'firstname'              => $raw['firstname'] ?? '',
                'lastname'               => $raw['lastname'] ?? '',
                'email'                  => $raw['email'] ?? '',
                'telephone'              => $raw['telephone'] ?? '',
                'fax'                    => $raw['fax'] ?? '',
                'custom_field'           => $raw['custom_field'] ?? '',
                'payment_firstname'      => $raw['payment_firstname'] ?? '',
                'payment_lastname'       => $raw['payment_lastname'] ?? '',
                'payment_company'        => $raw['payment_company'] ?? '',
                'payment_address_1'      => $raw['payment_address_1'] ?? '',
                'payment_address_2'      => $raw['payment_address_2'] ?? '',
                'payment_city'           => $raw['payment_city'] ?? '',
                'payment_postcode'       => $raw['payment_postcode'] ?? '',
                'payment_country'        => $raw['payment_country'] ?? '',
                'payment_country_id'     => (int) ($raw['payment_country_id'] ?? 0),
                'payment_zone'           => $raw['payment_zone'] ?? '',
                'payment_zone_id'        => (int) ($raw['payment_zone_id'] ?? 0),
                'payment_address_format' => $raw['payment_address_format'] ?? '',
                'payment_custom_field'   => $raw['payment_custom_field'] ?? '',
                'payment_method'         => $raw['payment_method'] ?? '',
                'payment_cost'           => 0,
                'payment_code'           => $raw['payment_code'] ?? '',
                'shipping_firstname'     => $raw['shipping_firstname'] ?? '',
                'shipping_lastname'      => $raw['shipping_lastname'] ?? '',
                'shipping_company'       => $raw['shipping_company'] ?? '',
                'shipping_address_1'     => $raw['shipping_address_1'] ?? '',
                'shipping_address_2'     => $raw['shipping_address_2'] ?? '',
                'shipping_city'          => $raw['shipping_city'] ?? '',
                'shipping_postcode'      => $raw['shipping_postcode'] ?? '',
                'shipping_country'       => $raw['shipping_country'] ?? '',
                'shipping_country_id'    => (int) ($raw['shipping_country_id'] ?? 0),
                'shipping_zone'          => $raw['shipping_zone'] ?? '',
                'shipping_zone_id'       => (int) ($raw['shipping_zone_id'] ?? 0),
                'shipping_address_format'=> $raw['shipping_address_format'] ?? '',
                'shipping_custom_field'  => $raw['shipping_custom_field'] ?? '',
                'shipping_method'        => $raw['shipping_method'] ?? '',
                'shipping_cost'          => 0,
                'shipping_code'          => $raw['shipping_code'] ?? '',
                'comment'                => $raw['comment'] ?? '',
                'total'                  => (float) ($raw['total'] ?? 0),
                'extra_cost'             => 0,
                'order_status_id'        => $coreStatusId,
                'affiliate_id'           => (int) ($raw['affiliate_id'] ?? 0),
                'commission'             => (float) ($raw['commission'] ?? 0),
                'marketing_id'           => (int) ($raw['marketing_id'] ?? 0),
                'tracking'               => $raw['tracking'] ?? '',
                'language_id'            => (int) ($raw['language_id'] ?? 0),
                'currency_id'            => (int) ($raw['currency_id'] ?? 0),
                'currency_code'          => $raw['currency_code'] ?? 'PHP',
                'currency_value'         => (float) ($raw['currency_value'] ?? 1),
                'ip'                     => $raw['ip'] ?? '',
                'forwarded_ip'           => $raw['forwarded_ip'] ?? '',
                'user_agent'             => $raw['user_agent'] ?? '',
                'accept_language'        => $raw['accept_language'] ?? '',
                'courier_id'             => 0,
                'tracking_number'        => '',
                'date_modified'          => (isset($raw['date_modified']) && $raw['date_modified'] !== '0000-00-00 00:00:00') ? $raw['date_modified'] : now()->toDateTimeString(),
                'oe_import'              => 0,
                'marketplace_source'     => 'opencart',
                'marketplace_order_id'   => (string) $ocOrderId,
            ];

            $coreOrderId = null;

            if ($existing) {
                $coreOrderId = (int) $existing->order_id;
                DB::table($pfx . 'order')
                    ->where('order_id', $coreOrderId)
                    ->update($orderData);
            } else {
                $orderData['date_added'] = (isset($raw['date_added']) && $raw['date_added'] !== '0000-00-00 00:00:00') ? $raw['date_added'] : now()->toDateTimeString();
                $coreOrderId = DB::table($pfx . 'order')->insertGetId($orderData, 'order_id');
                $created = true;
            }

            // Sync order products — resolve product_id via SKU
            if (isset($raw['products']) && is_array($raw['products'])) {
                DB::table($pfx . 'order_product')
                    ->where('order_id', $coreOrderId)
                    ->delete();

                DB::table($pfx . 'order_option')
                    ->where('order_id', $coreOrderId)
                    ->delete();

                foreach ($raw['products'] as $p) {
                    // Resolve product_id via SKU lookup on core product table
                    $coreProductId = $this->resolveProductBySku($pfx, $p);

                    $opId = DB::table($pfx . 'order_product')->insertGetId([
                        'order_id'   => $coreOrderId,
                        'product_id' => $coreProductId,
                        'name'       => $p['name'] ?? '',
                        'model'      => $p['model'] ?? '',
                        'quantity'   => (int) ($p['quantity'] ?? 0),
                        'price'      => (float) ($p['price'] ?? 0),
                        'total'      => (float) ($p['total'] ?? 0),
                        'tax'        => (float) ($p['tax'] ?? 0),
                        'reward'     => (int) ($p['reward'] ?? 0),
                    ]);

                    if (isset($p['options']) && is_array($p['options'])) {
                        foreach ($p['options'] as $opt) {
                            DB::table($pfx . 'order_option')->insert([
                                'order_id'                 => $coreOrderId,
                                'order_product_id'         => $opId,
                                'product_option_id'        => 0,
                                'product_option_value_id'  => 0,
                                'name'                     => $opt['name'] ?? '',
                                'value'                    => $opt['value'] ?? '',
                                'type'                     => $opt['type'] ?? '',
                            ]);
                        }
                    }
                }
            }

            // Sync order totals
            if (isset($raw['totals']) && is_array($raw['totals'])) {
                DB::table($pfx . 'order_total')
                    ->where('order_id', $coreOrderId)
                    ->delete();

                foreach ($raw['totals'] as $t) {
                    DB::table($pfx . 'order_total')->insert([
                        'order_id'   => $coreOrderId,
                        'code'       => $t['code'] ?? '',
                        'title'      => $t['title'] ?? '',
                        'value'      => (float) ($t['value'] ?? 0),
                        'sort_order' => (int) ($t['sort_order'] ?? 0),
                    ]);
                }
            }

            // Sync order history (only on create)
            if (isset($raw['history']) && is_array($raw['history']) && $created) {
                foreach ($raw['history'] as $h) {
                    DB::table($pfx . 'order_history')->insert([
                        'order_id'        => $coreOrderId,
                        'order_status_id' => (int) ($h['order_status_id'] ?? 0),
                        'notify'          => (int) ($h['notify'] ?? 0),
                        'comment'         => $h['comment'] ?? '',
                        'date_added'      => (isset($h['date_added']) && $h['date_added'] !== '0000-00-00 00:00:00') ? $h['date_added'] : now()->toDateTimeString(),
                    ]);
                }
            }
        });

        return $created;
    }

    /**
     * Resolve a core product_id from an order product row.
     * Tries SKU lookup first, then model, then falls back to 0.
     */
    private function resolveProductBySku(string $pfx, array $p): int
    {
        // Try by model (OC often stores the SKU-like identifier in model)
        $model = trim($p['model'] ?? '');
        if ($model !== '') {
            $found = DB::table($pfx . 'product')
                ->where('sku', $model)
                ->select('product_id')
                ->first();
            if ($found) return (int) $found->product_id;
        }

        // Try by name as last resort
        $name = trim($p['name'] ?? '');
        if ($name !== '') {
            $langId = (int) config('catalog.default_language_id');
            $found = DB::table($pfx . 'product_description')
                ->where('name', $name)
                ->where('language_id', $langId)
                ->select('product_id')
                ->first();
            if ($found) return (int) $found->product_id;
        }

        return 0;
    }
}
