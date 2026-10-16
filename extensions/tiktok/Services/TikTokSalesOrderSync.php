<?php

namespace Extensions\TikTok\Services;

use Extensions\TikTok\Models\TikTokOrder;
use Extensions\TikTok\Models\TikTokOrderStatusMap;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderVariation;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Services\OrderStockService;
use App\Services\ActivityLogger;
use Illuminate\Support\Facades\DB;

class TikTokSalesOrderSync
{
    private bool $skipStockAdjust = false;

    public function setSkipStockAdjust(bool $skip = true): self
    {
        $this->skipStockAdjust = $skip;
        return $this;
    }

    public function sync(TikTokOrder $tiktokOrder): void
    {
        DB::transaction(function () use ($tiktokOrder) {
            $raw = is_array($tiktokOrder->raw) ? $tiktokOrder->raw : [];

            $statusMap = TikTokOrderStatusMap::where('context', 'order')->pluck('order_status_id', 'tiktok_status')->all();
            $statusId = $statusMap[$tiktokOrder->status ?? ''] ?? null;

            $addr = $raw['recipient_address'] ?? [];
            if (!is_array($addr)) $addr = [];

            $customerName = trim((string) ($addr['name'] ?? ($tiktokOrder->customer_name ?? '')));
            $parts = preg_split('/\s+/', $customerName, 2);
            $firstname = $parts[0] ?? '';
            $lastname = $parts[1] ?? '';

            $shippingFirstname = mb_substr(trim((string) ($addr['name'] ?? $firstname)), 0, 128);
            $shippingLastname = '';
            $shippingAddress1 = mb_substr(trim((string) ($addr['full_address'] ?? ($addr['address_line1'] ?? ''))), 0, 256);
            $shippingAddress2 = mb_substr(trim((string) ($addr['address_line2'] ?? ($addr['address_detail'] ?? ''))), 0, 256);
            $shippingCity = mb_substr(trim((string) ($addr['city'] ?? ($addr['district'] ?? ''))), 0, 128);
            $shippingPostcode = mb_substr(trim((string) ($addr['zipcode'] ?? ($addr['postal_code'] ?? ''))), 0, 10);
            $shippingCountry = mb_substr(trim((string) ($addr['country'] ?? ($addr['region_code'] ?? ''))), 0, 128);
            $shippingZone = mb_substr(trim((string) ($addr['state'] ?? ($addr['region'] ?? ''))), 0, 128);
            $telephone = mb_substr(trim((string) ($addr['phone_number'] ?? ($addr['phone'] ?? ''))), 0, 32);

            $paymentInfo = $raw['payment_info'] ?? $raw['payment'] ?? [];
            if (!is_array($paymentInfo)) $paymentInfo = [];

            $paymentMethod = trim((string) ($paymentInfo['payment_method'] ?? ($paymentInfo['payment_method_name'] ?? '')));
            $apiTotal = (float) ($paymentInfo['total_amount'] ?? ($paymentInfo['original_total_product_price'] ?? 0));
            $currencyCode = trim((string) ($paymentInfo['currency'] ?? 'PHP'));
            if ($currencyCode === '') $currencyCode = 'PHP';

            $currencySvc = app(\App\Services\OrderCurrencyService::class);
            try {
                $currency = $currencySvc->resolve($currencyCode);
            } catch (\InvalidArgumentException $e) {
                \Log::warning("Unknown marketplace currency '{$currencyCode}', defaulting.", ['order' => $tiktokOrder->order_id]);
                $currency = $currencySvc->resolve($currencySvc->defaultCode());
            }

            $shippingInfo = $raw['shipping_info'] ?? $raw['logistics'] ?? [];
            if (is_array($shippingInfo) && isset($shippingInfo[0]) && is_array($shippingInfo[0])) {
                $shippingInfo = $shippingInfo[0];
            }
            if (!is_array($shippingInfo)) $shippingInfo = [];

            $shippingMethod = trim((string) ($shippingInfo['shipping_provider_name'] ?? ($shippingInfo['shipping_provider'] ?? '')));
            $trackingNumber = trim((string) ($shippingInfo['tracking_number'] ?? ''));

            $tiktokOrderId = (string) $tiktokOrder->order_id;

            $createdAt = $tiktokOrder->order_created_at ?? now();
            $updatedAt = $tiktokOrder->order_updated_at ?? now();

            $salesOrder = null;

            if ($tiktokOrder->sales_order_id) {
                $salesOrder = Order::where('order_id', $tiktokOrder->sales_order_id)->first();
            }

            if (!$salesOrder) {
                $salesOrder = Order::where('channel', 'tiktok')
                    ->where('channel_order_id', $tiktokOrderId)
                    ->first();
            }

            if ($statusId === null) {
                if (! $salesOrder) {
                    return;
                }
                $statusId = (int) $salesOrder->order_status_id;
            }

            // Once an order is normalized its currency_value is a frozen rate; a re-sync must not overwrite it
            // with today's rate, nor apply it if the order is no longer foreign.
            $rate = $currency['rate'];
            $isDefault = $currency['is_default'];
            if ($salesOrder && !$isDefault && $salesOrder->foreign_total !== null) {
                $rate = (float) $salesOrder->currency_value;
            }

            $storeId = (int) $tiktokOrder->store_id;
            $storeName = mb_substr((string) (\Extensions\TikTok\Models\TikTokStore::query()->find($storeId)?->store_name ?? ''), 0, 64);

            $orderData = [
                'store_id'              => $storeId,
                'store_name'            => $storeName,
                'firstname'             => $firstname,
                'lastname'              => $lastname,
                'email'                 => '',
                'telephone'             => $telephone,
                'payment_firstname'     => $firstname,
                'payment_lastname'      => $lastname,
                'payment_company'       => '',
                'payment_address_1'     => '',
                'payment_address_2'     => '',
                'payment_city'          => '',
                'payment_postcode'      => '',
                'payment_country'       => '',
                'payment_zone'          => '',
                'payment_method'        => $paymentMethod,
                'payment_cost'          => 0,
                'shipping_firstname'    => $shippingFirstname,
                'shipping_lastname'     => $shippingLastname,
                'shipping_company'      => '',
                'shipping_address_1'    => $shippingAddress1,
                'shipping_address_2'    => $shippingAddress2,
                'shipping_city'         => $shippingCity,
                'shipping_postcode'     => $shippingPostcode,
                'shipping_country'      => $shippingCountry,
                'shipping_zone'         => $shippingZone,
                'shipping_method'       => $shippingMethod,
                'shipping_cost'         => 0,
                'customer_note'         => '',
                'total'                 => $apiTotal,
                'extra_cost'            => 0,
                'order_status_id'       => $statusId,
                'currency_id'           => $currency['id'],
                'currency_code'         => $currency['code'],
                'currency_value'        => $rate,
                'ip'                    => '',
                'courier_id'            => 0,
                'tracking_number'       => $trackingNumber,
                'channel'    => 'tiktok',
                'channel_order_id'  => $tiktokOrderId,
            ];

            if ($salesOrder) {
                $oldStatusId = (int) $salesOrder->order_status_id;
                if ((int) ($salesOrder->sync_override ?? 0) === 1) {
                    $statusId = $oldStatusId;
                    $orderData['order_status_id'] = $oldStatusId;
                }
                $orderData['date_modified'] = $updatedAt;
                $salesOrder->update(\App\Support\OrderSnapshot::keepOnOrder($salesOrder, $orderData));
            } else {
                $oldStatusId = 0;
                $orderData['date_added'] = $createdAt;
                $orderData['date_modified'] = $updatedAt;
                $salesOrder = Order::create($orderData);
            }

            $snapshot = \App\Support\OrderSnapshot::take((int) $salesOrder->order_id);
            OrderProduct::where('order_id', $salesOrder->order_id)->delete();
            OrderVariation::where('order_id', $salesOrder->order_id)->delete();

            $subTotal = 0;
            $shippingTotal = 0;

            $grouped = [];
            foreach ($tiktokOrder->products as $tp) {
                $tpRaw = is_array($tp->raw) ? $tp->raw : [];
                $key = trim((string) ($tp->sku ?? ''));
                if ($key === '') $key = '_nosku_' . $tp->id;

                $itemPrice = (float) ($tp->sale_price ?? ($tp->item_price ?? ($tpRaw['sale_price'] ?? ($tpRaw['original_price'] ?? 0))));
                $itemQty = max(1, (int) $tp->quantity);

                $shippingTotal += (float) ($tpRaw['shipping_fee'] ?? 0);

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'sku'       => trim((string) ($tp->sku ?? '')),
                        'name'      => $tp->name ?? '',
                        'variation' => $tp->variation ?? '',
                        'price'     => $itemPrice,
                        'quantity'  => $itemQty,
                    ];
                } else {
                    $grouped[$key]['quantity'] += $itemQty;
                }
            }

            foreach ($grouped as $item) {
                $price = $item['price'];
                $qty = $item['quantity'];
                $lineTotal = $price * $qty;
                $subTotal += $lineTotal;

                $priceBase = \App\Services\OrderCurrencyService::toBase($price, $rate);
                $lineTotalBase = \App\Services\OrderCurrencyService::toBase($lineTotal, $rate);

                $resolvedProductId = 0;
                $resolvedVariationValueId = 0;
                $sellerSku = $item['sku'];
                if ($sellerSku !== '') {
                    $pov = DB::table('product_variation_values')
                        ->where('sku', $sellerSku)
                        ->first();
                    if ($pov) {
                        $resolvedProductId = (int) $pov->product_id;
                        $resolvedVariationValueId = (int) $pov->product_variation_value_id;
                    }

                    if ($resolvedProductId === 0) {
                        $prod = DB::table('products')
                            ->where('sku', $sellerSku)
                            ->first(['product_id']);
                        if ($prod) {
                            $resolvedProductId = (int) $prod->product_id;
                        }
                    }
                }

                if ($resolvedProductId > 0 && $resolvedVariationValueId === 0) {
                    $variation = trim($item['variation'] ?? '');
                    if ($variation !== '') {
                        $resolvedVariationValueId = $this->resolveOptionByVariation( $resolvedProductId, $variation);
                    }
                }

                $productCost = $snapshot->cost($sellerSku, $item['name'], (int) $resolvedProductId, (int) $resolvedVariationValueId);

                $op = OrderProduct::create([
                    'order_id'      => $salesOrder->order_id,
                    'product_id'    => $resolvedProductId,
                    'name'          => $item['name'],
                    'sku'           => $sellerSku,
                    'quantity'      => $qty,
                    'price'         => $priceBase,
                    'foreign_price' => $isDefault ? null : round($price, \App\Services\OrderCurrencyService::MONEY_SCALE),
                    'total'         => $lineTotalBase,
                    'foreign_total' => $isDefault ? null : round($lineTotal, \App\Services\OrderCurrencyService::MONEY_SCALE),
                    'base_price'    => $priceBase,
                    'cost'          => $productCost,
                    'supplier_id'   => 0,
                ]);

                foreach ($snapshot->variations($sellerSku, $item['name'], $item['variation'] ?? '', (int) $resolvedVariationValueId) as $v) {
                    OrderVariation::create($v + ['order_id' => $salesOrder->order_id, 'order_product_id' => $op->order_product_id]);
                }
            }

            $total = $subTotal;
            $salesOrder->update([
                'total'         => \App\Services\OrderCurrencyService::toBase($total, $rate),
                'foreign_total' => $isDefault ? null : round($total, \App\Services\OrderCurrencyService::MONEY_SCALE),
            ]);

            OrderTotal::where('order_id', $salesOrder->order_id)->delete();

            $sortOrder = 1;
            foreach (\Extensions\TikTok\Services\TikTokSaleLines::lines(is_array($raw['payment'] ?? null) ? $raw['payment'] : [], $subTotal, $shippingTotal) as $line) {
                OrderTotal::create([
                    'order_id'   => $salesOrder->order_id,
                    'code'       => $line['code'],
                    'title'      => $line['title'],
                    'value'      => \App\Services\OrderCurrencyService::toBase($line['value'], $rate),
                    'sort_order' => $sortOrder++,
                ]);
            }

            if ($oldStatusId !== $statusId) {
                OrderHistory::create([
                    'order_id'        => $salesOrder->order_id,
                    'order_status_id' => $statusId,
                    'notify'          => 0,
                    'note'            => 'Synced from TikTok (status: ' . ($tiktokOrder->status ?? 'unknown') . ')',
                    'date_added'      => now(),
                    'user_id'         => null,
                    'user_name'       => 'TikTok Sync',
                ]);

                ActivityLogger::log('updated', 'Order', $salesOrder->order_id, 'TikTok #' . ($tiktokOrder->order_id ?? ''), [
                    'order_status_id' => [(string) $oldStatusId, (string) $statusId],
                ], source: 'system');

                if (!$this->skipStockAdjust) {
                    OrderStockService::adjustStock($salesOrder, $oldStatusId, $statusId);
                }
            }

            $tiktokOrder->sales_order_id = $salesOrder->order_id;
            $tiktokOrder->saveQuietly();

            app(\App\Services\Orders\ChannelFeeNormalizer::class)->forOrder((int) $salesOrder->order_id);

        });
    }

    private function resolveOptionByVariation( int $productId, string $variation): int
    {
        $langId = (int) config('catalog.default_language_id');

        $parts = array_map('trim', explode(',', $variation));
        $candidates = [];
        foreach ($parts as $part) {
            if (str_contains($part, ':')) {
                $after = trim(substr($part, strrpos($part, ':') + 1));
                if ($after !== '') {
                    $candidates[] = $after;
                }
            }
            $candidates[] = $part;
        }

        $candidates = array_unique(array_filter($candidates));
        if (empty($candidates)) {
            return 0;
        }

        $match = DB::table('product_variation_values as pov')
            ->join('variation_value_descriptions as ovd', function ($j) use ($langId) {
                $j->on('pov.variation_value_id', '=', 'ovd.variation_value_id')
                    ->where('ovd.language_id', '=', $langId);
            })
            ->where('pov.product_id', $productId)
            ->whereIn('ovd.name', $candidates)
            ->first(['pov.product_variation_value_id']);

        return $match ? (int) $match->product_variation_value_id : 0;
    }
}
