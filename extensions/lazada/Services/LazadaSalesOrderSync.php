<?php

namespace Extensions\Lazada\Services;

use Extensions\Lazada\Models\LazadaOrder;
use Extensions\Lazada\Models\LazadaOrderStatusMap;
use Extensions\Lazada\Models\LazadaListing;
use Extensions\Lazada\Models\LazadaListingVariation;
use App\Models\Catalog\Order;
use App\Models\Catalog\OrderHistory;
use App\Models\Catalog\OrderVariation;
use App\Models\Catalog\OrderProduct;
use App\Models\Catalog\OrderTotal;
use App\Services\OrderStockService;
use Illuminate\Support\Facades\DB;

use App\Services\ActivityLogger;

class LazadaSalesOrderSync
{
    private bool $skipStockAdjust = false;

    public function setSkipStockAdjust(bool $skip = true): self
    {
        $this->skipStockAdjust = $skip;
        return $this;
    }

    public function sync(LazadaOrder $lazadaOrder): void
    {
        DB::transaction(function () use ($lazadaOrder) {
            $raw = is_array($lazadaOrder->raw) ? $lazadaOrder->raw : [];
            $detail = (isset($raw['_detail']) && is_array($raw['_detail'])) ? $raw['_detail'] : [];

            $src = array_merge($raw, $detail);

            $orderStatusMap = LazadaOrderStatusMap::where('context', 'order')->pluck('order_status_id', 'lazada_status')->all();
            $returnStatusMap = LazadaOrderStatusMap::where('context', 'return')->pluck('order_status_id', 'lazada_status')->all();
            $lazadaStatus = strtolower($lazadaOrder->status ?? '');
            $statusId = $orderStatusMap[$lazadaStatus] ?? $returnStatusMap[$lazadaStatus] ?? null;

            $firstname = trim((string) ($src['customer_first_name'] ?? ''));
            $lastname = trim((string) ($src['customer_last_name'] ?? ''));
            if ($firstname === '') {
                $fullName = trim((string) ($src['customer_name'] ?? ($src['buyer_name'] ?? '')));
                $parts = preg_split('/\s+/', $fullName, 2);
                $firstname = $parts[0] ?? '';
                $lastname = $parts[1] ?? '';
            }

            $addr = $src['address_shipping'] ?? $src['shipping_address'] ?? $src['address'] ?? [];
            if (!is_array($addr)) $addr = [];

            $shippingFirstname = trim((string) ($addr['first_name'] ?? $firstname));
            $shippingLastname = trim((string) ($addr['last_name'] ?? $lastname));

            [$firstname, $lastname, $shippingFirstname, $shippingLastname] = array_map(
                fn (string $name) => mb_substr($name, 0, 32),
                [$firstname, $lastname, $shippingFirstname, $shippingLastname]
            );
            $shippingAddress1 = trim((string) ($addr['address1'] ?? ($addr['address'] ?? '')));
            $shippingAddress2 = trim((string) ($addr['address2'] ?? ''));
            $shippingCity = trim((string) ($addr['city'] ?? ''));
            $shippingPostcode = mb_substr(trim((string) ($addr['post_code'] ?? ($addr['zip_code'] ?? ''))), 0, 10);
            $shippingCountry = trim((string) ($addr['country'] ?? ''));
            $shippingZone = trim((string) ($addr['state'] ?? ($addr['region'] ?? '')));
            $telephone = trim((string) ($addr['phone'] ?? ($addr['phone1'] ?? ($src['receiver_phone'] ?? ''))));

            $email = trim((string) ($src['customer_email'] ?? ''));
            $shippingMethod = trim((string) ($src['shipping_provider'] ?? ($src['shipping_provider_type'] ?? '')));
            $paymentMethod = trim((string) ($src['payment_method'] ?? ''));
            $trackingNumber = trim((string) ($src['tracking_code'] ?? ($src['tracking_number'] ?? '')));
            $total = \App\Support\Money::parse($src['price'] ?? ($src['total'] ?? 0));
            $currencyCode = trim((string) ($src['currency'] ?? 'PHP'));
            if ($currencyCode === '') $currencyCode = 'PHP';

            $currencySvc = app(\App\Services\OrderCurrencyService::class);
            try {
                $currency = $currencySvc->resolve($currencyCode);
            } catch (\InvalidArgumentException $e) {
                \Log::warning("Unknown marketplace currency '{$currencyCode}', defaulting.", ['order' => $lazadaOrder->order_id]);
                $currency = $currencySvc->resolve($currencySvc->defaultCode());
            }

            $lazadaOrderId = (string) $lazadaOrder->order_id;

            $createdAt = $lazadaOrder->order_created_at ?? now();
            $updatedAt = $lazadaOrder->order_updated_at ?? now();

            $salesOrder = null;

            if ($lazadaOrder->sales_order_id) {
                $salesOrder = Order::where('order_id', $lazadaOrder->sales_order_id)->first();
            }

            if (!$salesOrder) {
                $salesOrder = Order::where('channel', 'lazada')
                    ->where('channel_order_id', $lazadaOrderId)
                    ->first();
            }

            if ($statusId === null) {
                if (! $salesOrder) {
                    return;
                }
                $statusId = (int) $salesOrder->order_status_id;
            }

            // Never overwrite a frozen as-transacted currency_value on re-sync, or it drifts from the stored totals.
            $rate = $currency['rate'];
            $isDefault = $currency['is_default'];
            if ($salesOrder && !$isDefault && $salesOrder->foreign_total !== null) {
                $rate = (float) $salesOrder->currency_value;
            }

            $storeId = (int) $lazadaOrder->store_id;
            $storeName = mb_substr((string) (\Extensions\Lazada\Models\LazadaStore::query()->find($storeId)?->store_name ?? ''), 0, 64);

            $orderData = [
                'store_id'              => $storeId,
                'store_name'            => $storeName,
                'firstname'             => $firstname,
                'lastname'              => $lastname,
                'email'                 => $email,
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
                'total'                 => \App\Services\OrderCurrencyService::toBase($total, $rate),
                'foreign_total'         => $isDefault ? null : round($total, \App\Services\OrderCurrencyService::MONEY_SCALE),
                'extra_cost'            => 0,
                'order_status_id'       => $statusId,
                'currency_id'           => $currency['id'],
                'currency_code'         => $currency['code'],
                'currency_value'        => $rate,
                'ip'                    => '',
                'courier_id'            => 0,
                'tracking_number'       => $trackingNumber,
                'channel'    => 'lazada',
                'channel_order_id'  => $lazadaOrderId,
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
            $voucherTotal = 0;

            $grouped = [];
            foreach ($lazadaOrder->products as $lp) {
                $lpRaw = is_array($lp->raw) ? $lp->raw : [];
                $key = trim((string) ($lp->sku ?? ''));
                if ($key === '') $key = '_nosku_' . $lp->id;

                $itemPrice = \App\Support\Money::parse($lpRaw['item_price'] ?? ($lpRaw['paid_price'] ?? ($lpRaw['price'] ?? 0)));
                $itemQty = max(1, (int) $lp->quantity);

                $shippingTotal += \App\Support\Money::parse($lpRaw['shipping_amount'] ?? ($lpRaw['shipping_fee_original'] ?? 0));
                $voucherTotal += \App\Support\Money::parse($lpRaw['voucher_amount'] ?? ($lpRaw['voucher_seller'] ?? 0));

                if (!isset($grouped[$key])) {
                    $grouped[$key] = [
                        'sku'       => trim((string) ($lp->sku ?? '')),
                        'name'      => $lp->name ?? '',
                        'variation' => $lp->variation ?? '',
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
                    $variant = LazadaListingVariation::where('seller_sku', $sellerSku)->first();
                    if ($variant) {
                        $lzProduct = LazadaListing::where('id', $variant->lazada_listing_id)->first();
                        if ($lzProduct && $lzProduct->product_id > 0) {
                            $resolvedProductId = (int) $lzProduct->product_id;
                        }
                        if ($variant->product_variation_value_id > 0) {
                            $resolvedVariationValueId = (int) $variant->product_variation_value_id;
                        }
                    }

                    if ($resolvedProductId > 0 && $resolvedVariationValueId === 0) {
                        $pov = \App\Models\Catalog\ProductVariationValue::where('product_id', $resolvedProductId)
                            ->where('sku', $sellerSku)
                            ->first();
                        if ($pov) {
                            $resolvedVariationValueId = (int) $pov->product_variation_value_id;
                        }
                    }
                }

                if ($resolvedProductId > 0 && $resolvedVariationValueId === 0) {
                    $variation = trim($item['variation'] ?? '');
                    if ($variation !== '') {
                        $resolvedVariationValueId = $this->resolveOptionByVariation($resolvedProductId, $variation);
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

            OrderTotal::where('order_id', $salesOrder->order_id)->delete();

            $sortOrder = 1;
            foreach (\Extensions\Lazada\Services\LazadaSaleLines::lines($raw, $subTotal, $shippingTotal) as $line) {
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
                    'note'            => 'Synced from Lazada (status: ' . ($lazadaOrder->status ?? 'unknown') . ')',
                    'date_added'      => now(),
                    'user_id'         => null,
                    'user_name'       => 'Lazada Sync',
                ]);

                ActivityLogger::log('updated', 'Order', $salesOrder->order_id, 'Lazada #' . ($lazadaOrder->order_id ?? ''), [
                    'order_status_id' => [(string) $oldStatusId, (string) $statusId],
                ], source: 'system');

                if (!$this->skipStockAdjust) {
                    OrderStockService::adjustStock($salesOrder, $oldStatusId, $statusId);
                }
            }

            $lazadaOrder->sales_order_id = $salesOrder->order_id;
            $lazadaOrder->saveQuietly();

            app(\App\Services\Orders\ChannelFeeNormalizer::class)->forOrder((int) $salesOrder->order_id);

        });
    }

    private function resolveOptionByVariation(int $productId, string $variation): int
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
