<?php

namespace Extensions\ventacart\Services\VentaCart;

use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderOption;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Services\OrderStockService;
use App\Events\OrderStatusChanged;
use App\Services\ActivityLogger;
use Extensions\ventacart\Models\VentaCartOrder;
use Extensions\ventacart\Models\VentaCartOrderProduct;
use Extensions\ventacart\Models\VentaCartOrderStatusMap;
use Extensions\ventacart\Models\VentaCartSetting;
use Extensions\ventacart\Models\VentaCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VentaCartOrderSync
{
    private VentaCartClient $client;
    private VentaCartSetting $setting;
    private bool $skipStockAdjust = false;

    private bool $feesOnly = false;

    public function __construct(VentaCartClient $client, VentaCartSetting $setting)
    {
        $this->client = $client;
        $this->setting = $setting;
    }

    public function setSkipStockAdjust(bool $skip = true): self
    {
        $this->skipStockAdjust = $skip;
        return $this;
    }

    public function setFeesOnly(bool $only = true): self
    {
        $this->feesOnly = $only;

        return $this;
    }

    public ?int $lastTotalPages = null;

    public function pull(
        ?string $since = null,
        ?callable $onProgress = null,
        bool $full = false,
        int $maxPages = 0,
        int $startPage = 1
    ): VentaCartSyncLog {
        $log = VentaCartSyncLog::create([
            'ventacart_setting_id' => $this->setting->id,
            'entity_type'      => 'order',
            'direction'        => 'pull',
            'status'           => 'started',
            'started_at'       => now(),
        ]);

        try {
            $this->refreshPlacements();

            $sinceDate = $full ? null : ($since ?? $this->resolveSinceDate());
            $page = max(1, $startPage);
            $perPage = 100;
            $created = 0;
            $updated = 0;
            $failed = 0;
            $errors = [];

            $statusMap = $this->buildStatusMap();

            do {
                $result = $this->client->getOrders($perPage, $sinceDate, null, $page);

                if (!$result['ok']) {
                    throw new \RuntimeException('API error: ' . json_encode($result['body']));
                }

                $orders = $result['body']['data'] ?? [];
                // The storefront returns a bare paginator, so last_page is top level (meta only if wrapped); accept both.
                $body = $result['body'];
                $lastPage = $body['meta']['last_page'] ?? $body['last_page'] ?? 1;
                $this->lastTotalPages = (int) $lastPage;

                foreach ($orders as $raw) {
                    try {
                        $orderId = (int) ($raw['id'] ?? 0);
                        if ($orderId > 0) {
                            $detailResult = $this->client->getOrder($orderId);
                            if (($detailResult['ok'] ?? false) && is_array($detailResult['body'])) {
                                $raw = array_merge($raw, $detailResult['body']);
                            }
                        }

                        $wasCreated = $this->upsertOrder($raw, $statusMap);
                        $wasCreated ? $created++ : $updated++;
                    } catch (\Throwable $e) {
                        $failed++;
                        if (count($errors) < 50) {
                            $errors[] = [
                                'order_id' => $raw['id'] ?? '?',
                                'error'    => $e->getMessage(),
                            ];
                        }
                    }

                    usleep(200000);
                }

                if ($onProgress) {
                    $onProgress($created + $updated + $failed);
                }

                $page++;
                $hasMore = $page <= $lastPage;
                if ($maxPages > 0 && ($page - max(1, $startPage)) >= $maxPages) {
                    $hasMore = false;
                }
            } while ($hasMore);

            $this->setting->update(['last_order_sync_at' => now()]);

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
            Log::error('VentaCart order sync failed', [
                'store' => $this->setting->store_name,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    private function resolveSinceDate(): ?string
    {
        if ($this->setting->sync_last_days && $this->setting->sync_last_days > 0) {
            return now()->subDays($this->setting->sync_last_days)->startOfDay()->toIso8601String();
        }

        if ($this->setting->sync_orders_from) {
            return $this->setting->sync_orders_from->startOfDay()->toIso8601String();
        }

        return null;
    }

    private function buildStatusMap(): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $idMap = [];
        $storeMap = VentaCartOrderStatusMap::where('ventacart_setting_id', $this->setting->id)->get();
        foreach ($storeMap as $row) {
            $idMap[(int) $row->ventacart_status_id] = (int) $row->order_status_id;
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

    private function resolveOrderStatus(array $statusMap, int $ventaCartStatusId, string $statusName): int
    {
        if (isset($statusMap['by_id'][$ventaCartStatusId])) {
            return $statusMap['by_id'][$ventaCartStatusId];
        }

        if ($statusName !== '') {
            $key = strtolower(trim($statusName));
            if (isset($statusMap['by_name'][$key])) {
                return $statusMap['by_name'][$key];
            }
        }

        return $ventaCartStatusId;
    }

    public function pullOne(int $ventaCartOrderId): ?VentaCartOrder
    {
        $this->refreshPlacements();

        $res = $this->client->getOrder($ventaCartOrderId);
        $raw = $res['body'] ?? [];

        if (! ($res['ok'] ?? false) || ! is_array($raw) || (int) ($raw['id'] ?? 0) !== $ventaCartOrderId) {
            return null;
        }

        $this->upsertOrder($raw, $this->buildStatusMap());

        return VentaCartOrder::where('ventacart_setting_id', $this->setting->id)
            ->where('ventacart_order_id', $ventaCartOrderId)
            ->first();
    }

    private function refreshPlacements(): void
    {
        try {
            VentaCartStatusPlacements::refresh($this->setting, $this->client);
        } catch (\Throwable $e) {
            Log::warning('VentaCart status placements could not be refreshed', [
                'store' => $this->setting->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Only follow the payload when it carries the courier object; a storefront that omits it must not blank the ERP's booking.
    public static function courierFieldsFrom(array $raw): array
    {
        $courier = $raw['courier'] ?? null;
        if (! is_array($courier)) {
            return [];
        }

        $manual = (bool) ($courier['manual'] ?? false);

        return [
            'courier_provider'        => $manual ? VentaCartOrder::MANUAL_PROVIDER : ($courier['provider'] ?? null),
            'courier_name'            => $manual ? ($courier['manual_courier'] ?? null) : null,
            'courier_tracking_number' => $courier['tracking_number'] ?? null,
            'courier_status'          => $manual ? 'manual' : ($courier['status'] ?? null),
            'courier_booked_at'       => $courier['booked_at'] ?? null,
        ];
    }

    private function upsertOrder(array $raw, array $statusMap): bool
    {
        $pfx = (string) config('catalog.prefix');
        $ventaCartOrderId = (int) ($raw['id'] ?? 0);
        if ($ventaCartOrderId <= 0) {
            throw new \RuntimeException('Missing order id');
        }

        $ventaCartStatusId = (int) ($raw['status_id'] ?? 0);
        $statusName = trim($raw['status'] ?? '');
        $coreStatusId = $this->resolveOrderStatus($statusMap, $ventaCartStatusId, $statusName);

        $marketplaceSource = 'ventacart:' . $this->setting->id;

        $ventaCartOrder = VentaCartOrder::updateOrCreate(
            [
                'ventacart_setting_id' => $this->setting->id,
                'ventacart_order_id'   => $ventaCartOrderId,
            ],
            [
                'ventacart_order_number' => $raw['order_number'] ?? null,
                'status'             => $statusName,
                'status_id'          => $ventaCartStatusId,
                'customer_name'      => trim(($raw['contact']['name'] ?? '') ?: ($raw['customer']['name'] ?? '')),
                'customer_email'     => $raw['contact']['email'] ?? ($raw['customer']['email'] ?? ''),
                'customer_group'     => self::customerGroupFrom($raw),
                'total'              => (float) ($raw['totals']['total'] ?? 0),
                'payment_method'     => $raw['payment_method'] ?? '',
                'shipping_method'    => $raw['shipping_method'] ?? '',
                'tracking_number'    => $raw['tracking_number'] ?? '',
                'shipping_address'   => $raw['shipping_address'] ?? null,
                'raw'                => $raw,
                'order_created_at'   => $raw['created_at'] ?? null,
                'order_updated_at'   => $raw['updated_at'] ?? null,
            ] + self::courierFieldsFrom($raw)
        );

        $wasCreated = $ventaCartOrder->wasRecentlyCreated;

        VentaCartOrderProduct::where('ventacart_order_id', $ventaCartOrder->id)->delete();
        foreach (($raw['items'] ?? []) as $item) {
            VentaCartOrderProduct::create([
                'ventacart_order_id' => $ventaCartOrder->id,
                'sku'            => $item['sku'] ?? '',
                'name'           => $item['name'] ?? '',
                'variant_label'  => $item['variant_label'] ?? '',
                'quantity'       => (int) ($item['quantity'] ?? 1),
                'price'          => (float) ($item['price'] ?? 0),
                'total'          => (float) ($item['total'] ?? 0),
                'raw'            => $item,
            ]);
        }

        if ($this->feesOnly) {
            if ($ventaCartOrder->catalog_order_id) {
                app(\App\Services\Orders\MarketplaceFeeNormalizer::class)->forOrder((int) $ventaCartOrder->catalog_order_id);
            }

            return $wasCreated;
        }

        $this->syncToCatalog($ventaCartOrder, $raw, $coreStatusId, $marketplaceSource, $statusMap);

        return $wasCreated;
    }

    private function syncToCatalog(VentaCartOrder $ventaCartOrder, array $raw, int $coreStatusId, string $marketplaceSource, array $statusMap): void
    {
        $pfx = (string) config('catalog.prefix');
        $ventaCartOrderId = (string) $ventaCartOrder->ventacart_order_id;

        $contact = $raw['contact'] ?? [];
        $customer = $raw['customer'] ?? [];
        $shipping = $raw['shipping_address'] ?? [];
        $billing = is_array($raw['billing_address'] ?? null) ? $raw['billing_address'] : $shipping;
        $totals = $raw['totals'] ?? [];

        $firstname = trim($shipping['first_name'] ?? ($contact['name'] ?? ($customer['name'] ?? '')));
        $lastname = trim($shipping['last_name'] ?? '');
        $email = trim($contact['email'] ?? ($customer['email'] ?? ''));
        $phone = trim($contact['phone'] ?? '');

        $oldStatusId = 0;
        $coreOrderId = null;
        $created = false;

        DB::transaction(function () use (
            $raw, $pfx, $ventaCartOrderId, $coreStatusId, $marketplaceSource,
            $firstname, $lastname, $email, $phone,
            $shipping, $billing, $totals, $ventaCartOrder,
            &$oldStatusId, &$coreOrderId, &$created
        ) {
            $existing = DB::table($pfx . 'order')
                ->where('marketplace_source', $marketplaceSource)
                ->where('marketplace_order_id', $ventaCartOrderId)
                ->first();

            $currencySvc = app(\App\Services\OrderCurrencyService::class);
            $currency = $currencySvc->resolve($currencySvc->defaultCode());
            // Once an order is normalized its currency_value is a frozen rate; a re-sync must not overwrite it
            // with today's rate, nor apply it if the order is no longer foreign.
            $rate = $currency['rate'];
            $isDefault = $currency['is_default'];
            if ($existing && !$isDefault && $existing->foreign_total !== null) {
                $rate = (float) $existing->currency_value;
            }
            $moneyScale = \App\Services\OrderCurrencyService::MONEY_SCALE;

            $orderData = [
                'invoice_no'             => 0,
                'invoice_prefix'         => '',
                'store_id'               => 0,
                'store_name'             => $this->setting->store_name,
                'store_url'              => $this->setting->base_url,
                'customer_id'            => 0,
                'customer_group_id'      => 0,
                'customer_group'         => self::customerGroupFrom($raw),
                'firstname'              => $firstname,
                'lastname'               => $lastname,
                'email'                  => $email,
                'telephone'              => $phone,
                'fax'                    => '',
                'custom_field'           => '',
                'payment_firstname'      => trim($billing['first_name'] ?? $firstname),
                'payment_lastname'       => trim($billing['last_name'] ?? $lastname),
                'payment_company'        => '',
                'payment_address_1'      => trim(implode(', ', array_filter([$billing['address_1'] ?? '', $billing['barangay'] ?? '']))),
                'payment_address_2'      => trim($billing['district'] ?? ''),
                'payment_city'           => trim($billing['city'] ?? ''),
                'payment_postcode'       => trim($billing['postcode'] ?? ''),
                'payment_country'        => trim($billing['country_code'] ?? ''),
                'payment_country_id'     => 0,
                'payment_zone'           => trim($billing['state'] ?? ''),
                'payment_zone_id'        => 0,
                'payment_address_format' => '',
                'payment_custom_field'   => '',
                'payment_method'         => $raw['payment_method'] ?? '',
                'payment_cost'           => 0,
                'payment_code'           => '',
                'shipping_firstname'     => $firstname,
                'shipping_lastname'      => $lastname,
                'shipping_company'       => '',
                'shipping_address_1'     => trim(implode(', ', array_filter([$shipping['address_1'] ?? '', $shipping['barangay'] ?? '']))),
                'shipping_address_2'     => trim($shipping['district'] ?? ''),
                'shipping_city'          => trim($shipping['city'] ?? ''),
                'shipping_postcode'      => trim($shipping['postcode'] ?? ''),
                'shipping_country'       => trim($shipping['country_code'] ?? ''),
                'shipping_country_id'    => 0,
                'shipping_zone'          => trim($shipping['state'] ?? ''),
                'shipping_zone_id'       => 0,
                'shipping_address_format'=> '',
                'shipping_custom_field'  => '',
                'shipping_method'        => $raw['shipping_method'] ?? '',
                'shipping_cost'          => 0,
                'shipping_code'          => '',
                'comment'                => $raw['comment'] ?? '',
                'total'                  => \App\Services\OrderCurrencyService::toBase((float) ($totals['total'] ?? ($totals['subtotal'] ?? 0)), $rate),
                'foreign_total'          => $isDefault ? null : round((float) ($totals['total'] ?? ($totals['subtotal'] ?? 0)), $moneyScale),
                'extra_cost'             => 0,
                'order_status_id'        => $coreStatusId,
                'affiliate_id'           => 0,
                'commission'             => 0,
                'marketing_id'           => 0,
                'tracking'               => '',
                'language_id'            => 1,
                'currency_id'            => $currency['id'],
                'currency_code'          => $currency['code'],
                'currency_value'         => $rate,
                'ip'                     => '',
                'forwarded_ip'           => '',
                'user_agent'             => '',
                'accept_language'        => '',
                'courier_id'             => 0,
                'tracking_number'        => $raw['tracking_number'] ?? '',
                'marketplace_source'     => $marketplaceSource,
                'marketplace_order_id'   => $ventaCartOrderId,
                'oe_import'              => 0,
                'date_modified'          => isset($raw['updated_at']) ? \Carbon\Carbon::parse($raw['updated_at'])->format('Y-m-d H:i:s') : now()->toDateTimeString(),
            ];

            if (array_key_exists('tracking_link', $raw)) {
                $orderData['tracking_url'] = \App\Models\Catalog\Order::cleanTrackingUrl($raw['tracking_link'] ?? null);
            }

            if ($existing) {
                $coreOrderId = (int) $existing->order_id;
                $oldStatusId = (int) $existing->order_status_id;
                if ((int) ($existing->sync_override ?? 0) === 1) {
                    $orderData['order_status_id'] = $oldStatusId;
                    $oldStatusId = $coreStatusId;
                }
                $orderData['date_added'] = $existing->date_added;
                unset($orderData['shipping_cost']);
                DB::table($pfx . 'order')
                    ->where('order_id', $coreOrderId)
                    ->update($orderData);
            } else {
                $orderData['date_added'] = isset($raw['created_at']) ? \Carbon\Carbon::parse($raw['created_at'])->format('Y-m-d H:i:s') : now()->toDateTimeString();
                $coreOrderId = DB::table($pfx . 'order')->insertGetId($orderData, 'order_id');
                $created = true;
            }

            $items = $raw['items'] ?? [];
            DB::table($pfx . 'order_product')->where('order_id', $coreOrderId)->delete();
            DB::table($pfx . 'order_option')->where('order_id', $coreOrderId)->delete();

            $subTotal = 0;
            foreach ($items as $item) {
                $sku = trim($item['sku'] ?? '');
                $qty = (int) ($item['quantity'] ?? 1);
                $price = (float) ($item['price'] ?? 0);
                $lineTotal = (float) ($item['total'] ?? ($price * $qty));
                $subTotal += $lineTotal;

                $priceBase = \App\Services\OrderCurrencyService::toBase($price, $rate);
                $lineTotalBase = \App\Services\OrderCurrencyService::toBase($lineTotal, $rate);

                $resolvedProductId = 0;
                $resolvedOptionValueId = 0;

                if ($sku !== '') {
                    $pov = DB::table($pfx . 'product_option_value')
                        ->where('sku', $sku)
                        ->first();
                    if ($pov) {
                        $resolvedProductId = (int) $pov->product_id;
                        $resolvedOptionValueId = (int) $pov->product_option_value_id;
                    }

                    if ($resolvedProductId === 0) {
                        $combo = DB::table('product_option_combinations as poc')
                            ->where('poc.sku', $sku)
                            ->first(['poc.product_id', 'poc.id as combination_id']);
                        if ($combo) {
                            $resolvedProductId = (int) $combo->product_id;
                            $comboValue = DB::table('product_option_combination_values')
                                ->where('combination_id', $combo->combination_id)
                                ->first(['product_option_value_id']);
                            if ($comboValue) {
                                $resolvedOptionValueId = (int) $comboValue->product_option_value_id;
                            }
                        }
                    }

                    if ($resolvedProductId === 0) {
                        $prod = DB::table($pfx . 'product')
                            ->where('sku', $sku)
                            ->first(['product_id']);
                        if ($prod) {
                            $resolvedProductId = (int) $prod->product_id;
                        }
                    }

                    if ($resolvedProductId === 0) {
                        $link = DB::table('ventacart_product_links')
                            ->where('ventacart_setting_id', $this->setting->id)
                            ->where('sku', $sku)
                            ->first();
                        if ($link) {
                            $resolvedProductId = (int) $link->product_id;
                        }
                    }
                }

                $productCost = \App\Support\LineCost::resolve((int) $resolvedProductId, (int) $resolvedOptionValueId);

                $opId = DB::table($pfx . 'order_product')->insertGetId([
                    'order_id'      => $coreOrderId,
                    'product_id'    => $resolvedProductId,
                    'name'          => $item['name'] ?? '',
                    'model'         => $sku,
                    'quantity'      => $qty,
                    'price'         => $priceBase,
                    'foreign_price' => $isDefault ? null : round($price, $moneyScale),
                    'total'         => $lineTotalBase,
                    'foreign_total' => $isDefault ? null : round($lineTotal, $moneyScale),
                    'tax'           => 0,
                    'reward'        => 0,
                    'cost'          => $productCost,
                ]);

                $variantLabel = trim($item['variant_label'] ?? '');
                if ($variantLabel !== '') {
                    DB::table($pfx . 'order_option')->insert([
                        'order_id'                 => $coreOrderId,
                        'order_product_id'         => $opId,
                        'product_option_id'        => 0,
                        'product_option_value_id'  => $resolvedOptionValueId,
                        'name'                     => 'Option',
                        'value'                    => $variantLabel,
                        'type'                     => 'text',
                    ]);
                }
            }

            DB::table($pfx . 'order_total')->where('order_id', $coreOrderId)
                ->whereNotIn('code', array_column(\App\Services\Orders\MarketplaceFeeNormalizer::CODES, 0))->delete();
            $sortOrder = 1;
            foreach (VentaCartSaleLines::lines(is_array($totals) ? $totals : [], $subTotal, (float) ($totals['shipping'] ?? 0)) as $line) {
                DB::table($pfx . 'order_total')->insert([
                    'order_id'   => $coreOrderId,
                    'code'       => $line['code'],
                    'title'      => $line['title'],
                    'value'      => \App\Services\OrderCurrencyService::toBase($line['value'], $rate),
                    'sort_order' => $sortOrder++,
                ]);
            }

            if ($created || $oldStatusId !== $coreStatusId) {
                DB::table($pfx . 'order_history')->insert([
                    'order_id'        => $coreOrderId,
                    'order_status_id' => $coreStatusId,
                    'notify'          => 0,
                    'comment'         => 'Synced from VentaCart (status: ' . ($raw['status'] ?? 'unknown') . ')',
                    'date_added'      => now()->toDateTimeString(),
                    'user_id'         => null,
                    'user_name'       => 'VentaCart Sync',
                ]);
            }

            $ventaCartOrder->update(['catalog_order_id' => $coreOrderId]);

            app(\App\Services\Orders\MarketplaceFeeNormalizer::class)->forOrder($coreOrderId);
        });

        if ($coreOrderId && $oldStatusId !== $coreStatusId) {
            ActivityLogger::log('updated', 'Order', $coreOrderId, 'VentaCart #' . $ventaCartOrderId, [
                'order_status_id' => [(string) $oldStatusId, (string) $coreStatusId],
            ], source: 'system');

            OrderStatusChanged::fire($coreOrderId, $oldStatusId, $coreStatusId);

            if (!$this->skipStockAdjust) {
                try {
                    $order = Order::where('order_id', $coreOrderId)->first();
                    if ($order) {
                        OrderStockService::adjustStock($order, $oldStatusId, $coreStatusId);
                    }
                } catch (\Throwable $e) {
                    Log::warning('VentaCart order sync stock adjustment failed', [
                        'order_id' => $coreOrderId,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }
    }

    public static function customerGroupFrom(array $raw): ?string
    {
        $group = $raw['customer_group'] ?? null;
        if (is_array($group)) {
            $group = $group['name'] ?? null;
        }
        $group = trim((string) $group);

        return $group === '' ? null : mb_substr($group, 0, 64);
    }
}
