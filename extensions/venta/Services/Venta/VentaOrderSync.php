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
    /** How many pages the store said it holds on the last pull; the page-driven fetch reads it. */
    public ?int $lastTotalPages = null;

    public function pull(
        ?string $since = null,
        ?callable $onProgress = null,
        bool $full = false,
        int $maxPages = 0,
        int $startPage = 1
    ): VentaSyncLog {
        $log = VentaSyncLog::create([
            'venta_setting_id' => $this->setting->id,
            'entity_type'      => 'order',
            'direction'        => 'pull',
            'status'           => 'started',
            'started_at'       => now(),
        ]);

        try {
            // Where the store places each status, refreshed beside the
            // orders so a placement is never older than the status it is
            // applied to (#167). A store that does not answer keeps the
            // placements it last reported.
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
                $lastPage = $result['body']['meta']['last_page'] ?? 1;
                $this->lastTotalPages = (int) $lastPage;

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
    /**
     * Re-read ONE order from the storefront and write it through the same
     * upsert a pull uses. This is what moves a row the moment the ERP books
     * or cancels a courier: the storefront changes the order's status as a
     * side effect of the booking, and the ERP's row would otherwise sit in
     * To Pack until the next scheduled pull. No sync log row, because a
     * single re-read is part of the action that caused it, not a sync.
     */
    public function pullOne(int $ventaOrderId): ?VentaOrder
    {
        $this->refreshPlacements();

        $res = $this->client->getOrder($ventaOrderId);
        $raw = $res['body'] ?? [];

        if (! ($res['ok'] ?? false) || ! is_array($raw) || (int) ($raw['id'] ?? 0) !== $ventaOrderId) {
            return null;
        }

        $this->upsertOrder($raw, $this->buildStatusMap());

        return VentaOrder::where('venta_setting_id', $this->setting->id)
            ->where('venta_order_id', $ventaOrderId)
            ->first();
    }

    private function refreshPlacements(): void
    {
        try {
            VentaStatusPlacements::refresh($this->setting, $this->client);
        } catch (\Throwable $e) {
            Log::warning('Venta status placements could not be refreshed', [
                'store' => $this->setting->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * The courier booking as the storefront reports it (#169), or nothing.
     *
     * Only when the payload carries the object: a storefront that does not
     * yet send it must not blank what the ERP recorded from its own booking
     * response. When it is there, it is the truth, blanks included, because
     * a cancelled booking clears the columns on the storefront and the ERP
     * has to follow.
     *
     * @return array<string, mixed>
     */
    public static function courierFieldsFrom(array $raw): array
    {
        $courier = $raw['courier'] ?? null;
        if (! is_array($courier)) {
            return [];
        }

        // A shipment recorded by hand on the storefront has no provider
        // key; it has a courier name. It is filed under 'manual' so the row
        // knows it has no waybill or timeline, and the name is what reads.
        $manual = (bool) ($courier['manual'] ?? false);

        return [
            'courier_provider'        => $manual ? VentaOrder::MANUAL_PROVIDER : ($courier['provider'] ?? null),
            'courier_name'            => $manual ? ($courier['manual_courier'] ?? null) : null,
            'courier_tracking_number' => $courier['tracking_number'] ?? null,
            'courier_status'          => $manual ? 'manual' : ($courier['status'] ?? null),
            'courier_booked_at'       => $courier['booked_at'] ?? null,
        ];
    }

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
                // The group the order was placed under, by name, as Venta
                // sends it (#129). Null on orders Venta never captured it for.
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

            // Venta is the in-house storefront - no currency code is transacted
            // in the payload, so it always settles in the ERP's default currency.
            $currencySvc = app(\App\Services\OrderCurrencyService::class);
            $currency = $currencySvc->resolve($currencySvc->defaultCode());
            // Always the default currency today (rate=1, is_default=true), but
            // kept structurally identical to the other four marketplace syncs
            // so a future non-default settlement doesn't silently regress into
            // the same "raw amount in a PHP column" corruption. The re-sync
            // guard is the same as the other syncs: never clobber an
            // already-normalized order's frozen rate with today's resolution,
            // and never apply a frozen rate once the order is no longer
            // foreign.
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
                // The group's NAME, on the core order row, which is where the
                // margin report reads it for every storefront (#129). The id
                // above stays 0: there is no customer table here to point at
                // (#132), and the name is what the report needs.
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
                'shipping_cost'          => (float) ($totals['shipping'] ?? 0),
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
                'marketplace_order_id'   => $ventaOrderId,
                'oe_import'              => 0,
                'date_modified'          => isset($raw['updated_at']) ? \Carbon\Carbon::parse($raw['updated_at'])->format('Y-m-d H:i:s') : now()->toDateTimeString(),
            ];

            if ($existing) {
                $coreOrderId = (int) $existing->order_id;
                $oldStatusId = (int) $existing->order_status_id;
                // Sync Override: operator manually finalised this order. Keep its
                // current status in the DB write, and equalise $oldStatusId so the
                // post-transaction status-change block (history/FCM/stock) no-ops.
                if ((int) ($existing->sync_override ?? 0) === 1) {
                    $orderData['order_status_id'] = $oldStatusId;
                    $oldStatusId = $coreStatusId;
                }
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
                // Foreign (as-transacted) figures - always PHP for Venta today.
                $price = (float) ($item['price'] ?? 0);
                $lineTotal = (float) ($item['total'] ?? ($price * $qty));
                $subTotal += $lineTotal;

                $priceBase = \App\Services\OrderCurrencyService::toBase($price, $rate);
                $lineTotalBase = \App\Services\OrderCurrencyService::toBase($lineTotal, $rate);

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

                // Cost at the time of sale, from the catalogue: the variation's own
                // cost when it has an amount, else the parent's. One rule for every
                // channel, in App\Support\LineCost.
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
                    // Cost is looked up from the catalog (already PHP), not
                    // from marketplace data - no conversion needed.
                    'cost'          => $productCost,
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

            // Sync order totals. order_total has no foreign_* column - it
            // always holds the default-currency figure, matching order.total.
            /* Only the totals THIS sync owns. A blanket delete took the
               normalized marketplace fee rows with it
               (App\Services\Orders\MarketplaceFeeNormalizer), so a re-sync
               silently emptied the Fees column on every report until the
               next backfill. A writer clears its own codes and nothing
               else's. */
            DB::table($pfx . 'order_total')->where('order_id', $coreOrderId)
                ->whereIn('code', ['sub_total', 'shipping', 'total', 'handling', 'tax', 'voucher', 'discount', 'credit'])->delete();
            $sortOrder = 1;

            DB::table($pfx . 'order_total')->insert([
                'order_id'   => $coreOrderId,
                'code'       => 'sub_total',
                'title'      => 'Sub-Total',
                'value'      => \App\Services\OrderCurrencyService::toBase($subTotal, $rate),
                'sort_order' => $sortOrder++,
            ]);

            $shippingCost = (float) ($totals['shipping'] ?? 0);
            if ($shippingCost != 0) {
                DB::table($pfx . 'order_total')->insert([
                    'order_id'   => $coreOrderId,
                    'code'       => 'shipping',
                    'title'      => 'Shipping',
                    'value'      => \App\Services\OrderCurrencyService::toBase($shippingCost, $rate),
                    'sort_order' => $sortOrder++,
                ]);
            }

            DB::table($pfx . 'order_total')->insert([
                'order_id'   => $coreOrderId,
                'code'       => 'total',
                'title'      => 'Total',
                'value'      => \App\Services\OrderCurrencyService::toBase((float) ($totals['total'] ?? $subTotal), $rate),
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

            /* The storefront's own fees land on the CORE order here, in the
               four codes every report reads (MarketplaceFeeNormalizer).
               After the totals above, which clear only their own codes. */
            app(\App\Services\Orders\MarketplaceFeeNormalizer::class)->forOrder($coreOrderId);
        });

        // Post-transaction: activity log, FCM, stock
        if ($coreOrderId && $oldStatusId !== $coreStatusId) {
            ActivityLogger::log('updated', 'Order', $coreOrderId, 'Venta #' . $ventaOrderId, [
                'order_status_id' => [(string) $oldStatusId, (string) $coreStatusId],
            ], source: 'system');

            // Direct FCM dispatch - kept here because this sync uses DB::table()->insertGetId()
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

    /**
     * The customer group name from Venta's order payload: a plain string
     * on the order, or null when Venta never captured one (the migrated
     * orders). Trimmed and capped to the column.
     */
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
