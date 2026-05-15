<?php

namespace Extensions\venta\Services\Venta;

use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderOption;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Services\OrderStockService;
use App\Services\FcmService;
use App\Services\ActivityLogger;
use Extensions\venta\Models\VentaOrder;
use Extensions\venta\Models\VentaOrderProduct;
use Extensions\venta\Models\VentaOrderStatusMap;
use Extensions\venta\Models\VentaSetting;
use Extensions\venta\Models\VentaSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VentaOrderSync
{
    private VentaClient $client;
    private VentaSetting $setting;
    private bool $skipStockAdjust = false;

    public function __construct(VentaClient $client, VentaSetting $setting)
    {
        $this->client = $client;
        $this->setting = $setting;
    }

    public function setSkipStockAdjust(bool $skip = true): self
    {
        $this->skipStockAdjust = $skip;
        return $this;
    }

    /**
     * Pull orders from Venta into local venta_orders + core catalog tables.
     */
    public function pull(
        ?string $since = null,
        ?callable $onProgress = null,
        bool $full = false,
        int $maxPages = 0
    ): VentaSyncLog {
        $log = VentaSyncLog::create([
            'venta_setting_id' => $this->setting->id,
            'entity_type'      => 'order',
            'direction'        => 'pull',
            'status'           => 'started',
            'started_at'       => now(),
        ]);

        try {
            $sinceDate = $full ? null : ($since ?? $this->resolveSinceDate());
            $page = 1;
            $perPage = 100;
            $created = 0;
            $updated = 0;
            $failed = 0;
            $errors = [];

            $statusMap = $this->buildStatusMap();

            do {
                $result = $this->client->getOrders($perPage, $sinceDate);

                if (!$result['ok']) {
                    throw new \RuntimeException('API error: ' . json_encode($result['body']));
                }

                $orders = $result['body']['data'] ?? [];
                $lastPage = $result['body']['meta']['last_page'] ?? 1;

                foreach ($orders as $raw) {
                    try {
                        // Fetch full order detail (list only returns summary)
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

                    usleep(200000); // 200ms rate limit between detail fetches
                }

                if ($onProgress) {
                    $onProgress($created + $updated + $failed);
                }

                $page++;
                $hasMore = $page <= $lastPage;
                if ($maxPages > 0 && ($page - 1) >= $maxPages) {
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
            Log::error('Venta order sync failed', [
                'store' => $this->setting->store_name,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    /**
     * Resolve the "since" date for order sync.
     * sync_last_days (rolling window) takes priority over sync_orders_from (fixed date).
     */
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

    /**
     * Build status map: venta_status_id → core order_status_id.
     * Fallback chain: mapping table → name match → raw status ID.
     */
    private function buildStatusMap(): array
    {
        $pfx = (string) config('catalog.prefix');
        $langId = (int) config('catalog.default_language_id');

        $idMap = [];
        $storeMap = VentaOrderStatusMap::where('venta_setting_id', $this->setting->id)->get();
        foreach ($storeMap as $row) {
            $idMap[(int) $row->venta_status_id] = (int) $row->order_status_id;
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

    private function resolveOrderStatus(array $statusMap, int $ventaStatusId, string $statusName): int
    {
        if (isset($statusMap['by_id'][$ventaStatusId])) {
            return $statusMap['by_id'][$ventaStatusId];
        }

        if ($statusName !== '') {
            $key = strtolower(trim($statusName));
            if (isset($statusMap['by_name'][$key])) {
                return $statusMap['by_name'][$key];
            }
        }

        return $ventaStatusId;
    }

    /**
     * Upsert a single order from the Venta API response.
     *
     * @return bool True if created, false if updated.
     */
    private function upsertOrder(array $raw, array $statusMap): bool
    {
        $pfx = (string) config('catalog.prefix');
        $ventaOrderId = (int) ($raw['id'] ?? 0);
        if ($ventaOrderId <= 0) {
            throw new \RuntimeException('Missing order id');
        }

        $ventaStatusId = (int) ($raw['status_id'] ?? 0);
        $statusName = trim($raw['status'] ?? '');
        $coreStatusId = $this->resolveOrderStatus($statusMap, $ventaStatusId, $statusName);

        $marketplaceSource = 'venta:' . $this->setting->id;

        // Upsert into venta_orders (local mirror)
        $ventaOrder = VentaOrder::updateOrCreate(
            [
                'venta_setting_id' => $this->setting->id,
                'venta_order_id'   => $ventaOrderId,
            ],
            [
                'venta_order_number' => $raw['order_number'] ?? null,
                'status'             => $statusName,
                'status_id'          => $ventaStatusId,
                'customer_name'      => trim(($raw['contact']['name'] ?? '') ?: ($raw['customer']['name'] ?? '')),
                'customer_email'     => $raw['contact']['email'] ?? ($raw['customer']['email'] ?? ''),
                'total'              => (float) ($raw['totals']['total'] ?? 0),
                'payment_method'     => $raw['payment_method'] ?? '',
                'shipping_method'    => $raw['shipping_method'] ?? '',
                'tracking_number'    => $raw['tracking_number'] ?? '',
                'shipping_address'   => $raw['shipping_address'] ?? null,
                'raw'                => $raw,
                'order_created_at'   => $raw['created_at'] ?? null,
                'order_updated_at'   => $raw['updated_at'] ?? null,
            ]
        );

        $wasCreated = $ventaOrder->wasRecentlyCreated;

        // Sync order line items into venta_order_products
        VentaOrderProduct::where('venta_order_id', $ventaOrder->id)->delete();
        foreach (($raw['items'] ?? []) as $item) {
            VentaOrderProduct::create([
                'venta_order_id' => $ventaOrder->id,
                'sku'            => $item['sku'] ?? '',
                'name'           => $item['name'] ?? '',
                'variant_label'  => $item['variant_label'] ?? '',
                'quantity'       => (int) ($item['quantity'] ?? 1),
                'price'          => (float) ($item['price'] ?? 0),
                'total'          => (float) ($item['total'] ?? 0),
                'raw'            => $item,
            ]);
        }

        // Now sync to core catalog order
        $this->syncToCatalog($ventaOrder, $raw, $coreStatusId, $marketplaceSource, $statusMap);

        return $wasCreated;
    }

    /**
     * Create or update the core catalog order from a VentaOrder.
     */
    private function syncToCatalog(VentaOrder $ventaOrder, array $raw, int $coreStatusId, string $marketplaceSource, array $statusMap): void
    {
        $pfx = (string) config('catalog.prefix');
        $ventaOrderId = (string) $ventaOrder->venta_order_id;

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
            $raw, $pfx, $ventaOrderId, $coreStatusId, $marketplaceSource,
            $firstname, $lastname, $email, $phone,
            $shipping, $billing, $totals, $ventaOrder,
            &$oldStatusId, &$coreOrderId, &$created
        ) {
            $existing = DB::table($pfx . 'order')
                ->where('marketplace_source', $marketplaceSource)
                ->where('marketplace_order_id', $ventaOrderId)
                ->first();

            $orderData = [
                'invoice_no'             => 0,
                'invoice_prefix'         => '',
                'store_id'               => 0,
                'store_name'             => $this->setting->store_name,
                'store_url'              => $this->setting->base_url,
                'customer_id'            => 0,
                'customer_group_id'      => 0,
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
                'shipping_cost'          => (float) ($totals['shipping'] ?? 0),
                'shipping_code'          => '',
                'comment'                => $raw['comment'] ?? '',
                'total'                  => (float) ($totals['total'] ?? ($totals['subtotal'] ?? 0)),
                'extra_cost'             => 0,
                'order_status_id'        => $coreStatusId,
                'affiliate_id'           => 0,
                'commission'             => 0,
                'marketing_id'           => 0,
                'tracking'               => '',
                'language_id'            => 1,
                'currency_id'            => 0,
                'currency_code'          => 'PHP',
                'currency_value'         => 1,
                'ip'                     => '',
                'forwarded_ip'           => '',
                'user_agent'             => '',
                'accept_language'        => '',
                'courier_id'             => 0,
                'tracking_number'        => $raw['tracking_number'] ?? '',
                'marketplace_source'     => $marketplaceSource,
                'marketplace_order_id'   => $ventaOrderId,
                'oe_import'              => 0,
                'date_modified'          => isset($raw['updated_at']) ? \Carbon\Carbon::parse($raw['updated_at'])->format('Y-m-d H:i:s') : now()->toDateTimeString(),
            ];

            if ($existing) {
                $coreOrderId = (int) $existing->order_id;
                $oldStatusId = (int) $existing->order_status_id;
                $orderData['date_added'] = $existing->date_added;
                // Preserve manually-set shipping_cost if API returns 0
                if ((float) ($orderData['shipping_cost'] ?? 0) == 0 && (float) $existing->shipping_cost > 0) {
                    unset($orderData['shipping_cost']);
                }
                DB::table($pfx . 'order')
                    ->where('order_id', $coreOrderId)
                    ->update($orderData);
            } else {
                $orderData['date_added'] = isset($raw['created_at']) ? \Carbon\Carbon::parse($raw['created_at'])->format('Y-m-d H:i:s') : now()->toDateTimeString();
                $coreOrderId = DB::table($pfx . 'order')->insertGetId($orderData, 'order_id');
                $created = true;
            }

            // Sync order products
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

                $resolvedProductId = 0;
                $resolvedOptionValueId = 0;

                if ($sku !== '') {
                    // Try option value SKU match first
                    $pov = DB::table($pfx . 'product_option_value')
                        ->where('sku', $sku)
                        ->first();
                    if ($pov) {
                        $resolvedProductId = (int) $pov->product_id;
                        $resolvedOptionValueId = (int) $pov->product_option_value_id;
                    }

                    // Try combination SKU match (multi-option products)
                    if ($resolvedProductId === 0) {
                        $combo = DB::table('product_option_combinations as poc')
                            ->where('poc.sku', $sku)
                            ->first(['poc.product_id', 'poc.id as combination_id']);
                        if ($combo) {
                            $resolvedProductId = (int) $combo->product_id;
                            // Find a product_option_value_id linked to this combination
                            $comboValue = DB::table('product_option_combination_values')
                                ->where('combination_id', $combo->combination_id)
                                ->first(['product_option_value_id']);
                            if ($comboValue) {
                                $resolvedOptionValueId = (int) $comboValue->product_option_value_id;
                            }
                        }
                    }

                    // Fallback: main product SKU
                    if ($resolvedProductId === 0) {
                        $prod = DB::table($pfx . 'product')
                            ->where('sku', $sku)
                            ->first(['product_id']);
                        if ($prod) {
                            $resolvedProductId = (int) $prod->product_id;
                        }
                    }

                    // Fallback: venta_product_links
                    if ($resolvedProductId === 0) {
                        $link = DB::table('venta_product_links')
                            ->where('venta_setting_id', $this->setting->id)
                            ->where('sku', $sku)
                            ->first();
                        if ($link) {
                            $resolvedProductId = (int) $link->product_id;
                        }
                    }
                }

                // Calculate cost
                $productCost = 0;
                if ($resolvedProductId > 0) {
                    $baseCost = (float) DB::table($pfx . 'product')
                        ->where('product_id', $resolvedProductId)
                        ->value('cost');

                    if ($resolvedOptionValueId > 0) {
                        $optRow = DB::table($pfx . 'product_option_value')
                            ->where('product_option_value_id', $resolvedOptionValueId)
                            ->first(['cost', 'cost_prefix', 'absolute_cost']);
                        if ($optRow) {
                            if ((float) ($optRow->absolute_cost ?? 0) > 0) {
                                $productCost = (float) $optRow->absolute_cost;
                            } else {
                                $delta = (float) $optRow->cost;
                                $productCost = ($optRow->cost_prefix === '-')
                                    ? $baseCost - $delta
                                    : $baseCost + $delta;
                            }
                        } else {
                            $productCost = $baseCost;
                        }
                    } else {
                        $productCost = $baseCost;
                    }
                }

                $opId = DB::table($pfx . 'order_product')->insertGetId([
                    'order_id'   => $coreOrderId,
                    'product_id' => $resolvedProductId,
                    'name'       => $item['name'] ?? '',
                    'model'      => $sku,
                    'quantity'   => $qty,
                    'price'      => $price,
                    'total'      => $lineTotal,
                    'tax'        => 0,
                    'reward'     => 0,
                    'cost'       => $productCost,
                ]);

                // Add variant label as order option if present
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

            // Sync order totals
            DB::table($pfx . 'order_total')->where('order_id', $coreOrderId)->delete();
            $sortOrder = 1;

            DB::table($pfx . 'order_total')->insert([
                'order_id'   => $coreOrderId,
                'code'       => 'sub_total',
                'title'      => 'Sub-Total',
                'value'      => $subTotal,
                'sort_order' => $sortOrder++,
            ]);

            $shippingCost = (float) ($totals['shipping'] ?? 0);
            if ($shippingCost != 0) {
                DB::table($pfx . 'order_total')->insert([
                    'order_id'   => $coreOrderId,
                    'code'       => 'shipping',
                    'title'      => 'Shipping',
                    'value'      => $shippingCost,
                    'sort_order' => $sortOrder++,
                ]);
            }

            DB::table($pfx . 'order_total')->insert([
                'order_id'   => $coreOrderId,
                'code'       => 'total',
                'title'      => 'Total',
                'value'      => (float) ($totals['total'] ?? $subTotal),
                'sort_order' => $sortOrder,
            ]);

            // Add history if status changed or new
            if ($created || $oldStatusId !== $coreStatusId) {
                DB::table($pfx . 'order_history')->insert([
                    'order_id'        => $coreOrderId,
                    'order_status_id' => $coreStatusId,
                    'notify'          => 0,
                    'comment'         => 'Synced from Venta (status: ' . ($raw['status'] ?? 'unknown') . ')',
                    'date_added'      => now()->toDateTimeString(),
                    'user_id'         => null,
                    'user_name'       => 'Venta Sync',
                ]);
            }

            // Save catalog_order_id back to venta_orders
            $ventaOrder->update(['catalog_order_id' => $coreOrderId]);
        });

        // Post-transaction: activity log, FCM, stock
        if ($coreOrderId && $oldStatusId !== $coreStatusId) {
            ActivityLogger::log('updated', 'Order', $coreOrderId, 'Venta #' . $ventaOrderId, [
                'order_status_id' => [(string) $oldStatusId, (string) $coreStatusId],
            ], source: 'system');

            // Direct FCM dispatch — kept here because this sync uses DB::table()->insertGetId()
            // and DB::table()->update(), which bypass Eloquent observers (App\Observers\OrderObserver).
            // If this sync is ever converted to use Order::create()/$order->save(), remove this block.
            try {
                $firstItem = ($raw['items'] ?? [])[0] ?? null;
                $itemName = $firstItem ? trim($firstItem['name'] ?? '') : trim($firstname . ' ' . $lastname);
                $totalStr = number_format((float) ($totals['total'] ?? 0), 2) . ' PHP';
                (new FcmService())->notifyIfNeeded($coreOrderId, $oldStatusId, $coreStatusId, $itemName, $totalStr, $marketplaceSource);
            } catch (\Throwable $e) {
                Log::warning('FCM notification failed: ' . $e->getMessage());
            }

            if (!$this->skipStockAdjust) {
                try {
                    $order = Order::where('order_id', $coreOrderId)->first();
                    if ($order) {
                        OrderStockService::adjustStock($order, $oldStatusId, $coreStatusId);
                    }
                } catch (\Throwable $e) {
                    Log::warning('Venta order sync stock adjustment failed', [
                        'order_id' => $coreOrderId,
                        'error'    => $e->getMessage(),
                    ]);
                }
            }
        }
    }
}
