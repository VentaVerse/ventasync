<?php

namespace Extensions\VentaCart\Services\VentaCart;

use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderVariation;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Services\OrderStockService;
use App\Events\OrderStatusChanged;
use App\Services\ActivityLogger;
use Extensions\VentaCart\Models\VentaCartOrder;
use Extensions\VentaCart\Models\VentaCartOrderProduct;
use Extensions\VentaCart\Models\VentaCartOrderStatusMap;
use Extensions\VentaCart\Models\VentaCartStore;
use Extensions\VentaCart\Models\VentaCartSyncLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class VentaCartOrderSync
{
    private VentaCartClient $client;
    private VentaCartStore $store;
    private bool $skipStockAdjust = false;

    private bool $feesOnly = false;

    public function __construct(VentaCartClient $client, VentaCartStore $store)
    {
        $this->client = $client;
        $this->store = $store;
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
            'store_id' => $this->store->id,
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

            $this->store->update(['last_order_sync_at' => now()]);

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
                'store' => $this->store->store_name,
                'error' => $e->getMessage(),
            ]);
        }

        return $log;
    }

    private function resolveSinceDate(): ?string
    {
        if ($this->store->sync_last_days && $this->store->sync_last_days > 0) {
            return now()->subDays($this->store->sync_last_days)->startOfDay()->toIso8601String();
        }

        if ($this->store->sync_orders_from) {
            return $this->store->sync_orders_from->startOfDay()->toIso8601String();
        }

        return null;
    }

    private function buildStatusMap(): array
    {
        $langId = (int) config('catalog.default_language_id');

        $idMap = [];
        $storeMap = VentaCartOrderStatusMap::where('store_id', $this->store->id)->get();
        foreach ($storeMap as $row) {
            $idMap[(int) $row->ventacart_status_id] = (int) $row->order_status_id;
        }

        $nameMap = [];
        $rows = DB::table('order_statuses')
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

        return VentaCartOrder::where('store_id', $this->store->id)
            ->where('ventacart_order_id', $ventaCartOrderId)
            ->first();
    }

    private function refreshPlacements(): void
    {
        try {
            VentaCartStatusPlacements::refresh($this->store, $this->client);
        } catch (\Throwable $e) {
            Log::warning('VentaCart status placements could not be refreshed', [
                'store' => $this->store->id,
                'error' => $e->getMessage(),
            ]);
        }
    }

    // Only follow the payload when it carries the courier object; a storefront that omits it must not blank VentaSync's booking.
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
        $ventaCartOrderId = (int) ($raw['id'] ?? 0);
        if ($ventaCartOrderId <= 0) {
            throw new \RuntimeException('Missing order id');
        }

        $ventaCartStatusId = (int) ($raw['status_id'] ?? 0);
        $statusName = trim($raw['status'] ?? '');
        $coreStatusId = $this->resolveOrderStatus($statusMap, $ventaCartStatusId, $statusName);

        $orderChannel = 'ventacart:' . $this->store->id;

        $ventaCartOrder = VentaCartOrder::updateOrCreate(
            [
                'store_id' => $this->store->id,
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
                'variation_label' => $item['variant_label'] ?? '',
                'quantity'       => (int) ($item['quantity'] ?? 1),
                'price'          => (float) ($item['price'] ?? 0),
                'total'          => (float) ($item['total'] ?? 0),
                'raw'            => $item,
            ]);
        }

        if ($this->feesOnly) {
            if ($ventaCartOrder->sales_order_id) {
                app(\App\Services\Orders\ChannelFeeNormalizer::class)->forOrder((int) $ventaCartOrder->sales_order_id);
            }

            return $wasCreated;
        }

        $this->syncToCatalog($ventaCartOrder, $raw, $coreStatusId, $orderChannel, $statusMap);

        return $wasCreated;
    }

    private function syncToCatalog(VentaCartOrder $ventaCartOrder, array $raw, int $coreStatusId, string $orderChannel, array $statusMap): void
    {
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
            $raw, $ventaCartOrderId, $coreStatusId, $orderChannel,
            $firstname, $lastname, $email, $phone,
            $shipping, $billing, $totals, $ventaCartOrder,
            &$oldStatusId, &$coreOrderId, &$created
        ) {
            $existing = DB::table('orders')
                ->where('channel', $orderChannel)
                ->where('channel_order_id', $ventaCartOrderId)
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
                'store_id'               => 0,
                'store_name'             => $this->store->store_name,
                'customer_group'         => self::customerGroupFrom($raw),
                'firstname'              => $firstname,
                'lastname'               => $lastname,
                'email'                  => $email,
                'telephone'              => $phone,
                'payment_firstname'      => trim($billing['first_name'] ?? $firstname),
                'payment_lastname'       => trim($billing['last_name'] ?? $lastname),
                'payment_company'        => '',
                'payment_address_1'      => trim(implode(', ', array_filter([$billing['address_1'] ?? '', $billing['barangay'] ?? '']))),
                'payment_address_2'      => trim($billing['district'] ?? ''),
                'payment_city'           => trim($billing['city'] ?? ''),
                'payment_postcode'       => trim($billing['postcode'] ?? ''),
                'payment_country'        => trim($billing['country_code'] ?? ''),
                'payment_zone'           => trim($billing['state'] ?? ''),
                'payment_method'         => $raw['payment_method'] ?? '',
                'payment_cost'           => 0,
                'shipping_firstname'     => $firstname,
                'shipping_lastname'      => $lastname,
                'shipping_company'       => '',
                'shipping_address_1'     => trim(implode(', ', array_filter([$shipping['address_1'] ?? '', $shipping['barangay'] ?? '']))),
                'shipping_address_2'     => trim($shipping['district'] ?? ''),
                'shipping_city'          => trim($shipping['city'] ?? ''),
                'shipping_postcode'      => trim($shipping['postcode'] ?? ''),
                'shipping_country'       => trim($shipping['country_code'] ?? ''),
                'shipping_zone'          => trim($shipping['state'] ?? ''),
                'shipping_method'        => $raw['shipping_method'] ?? '',
                'shipping_cost'          => 0,
                'customer_note'          => $raw['comment'] ?? '',
                'total'                  => \App\Services\OrderCurrencyService::toBase((float) ($totals['total'] ?? ($totals['subtotal'] ?? 0)), $rate),
                'foreign_total'          => $isDefault ? null : round((float) ($totals['total'] ?? ($totals['subtotal'] ?? 0)), $moneyScale),
                'extra_cost'             => 0,
                'order_status_id'        => $coreStatusId,
                'currency_id'            => $currency['id'],
                'currency_code'          => $currency['code'],
                'currency_value'         => $rate,
                'ip'                     => '',
                'courier_id'             => 0,
                'tracking_number'        => $raw['tracking_number'] ?? '',
                'channel'     => $orderChannel,
                'channel_order_id'   => $ventaCartOrderId,
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
                DB::table('orders')
                    ->where('order_id', $coreOrderId)
                    ->update(\App\Support\OrderSnapshot::keepOnOrder($existing, $orderData));
            } else {
                $orderData['date_added'] = isset($raw['created_at']) ? \Carbon\Carbon::parse($raw['created_at'])->format('Y-m-d H:i:s') : now()->toDateTimeString();
                \App\Plans\Quota::refuseOrder();
                $coreOrderId = DB::table('orders')->insertGetId($orderData, 'order_id');
                $created = true;
            }

            $items = $raw['items'] ?? [];
            $snapshot = \App\Support\OrderSnapshot::take((int) $coreOrderId);
            DB::table('order_products')->where('order_id', $coreOrderId)->delete();
            DB::table('order_variations')->where('order_id', $coreOrderId)->delete();

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
                $resolvedVariationValueId = 0;

                if ($sku !== '') {
                    $pov = DB::table('product_variation_values')
                        ->where('sku', $sku)
                        ->first();
                    if ($pov) {
                        $resolvedProductId = (int) $pov->product_id;
                        $resolvedVariationValueId = (int) $pov->product_variation_value_id;
                    }

                    if ($resolvedProductId === 0) {
                        $combo = DB::table('product_variations as poc')
                            ->where('poc.sku', $sku)
                            ->first(['poc.product_id', 'poc.id as product_variation_id']);
                        if ($combo) {
                            $resolvedProductId = (int) $combo->product_id;
                            $comboValue = DB::table('product_variation_parts')
                                ->where('product_variation_id', $combo->product_variation_id)
                                ->first(['product_variation_value_id']);
                            if ($comboValue) {
                                $resolvedVariationValueId = (int) $comboValue->product_variation_value_id;
                            }
                        }
                    }

                    if ($resolvedProductId === 0) {
                        $prod = DB::table('products')
                            ->where('sku', $sku)
                            ->first(['product_id']);
                        if ($prod) {
                            $resolvedProductId = (int) $prod->product_id;
                        }
                    }

                    if ($resolvedProductId === 0) {
                        $link = DB::table('ch_ventacart_listing_links')
                            ->where('store_id', $this->store->id)
                            ->where('sku', $sku)
                            ->first();
                        if ($link) {
                            $resolvedProductId = (int) $link->product_id;
                        }
                    }
                }

                $productCost = $snapshot->cost($sku, $item['name'] ?? '', (int) $resolvedProductId, (int) $resolvedVariationValueId);

                $opId = DB::table('order_products')->insertGetId([
                    'order_id'      => $coreOrderId,
                    'product_id'    => $resolvedProductId,
                    'name'          => $item['name'] ?? '',
                    'sku'           => $sku,
                    'quantity'      => $qty,
                    'price'         => $priceBase,
                    'foreign_price' => $isDefault ? null : round($price, $moneyScale),
                    'total'         => $lineTotalBase,
                    'foreign_total' => $isDefault ? null : round($lineTotal, $moneyScale),
                    'cost'          => $productCost,
                ]);

                foreach ($snapshot->variations($sku, $item['name'] ?? '', (string) ($item['variant_label'] ?? ''), (int) $resolvedVariationValueId) as $v) {
                    DB::table('order_variations')->insert($v + ['order_id' => $coreOrderId, 'order_product_id' => $opId]);
                }
            }

            DB::table('order_totals')->where('order_id', $coreOrderId)
                ->whereNotIn('code', array_column(\App\Services\Orders\ChannelFeeNormalizer::CODES, 0))->delete();
            $sortOrder = 1;
            foreach (VentaCartSaleLines::lines(is_array($totals) ? $totals : [], $subTotal, (float) ($totals['shipping'] ?? 0)) as $line) {
                DB::table('order_totals')->insert([
                    'order_id'   => $coreOrderId,
                    'code'       => $line['code'],
                    'title'      => $line['title'],
                    'value'      => \App\Services\OrderCurrencyService::toBase($line['value'], $rate),
                    'sort_order' => $sortOrder++,
                ]);
            }

            if ($created || $oldStatusId !== $coreStatusId) {
                DB::table('order_history')->insert([
                    'order_id'        => $coreOrderId,
                    'order_status_id' => $coreStatusId,
                    'notify'          => 0,
                    'note'            => 'Synced from VentaCart (status: ' . ($raw['status'] ?? 'unknown') . ')',
                    'date_added'      => now()->toDateTimeString(),
                    'user_id'         => null,
                    'user_name'       => 'VentaCart Sync',
                ]);
            }

            $ventaCartOrder->update(['sales_order_id' => $coreOrderId]);

            app(\App\Services\Orders\ChannelFeeNormalizer::class)->forOrder($coreOrderId);
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
