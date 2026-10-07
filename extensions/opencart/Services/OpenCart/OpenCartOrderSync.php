<?php

namespace Extensions\opencart\Services\OpenCart;

use App\Models\Catalog\Order;
use Extensions\opencart\Models\OpenCartOrderStatusMap;
use Extensions\opencart\Models\OpenCartSetting;
use Extensions\opencart\Models\OpenCartSyncLog;
use App\Events\OrderStatusChanged;
use App\Services\OrderStockService;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class OpenCartOrderSync
{
    private OpenCartClient $client;
    private OpenCartSetting $setting;
    private bool $skipStockAdjust = false;

    public function __construct(OpenCartClient $client, OpenCartSetting $setting)
    {
        $this->client = $client;
        $this->setting = $setting;
    }

    public function setSkipStockAdjust(bool $skip = true): self
    {
        $this->skipStockAdjust = $skip;
        return $this;
    }

    public function pull(
        ?string $modifiedSince = null,
        int $startPage = 1,
        ?callable $onProgress = null,
        bool $full = false,
        int $maxPages = 0,
        ?string $modifiedBefore = null
    ): OpenCartSyncLog {
        $log = OpenCartSyncLog::create([
            'opencart_setting_id' => $this->setting->id,
            'entity_type' => 'order',
            'direction'   => 'pull',
            'status'      => 'started',
            'started_at'  => now(),
        ]);

        try {
            $since = $full ? null : ($modifiedSince ?? $this->resolveSinceDate());
            $page = $startPage;
            $limit = 100;
            $created = 0;
            $updated = 0;
            $failed = 0;
            $errors = [];
            $total = 0;

            $statusMap = $this->buildStatusMap();

            do {
                $result = $this->client->getOrders($page, $limit, $since, $modifiedBefore);

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

    private function resolveSinceDate(): ?string
    {
        if ($this->setting->sync_last_days && $this->setting->sync_last_days > 0) {
            return now()->subDays($this->setting->sync_last_days)->startOfDay()->toDateTimeString();
        }

        if ($this->setting->sync_orders_from) {
            return $this->setting->sync_orders_from->startOfDay()->toDateTimeString();
        }

        return null;
    }

    private function buildStatusMap(): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $idMap = [];
        $storeMap = OpenCartOrderStatusMap::where('opencart_setting_id', $this->setting->id)->get();
        foreach ($storeMap as $row) {
            $idMap[(int) $row->oc_status_id] = (int) $row->order_status_id;
        }

        $nameMap = [];
        $rows = DB::table($pfx . 'order_status')
            ->where('language_id', $langId)
            ->select('order_status_id', 'name')
            ->get();
        foreach ($rows as $row) {
            $nameMap[strtolower(trim($row->name))] = (int) $row->order_status_id;
        }

        return ['by_id' => $idMap, 'by_name' => $nameMap];
    }

    private function resolveOrderStatus(array $statusMap, int $ocStatusId, string $statusName): int
    {
        if (isset($statusMap['by_id'][$ocStatusId])) {
            return $statusMap['by_id'][$ocStatusId];
        }

        if ($statusName !== '') {
            $key = strtolower(trim($statusName));
            if (isset($statusMap['by_name'][$key])) {
                return $statusMap['by_name'][$key];
            }
        }

        return $ocStatusId;
    }

    private function upsertOrder(array $raw, array $statusMap): bool
    {
        $pfx = (string) config('catalog.prefix');
        $ocOrderId = (int) ($raw['order_id'] ?? 0);
        if ($ocOrderId <= 0) {
            throw new \RuntimeException('Missing order_id');
        }

        $ocStatusId = (int) ($raw['order_status_id'] ?? 0);
        $statusName = trim($raw['order_status_name'] ?? '');
        $coreStatusId = $this->resolveOrderStatus($statusMap, $ocStatusId, $statusName);

        $created = false;
        $oldStatusId = 0;
        $coreOrderId = null;

        $marketplaceSource = 'opencart:' . $this->setting->id;

        DB::transaction(function () use ($raw, $pfx, $ocOrderId, $coreStatusId, $marketplaceSource, $statusMap, &$created, &$oldStatusId, &$coreOrderId) {
            $existing = DB::table($pfx . 'order')
                ->where('marketplace_source', $marketplaceSource)
                ->where('marketplace_order_id', (string) $ocOrderId)
                ->first();

            if (! $existing) {
                $existing = DB::table($pfx . 'order')
                    ->where('marketplace_source', 'opencart')
                    ->where('marketplace_order_id', (string) $ocOrderId)
                    ->first();
            }

            $ocRate = (float) ($raw['currency_value'] ?? 1);
            $ocTotal = (float) ($raw['total'] ?? 0);

            $defaultCurrencyCode = app(\App\Services\OrderCurrencyService::class)->defaultCode();
            $ocCurrencyCode = trim((string) ($raw['currency_code'] ?? $defaultCurrencyCode));
            if ($ocCurrencyCode === '') {
                $ocCurrencyCode = $defaultCurrencyCode;
            }
            $isDefault = strcasecmp($ocCurrencyCode, $defaultCurrencyCode) === 0;

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
                'payment_cost'           => (float) ($raw['payment_cost'] ?? 0),
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
                'shipping_cost'          => (float) ($raw['shipping_cost'] ?? 0),
                'shipping_code'          => $raw['shipping_code'] ?? '',
                'comment'                => $raw['comment'] ?? '',
                'total'                  => $ocTotal,
                'extra_cost'             => (float) ($raw['extra_cost'] ?? 0),
                'order_status_id'        => $coreStatusId,
                'affiliate_id'           => (int) ($raw['affiliate_id'] ?? 0),
                'commission'             => (float) ($raw['commission'] ?? 0),
                'marketing_id'           => (int) ($raw['marketing_id'] ?? 0),
                'tracking'               => $raw['tracking'] ?? '',
                'language_id'            => (int) ($raw['language_id'] ?? 0),
                'currency_id'            => (int) ($raw['currency_id'] ?? 0),
                'currency_code'          => $raw['currency_code'] ?? $defaultCurrencyCode,
                'currency_value'         => $ocRate > 0 ? round(1 / $ocRate, \App\Services\OrderCurrencyService::RATE_SCALE) : 1.0,
                // foreign_total must stay NULL for a default-currency order; the re-sync guard keys off NULL.
                'foreign_total'          => ($ocRate > 0 && !$isDefault)
                    ? round($ocTotal * $ocRate, \App\Services\OrderCurrencyService::MONEY_SCALE)
                    : null,
                'ip'                     => $raw['ip'] ?? '',
                'forwarded_ip'           => $raw['forwarded_ip'] ?? '',
                'user_agent'             => $raw['user_agent'] ?? '',
                'accept_language'        => $raw['accept_language'] ?? '',
                'courier_id'             => 0,
                'tracking_number'        => '',
                'date_modified'          => (isset($raw['date_modified']) && $raw['date_modified'] !== '0000-00-00 00:00:00') ? $raw['date_modified'] : now()->toDateTimeString(),
                'oe_import'              => 0,
                'marketplace_source'     => $marketplaceSource,
                'marketplace_order_id'   => (string) $ocOrderId,
            ];

            $dateAdded = (isset($raw['date_added']) && $raw['date_added'] !== '0000-00-00 00:00:00') ? $raw['date_added'] : now()->toDateTimeString();

            if ($existing) {
                $coreOrderId = (int) $existing->order_id;
                $oldStatusId = (int) $existing->order_status_id;
                if ((int) ($existing->sync_override ?? 0) === 1) {
                    $orderData['order_status_id'] = $oldStatusId;
                    $oldStatusId = $coreStatusId;
                }
                $orderData['date_added'] = $dateAdded;
                Log::info('OC sync update order', [
                    'oc_order_id'      => $ocOrderId,
                    'core_order_id'    => $coreOrderId,
                    'old_date_added'   => $existing->date_added,
                    'new_date_added'   => $dateAdded,
                    'api_date_added'   => $raw['date_added'] ?? 'NOT SET',
                ]);
                DB::table($pfx . 'order')
                    ->where('order_id', $coreOrderId)
                    ->update($orderData);
            } else {
                $orderData['date_added'] = $dateAdded;
                $coreOrderId = DB::table($pfx . 'order')->insertGetId($orderData, 'order_id');
                $created = true;
            }

            if (isset($raw['products']) && is_array($raw['products'])) {
                DB::table($pfx . 'order_product')
                    ->where('order_id', $coreOrderId)
                    ->delete();

                DB::table($pfx . 'order_option')
                    ->where('order_id', $coreOrderId)
                    ->delete();

                foreach ($raw['products'] as $p) {
                    $coreProductId = $this->resolveProductBySku($pfx, $p);

                    $resolvedOptions = [];
                    $firstMatchedPovId = 0;
                    if ($coreProductId > 0 && isset($p['options']) && is_array($p['options'])) {
                        foreach ($p['options'] as $opt) {
                            $localPovId = 0;
                            $localPoId = 0;
                            $optValue = trim($opt['value'] ?? '');
                            if ($optValue !== '') {
                                $localPov = DB::table($pfx . 'product_option_value as pov')
                                    ->join($pfx . 'option_value_description as ovd', function ($j) use ($pfx) {
                                        $langId = (int) config('catalog.default_language_id');
                                        $j->on('pov.option_value_id', '=', 'ovd.option_value_id')
                                            ->where('ovd.language_id', '=', $langId);
                                    })
                                    ->where('pov.product_id', $coreProductId)
                                    ->where('ovd.name', $optValue)
                                    ->select('pov.product_option_value_id', 'pov.product_option_id')
                                    ->first();

                                if ($localPov) {
                                    $localPovId = (int) $localPov->product_option_value_id;
                                    $localPoId = (int) $localPov->product_option_id;
                                    if ($firstMatchedPovId === 0) {
                                        $firstMatchedPovId = $localPovId;
                                    }
                                }
                            }
                            $resolvedOptions[] = [
                                'localPovId' => $localPovId,
                                'localPoId'  => $localPoId,
                                'name'       => $opt['name'] ?? '',
                                'value'      => $optValue,
                                'type'       => $opt['type'] ?? '',
                            ];
                        }
                    }

                    $productCost = \App\Support\LineCost::resolve((int) $coreProductId, (int) ($firstMatchedPovId ?? 0));

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
                        'cost'       => $productCost,
                    ]);

                    foreach ($resolvedOptions as $ro) {
                        DB::table($pfx . 'order_option')->insert([
                            'order_id'                 => $coreOrderId,
                            'order_product_id'         => $opId,
                            'product_option_id'        => $ro['localPoId'],
                            'product_option_value_id'  => $ro['localPovId'],
                            'name'                     => $ro['name'],
                            'value'                    => $ro['value'],
                            'type'                     => $ro['type'],
                        ]);
                    }
                }
            }

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

            if (isset($raw['history']) && is_array($raw['history']) && $created) {
                foreach ($raw['history'] as $h) {
                    $hOcStatusId = (int) ($h['order_status_id'] ?? 0);
                    $hStatusName = trim($h['order_status_name'] ?? '');
                    $hCoreStatusId = $this->resolveOrderStatus($statusMap, $hOcStatusId, $hStatusName);

                    DB::table($pfx . 'order_history')->insert([
                        'order_id'        => $coreOrderId,
                        'order_status_id' => $hCoreStatusId,
                        'notify'          => (int) ($h['notify'] ?? 0),
                        'comment'         => $h['comment'] ?? '',
                        'date_added'      => (isset($h['date_added']) && $h['date_added'] !== '0000-00-00 00:00:00') ? $h['date_added'] : now()->toDateTimeString(),
                        'user_id'         => null,
                        'user_name'       => 'OpenCart Sync',
                    ]);
                }
            }
        });

        if ($coreOrderId && $oldStatusId !== $coreStatusId) {
            ActivityLogger::log('updated', 'Order', $coreOrderId, 'OpenCart #' . ($raw['order_id'] ?? ''), [
                'order_status_id' => [(string) $oldStatusId, (string) $coreStatusId],
            ], source: 'system');
        }

        if ($coreOrderId && $oldStatusId !== $coreStatusId) {
            OrderStatusChanged::fire($coreOrderId, $oldStatusId, $coreStatusId);
        }

        if (!$this->skipStockAdjust && $coreOrderId && $oldStatusId !== $coreStatusId) {
            try {
                $order = Order::where('order_id', $coreOrderId)->first();
                if ($order) {
                    OrderStockService::adjustStock($order, $oldStatusId, $coreStatusId);
                }
            } catch (\Throwable $e) {
                Log::warning('OC order sync stock adjustment failed', [
                    'order_id' => $coreOrderId,
                    'error'    => $e->getMessage(),
                ]);
            }
        }

        return $created;
    }

    private function resolveProductBySku(string $pfx, array $p): int
    {
        $ocProductId = (int) ($p['product_id'] ?? 0);
        if ($ocProductId > 0) {
            $link = DB::table('opencart_product_links')
                ->where('opencart_setting_id', $this->setting->id)
                ->where('oc_product_id', $ocProductId)
                ->select('product_id')
                ->first();
            if ($link && (int) $link->product_id > 0) {
                return (int) $link->product_id;
            }
        }

        $model = trim($p['model'] ?? '');
        if ($model !== '') {
            $found = DB::table($pfx . 'product')
                ->where('sku', $model)
                ->select('product_id')
                ->first();
            if ($found) return (int) $found->product_id;
        }

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
