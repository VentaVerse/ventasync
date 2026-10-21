<?php

namespace Extensions\Shopee\Services;

use Extensions\Shopee\Models\ShopeeOrder;
use Extensions\Shopee\Models\ShopeeOrderStatusMap;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderVariation;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Services\OrderStockService;
use Illuminate\Support\Facades\DB;

use App\Services\ActivityLogger;

class ShopeeSalesOrderSync
{
    private bool $skipStockAdjust = false;

    public function setSkipStockAdjust(bool $skip = true): self
    {
        $this->skipStockAdjust = $skip;
        return $this;
    }

    public function sync(ShopeeOrder $shopeeOrder): void
    {
        DB::transaction(function () use ($shopeeOrder) {
            $raw = is_array($shopeeOrder->raw) ? $shopeeOrder->raw : [];

            $orderStatusMap = ShopeeOrderStatusMap::where('context', 'order')->pluck('order_status_id', 'shopee_status')->all();
            $returnStatusMap = ShopeeOrderStatusMap::where('context', 'return')->pluck('order_status_id', 'shopee_status')->all();
            $shopeeStatus = $shopeeOrder->status ?? '';
            $statusId = $orderStatusMap[$shopeeStatus] ?? $returnStatusMap[$shopeeStatus] ?? null;

            $customerUsername = trim((string) ($raw['buyer_username'] ?? ($raw['buyer_user_name'] ?? '')));
            $firstname = $customerUsername;
            $lastname = '';

            $addr = $raw['recipient_address'] ?? $raw['shipping_address'] ?? [];
            if (!is_array($addr)) $addr = [];

            $shippingFirstname = mb_substr(trim((string) ($addr['name'] ?? $firstname)), 0, 128);
            $shippingLastname = '';
            $shippingAddress1 = mb_substr(trim((string) ($addr['full_address'] ?? ($addr['address1'] ?? ''))), 0, 256);
            $shippingAddress2 = '';
            $shippingCity = mb_substr(trim((string) ($addr['city'] ?? ($addr['town'] ?? ''))), 0, 128);
            $shippingPostcode = mb_substr(trim((string) ($addr['zipcode'] ?? ($addr['zip_code'] ?? ''))), 0, 10);
            $shippingCountry = mb_substr(trim((string) ($addr['country'] ?? '')), 0, 128);
            $shippingZone = mb_substr(trim((string) ($addr['state'] ?? ($addr['region'] ?? ''))), 0, 128);
            $telephone = mb_substr(trim((string) ($addr['phone'] ?? '')), 0, 32);

            $shippingMethod = trim((string) ($raw['shipping_carrier'] ?? ($raw['checkout_shipping_carrier'] ?? '')));
            $paymentMethod = trim((string) ($raw['payment_method'] ?? ''));
            $trackingNumber = trim((string) ($raw['tracking_no'] ?? ($raw['tracking_number'] ?? '')));
            $apiTotal = (float) ($raw['total_amount'] ?? ($raw['escrow_amount'] ?? 0));
            $currencyCode = trim((string) ($raw['currency'] ?? 'PHP'));
            if ($currencyCode === '') $currencyCode = 'PHP';

            $currencySvc = app(\App\Services\OrderCurrencyService::class);
            try {
                $currency = $currencySvc->resolve($currencyCode);
            } catch (\InvalidArgumentException $e) {
                \Log::warning("Unknown marketplace currency '{$currencyCode}', defaulting.", ['order' => $shopeeOrder->order_sn]);
                $currency = $currencySvc->resolve($currencySvc->defaultCode());
            }

            $orderSn = (string) $shopeeOrder->order_sn;

            $createdAt = $shopeeOrder->order_created_at ?? now();
            $updatedAt = $shopeeOrder->order_updated_at ?? now();

            $salesOrder = null;

            if ($shopeeOrder->sales_order_id) {
                $salesOrder = Order::where('order_id', $shopeeOrder->sales_order_id)->first();
            }

            if (!$salesOrder) {
                $salesOrder = Order::where('channel', 'shopee')
                    ->where('channel_order_id', $orderSn)
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

            $storeId = (int) $shopeeOrder->store_id;
            $storeName = mb_substr((string) (\Extensions\Shopee\Models\ShopeeStore::query()->find($storeId)?->store_name ?? ''), 0, 64);

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
                'customer_note'         => trim((string) ($raw['message_to_seller'] ?? '')),
                'total'                 => $apiTotal,
                'extra_cost'            => 0,
                'order_status_id'       => $statusId,
                'currency_id'           => $currency['id'],
                'currency_code'         => $currency['code'],
                'currency_value'        => $rate,
                'ip'                    => '',
                'courier_id'            => 0,
                'tracking_number'       => $trackingNumber,
                'channel'    => 'shopee',
                'channel_order_id'  => $orderSn,
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
            foreach ($shopeeOrder->products as $sp) {
                $spRaw = is_array($sp->raw) ? $sp->raw : [];
                $key = trim((string) ($sp->sku ?? ''));
                if ($key === '') $key = '_nosku_' . $sp->id;

                $itemPrice = (float) ($sp->price ?? ($spRaw['model_discounted_price'] ?? ($spRaw['model_original_price'] ?? 0)));
                $itemQty = max(1, (int) $sp->quantity);

                $shippingTotal += (float) ($spRaw['shipping_fee'] ?? 0);

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'sku'       => trim((string) ($sp->sku ?? '')),
                        'name'      => $sp->name ?? '',
                        'variation' => $sp->variation ?? '',
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

            $feeData = is_array($shopeeOrder->fees) ? $shopeeOrder->fees : [];
            $orderIncome = $feeData['order_income'] ?? $feeData;

            $customerPaidShipping = abs((float) ($orderIncome['buyer_paid_shipping_fee'] ?? 0));
            $voucherSeller = abs((float) ($orderIncome['voucher_from_seller'] ?? 0));

            if ($customerPaidShipping > 0 && $shippingTotal == 0) {
                $shippingTotal = $customerPaidShipping;
            }

            $total = $subTotal - $voucherSeller;
            $salesOrder->update([
                'total'         => \App\Services\OrderCurrencyService::toBase($total, $rate),
                'foreign_total' => $isDefault ? null : round($total, \App\Services\OrderCurrencyService::MONEY_SCALE),
            ]);

            OrderTotal::where('order_id', $salesOrder->order_id)->delete();

            $sortOrder = 1;
            foreach (\Extensions\Shopee\Services\ShopeeSaleLines::lines(is_array($orderIncome) ? $orderIncome : [], $subTotal, $shippingTotal) as $line) {
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
                    'note'            => 'Synced from Shopee (status: ' . ($shopeeOrder->status ?? 'unknown') . ')',
                    'date_added'      => now(),
                    'user_id'         => null,
                    'user_name'       => 'Shopee Sync',
                ]);

                ActivityLogger::log('updated', 'Order', $salesOrder->order_id, 'Shopee #' . ($shopeeOrder->order_sn ?? ''), [
                    'order_status_id' => [(string) $oldStatusId, (string) $statusId],
                ], source: 'system');

                if (!$this->skipStockAdjust) {
                    OrderStockService::adjustStock($salesOrder, $oldStatusId, $statusId);
                }
            }

            $shopeeOrder->sales_order_id = $salesOrder->order_id;
            $shopeeOrder->saveQuietly();

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
